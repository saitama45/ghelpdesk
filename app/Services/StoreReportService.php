<?php

namespace App\Services;

use App\Models\DepartmentNode;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;

class StoreReportService
{
    private const DEFAULT_THRESHOLDS = [
        'threshold_green_min' => 0,
        'threshold_green_max' => 2,
        'threshold_green_label' => 'Healthy',
        'threshold_yellow_min' => 3,
        'threshold_yellow_max' => 3,
        'threshold_yellow_label' => 'Warning',
        'threshold_orange_min' => 4,
        'threshold_orange_max' => 4,
        'threshold_orange_label' => 'At-risk',
        'threshold_red_min' => 5,
        'threshold_red_label' => 'Critical',
    ];

    public function getStoreHealthData(array $filters)
    {
        $userId = $filters['user_id'] ?? 'all';
        $storeId = $filters['store_id'] ?? 'all';
        $subUnit = $filters['sub_unit'] ?? 'all';
        $departmentId = $filters['department_id'] ?? null;
        $departmentNodeId = $filters['department_node_id'] ?? null;
        $asOfDate = $filters['as_of_date'] ?? Carbon::now()->format('Y-m-d');
        // Optional explicit entity scope (Entity/Company filter). When provided we
        // bypass the active-entity global scope and use exactly these companies.
        $companyIds = $filters['company_ids'] ?? null;
        $isCtMode = $this->isCorporateTechnologyFilter($departmentNodeId, $subUnit);
        // When set, corporate-office stores (class = "Office") are carved out of the
        // sector view (reportData / summary / entity heatmap) and returned as their
        // own "office" block. Opt-in so the full report page / PDF stay unchanged.
        $splitOffice = (bool) ($filters['split_office'] ?? false);

        // Resolve the configured bands once so every dashboard/report section uses
        // the same hierarchy scope, labels, ranges, and classification rules.
        $allThresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');
        $thresholds = $this->getThresholdsForScope($subUnit, $allThresholds, $departmentId, $departmentNodeId);
        $thresholdBands = $this->thresholdBands($thresholds);

        // Query active tickets. Sector summaries intentionally stay based on
        // the ticket's configured store sector, not the assignee hierarchy.
        $baseTicketsQuery = Ticket::query();

        if (is_array($companyIds)) {
            // Store Health is scoped by the STORE'S owning entity, not the ticket's
            // stamped company. A ticket stamped to entity A that sits on a store owned
            // by entity B is entity B's store health — so we filter on store.company_id.
            // This keeps the sector cards tallied with the Store-Health-by-Entity heatmap
            // (which counts stores by ownership) and the corporate-office block.
            $baseTicketsQuery->withoutGlobalScope(ActiveEntityScope::class)
                ->whereHas('store', fn ($q) => $q->whereIn('company_id', $companyIds));
        }

        // Parent tickets only — child/sub-tickets are not counted separately, matching
        // the rest of the dashboard (Ticket Flow Board, Open vs Closed, Overview) so
        // every widget tallies to the same ticket universe.
        $baseTicketsQuery->whereNull('tickets.parent_id')
            ->whereNotIn('tickets.status', ['resolved', 'closed']);

        if ($asOfDate) {
            $baseTicketsQuery->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        if ($storeId && $storeId !== 'all') {
            $baseTicketsQuery->where('tickets.store_id', $storeId);
        }

        $ticketsQuery = clone $baseTicketsQuery;

        if ($departmentNodeId) {
            $nodeIds = array_merge([(int) $departmentNodeId], DepartmentNode::getAllDescendantIds((int) $departmentNodeId));
            $ticketsQuery->whereHas('assignee', function($q) use ($nodeIds) {
                $q->whereIn('department_node_id', $nodeIds);
            });
        } elseif ($departmentId) {
            $ticketsQuery->whereHas('assignee', function($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        } elseif ($subUnit && $subUnit !== 'all') {
            $ticketsQuery->whereHas('assignee', function($q) use ($subUnit) {
                $q->where('org_path', 'like', '%'.$subUnit.'%');
            });
        }

        $displayTicketsQuery = clone $ticketsQuery;
        $sectorAssignments = $this->sectorAssignments();
        $activeTickets = $displayTicketsQuery->with(['assignee', 'store'])
            ->get()
            ->filter(fn ($ticket) => !$isCtMode || (int) $ticket->store?->sector === 0)
            ->filter(fn ($ticket) => $this->ticketHasReportableStoreSector($ticket))
            ->filter(fn ($ticket) => !$splitOffice || $ticket->store?->class !== 'Office')
            ->filter(fn ($ticket) => $this->ticketMatchesDisplayUser($ticket, $userId, $sectorAssignments));

        $reportData = $activeTickets->groupBy(function ($ticket) use ($sectorAssignments, $isCtMode) {
            if ($isCtMode) {
                return $ticket->store_id ? "store-{$ticket->store_id}" : 'unassigned-store';
            }

            $sector = (int) $ticket->store->sector;

            if (!empty($sectorAssignments['users_by_sector'][$sector])) {
                return "sector-{$sector}";
            }

            return $ticket->assignee_id ? "assignee-{$ticket->assignee_id}" : 'unassigned';
        })->map(function ($tickets, $groupKey) use ($sectorAssignments, $isCtMode, $thresholdBands) {
            $firstTicket = $tickets->first();
            $sector = (int) $firstTicket->store->sector;
            $assignee = $firstTicket->assignee;
            $firstStore = $firstTicket->store;

            $stores = $tickets->groupBy('store_id')->map(function ($storeTickets, $storeId) use ($thresholdBands) {
                $store = $storeTickets->first()->store;
                if (!$store) return null;
                if (!$store->is_active) return null;

                return [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => $storeTickets->count(),
                    'health_bucket' => $this->healthBucket($storeTickets->count(), $thresholdBands),
                ];
            })->filter()
                ->sortBy([
                    ['sector', 'asc'],
                    ['area', 'asc'],
                    ['code', 'asc'],
                    ['name', 'asc'],
                ])
                ->values();

            $sectorUsers = $sectorAssignments['users_by_sector'][$sector] ?? [];

            return [
                'id' => $groupKey,
                'name' => $isCtMode
                    ? ($firstStore?->code ?? 'Unassigned Store')
                    : (!empty($sectorUsers) ? implode(', ', $sectorUsers) : ($assignee?->name ?? 'Unassigned')),
                'sub_unit' => $assignee?->org_path,
                'sector' => !empty($sectorUsers) ? $sector : $stores->min('sector'),
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return count($u['stores']) > 0;
        })->sortBy([
            ['sector', 'asc'],
            ['name', 'asc'],
        ])->values();

        // Entity health heatmap — every active store in scope bucketed by open-ticket
        // count (0 open = Healthy). Entity-wide, independent of assignee/dept/sector.
        // When splitting out offices, the sector heatmap excludes them.
        $entityHealth = $this->buildEntityHealthHeatmap(
            $companyIds,
            $storeId,
            $asOfDate,
            $thresholdBands,
            $splitOffice ? 'exclude_office' : null
        );

        // Corporate-office block (per-office cards + detail table + office-only heatmap).
        $office = $splitOffice
            ? $this->buildOfficeHealth($companyIds, $storeId, $asOfDate, $thresholdBands)
            : null;

        // Summary logic
        $summary = [
            'north' => [],
            'south' => [],
            'ct' => [],
            'is_ct_mode' => $isCtMode,
        ];

        $summaryTicketsQuery = clone $ticketsQuery;
        $summaryTickets = $summaryTicketsQuery
            ->with(['assignee', 'store'])
            ->get()
            ->filter(fn ($ticket) => !$isCtMode || (int) $ticket->store?->sector === 0)
            ->filter(fn ($ticket) => $this->ticketHasReportableStoreSector($ticket))
            ->filter(fn ($ticket) => !$splitOffice || $ticket->store?->class !== 'Office')
            ->filter(fn ($ticket) => $this->ticketMatchesDisplayUser($ticket, $userId, $sectorAssignments));

        if ($isCtMode) {
            $summary['ct'] = $this->buildCtSummary($summaryTickets, $thresholdBands);

            return array_filter([
                'reportData' => $reportData,
                'summary' => $summary,
                'thresholds' => $thresholds,
                'thresholdBands' => $thresholdBands,
                'entityHealth' => $entityHealth,
                'office' => $office,
            ], fn ($value) => $value !== null);
        }

        $ticketCountsBySectorStore = $summaryTickets
            ->groupBy(fn ($ticket) => (int) $ticket->store->sector)
            ->map(fn ($sectorTickets) => $sectorTickets->groupBy('store_id')->map->count());

        $northArea = DepartmentNode::where('name', 'North Area')->first();
        $southArea = DepartmentNode::where('name', 'South Area')->first();
        $northNodes = $northArea ? DepartmentNode::where('parent_id', $northArea->id)->with('users')->get() : collect();
        $southNodes = $southArea ? DepartmentNode::where('parent_id', $southArea->id)->with('users')->get() : collect();

        for ($i = 1; $i <= 8; $i++) {
            $sectorStoreTicketCounts = $ticketCountsBySectorStore->get($i, collect());
            $healthTicketCounts = [
                'green' => 0,
                'yellow' => 0,
                'orange' => 0,
                'red' => 0,
            ];
            $healthStoreCounts = $healthTicketCounts;
            $totalTickets = 0;

            foreach ($sectorStoreTicketCounts as $ticketCount) {
                $ticketCount = (int) $ticketCount;
                $totalTickets += $ticketCount;
                $bucket = $this->healthBucket($ticketCount, $thresholdBands);
                $healthTicketCounts[$bucket] += $ticketCount;
                $healthStoreCounts[$bucket]++;
            }

            $nodeName = "Sector $i";
            if ($i <= 4) {
                $node = $northNodes->where('name', $nodeName)->first();
            } else {
                $node = $southNodes->where('name', $nodeName)->first();
            }

            $assignedUsers = $sectorAssignments['users_by_sector'][$i] ?? ($node ? $node->users->pluck('name')->toArray() : []);
            $assignedUserDisplay = empty($assignedUsers) ? 'Unassigned' : implode(', ', $assignedUsers);

            $sectorData = [
                'sector' => $i,
                'user' => $assignedUserDisplay,
                'store_count' => $sectorStoreTicketCounts->count(),
                'total_tickets' => $totalTickets,
                // Keep health_counts as a compatibility alias for ticket totals.
                'health_counts' => $healthTicketCounts,
                'health_ticket_counts' => $healthTicketCounts,
                'health_store_counts' => $healthStoreCounts,
            ];

            if ($i <= 4) {
                $summary['north'][] = $sectorData;
            } else {
                $summary['south'][] = $sectorData;
            }
        }

        return array_filter([
            'reportData' => $reportData,
            'summary' => $summary,
            'thresholds' => $thresholds,
            'thresholdBands' => $thresholdBands,
            'entityHealth' => $entityHealth,
            'office' => $office,
        ], fn ($value) => $value !== null);
    }

    /**
     * Build the per-entity (Company) health heatmap. Counts EVERY active store in
     * scope, bucketing it by its open-ticket count so stores with no open tickets
     * fold into the "Healthy" (green) column. Rows are entities; the four columns
     * are the legend buckets green/yellow/orange/red.
     *
     * Scope is intentionally entity-wide: it respects the entity/company filter,
     * the store filter and the as-of date, but NOT the assignee dept/user/sector
     * (an entity's health spans every sector).
     */
    private function buildEntityHealthHeatmap($companyIds, $storeId, $asOfDate, array $thresholdBands, ?string $classScope = null): array
    {
        $storesQuery = Store::query()
            ->where('is_active', true)
            ->with('company:id,name,code');

        if ($classScope === 'only_office') {
            $storesQuery->where('class', 'Office');
        } elseif ($classScope === 'exclude_office') {
            $storesQuery->where('class', '!=', 'Office');
        }

        if (is_array($companyIds)) {
            $storesQuery->whereIn('company_id', $companyIds);
        }
        if ($storeId && $storeId !== 'all') {
            $storesQuery->where('id', $storeId);
        }

        $stores = $storesQuery->get(['id', 'company_id']);

        if ($stores->isEmpty()) {
            return [];
        }

        // Open-ticket counts per store. Scoped by the listed stores (which are already
        // restricted to the entity by ownership), so every open ticket sitting on the
        // entity's store is counted regardless of the ticket's own stamped company.
        $ticketQuery = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereNull('tickets.parent_id')
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->whereIn('tickets.store_id', $stores->pluck('id'));

        if ($asOfDate) {
            $ticketQuery->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        $openCountsByStore = $ticketQuery
            ->selectRaw('store_id, COUNT(*) as c')
            ->groupBy('store_id')
            ->pluck('c', 'store_id');

        return $stores
            ->groupBy('company_id')
            ->map(function ($companyStores) use ($openCountsByStore, $thresholdBands) {
                $company = $companyStores->first()->company;
                $counts = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0];
                $openTickets = 0;
                $affected = 0;

                foreach ($companyStores as $store) {
                    $count = (int) ($openCountsByStore->get($store->id) ?? 0);
                    $openTickets += $count;
                    if ($count > 0) {
                        $affected++;
                    }
                    $counts[$this->healthBucket($count, $thresholdBands)]++;
                }

                return [
                    'id' => $company?->id,
                    'name' => $company?->name ?? 'Unassigned Entity',
                    'code' => $company?->code,
                    'total_stores' => $companyStores->count(),
                    'affected_stores' => $affected,
                    'open_tickets' => $openTickets,
                    'counts' => $counts,
                ];
            })
            ->sortByDesc('total_stores')
            ->values()
            ->all();
    }

    /**
     * Build the Corporate Office block for the Live Store Health tab. Corporate
     * offices are stores classified as "Office"; they carry out-of-range sector
     * numbers and so never fit the North/South sector cards. Every active office
     * store is shown as its own health card (0 open tickets = Healthy), plus a
     * per-entity detail table and an office-only entity heatmap.
     */
    private function buildOfficeHealth($companyIds, $storeId, $asOfDate, array $thresholdBands): array
    {
        $storesQuery = Store::query()
            ->where('is_active', true)
            ->where('class', 'Office')
            ->with([
                'company:id,name,code',
                // Only active assigned team members appear on the card label.
                'users' => fn ($q) => $q->where('users.is_active', true)->select('users.id', 'users.name'),
            ]);

        if (is_array($companyIds)) {
            $storesQuery->whereIn('company_id', $companyIds);
        }
        if ($storeId && $storeId !== 'all') {
            $storesQuery->where('id', $storeId);
        }

        $stores = $storesQuery->get();

        if ($stores->isEmpty()) {
            return [
                'summary' => ['office' => [], 'is_office_mode' => true],
                'reportData' => [],
                'entityHealth' => [],
            ];
        }

        // Open-ticket counts per office store, same open/as-of scope as the tab.
        $ticketQuery = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereNull('tickets.parent_id')
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->whereIn('tickets.store_id', $stores->pluck('id'));

        if ($asOfDate) {
            $ticketQuery->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        $openCountsByStore = $ticketQuery
            ->selectRaw('store_id, COUNT(*) as c')
            ->groupBy('store_id')
            ->pluck('c', 'store_id');

        // One health card per office store — the store's own open count is bucketed,
        // so a store with zero open tickets folds into "Healthy".
        $cards = $stores->map(function ($store) use ($openCountsByStore, $thresholdBands) {
            $count = (int) ($openCountsByStore->get($store->id) ?? 0);
            $healthCounts = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0];
            $healthBucket = $this->healthBucket($count, $thresholdBands);
            $healthCounts[$healthBucket] = 1;
            $team = $store->users->pluck('name')->implode(', ');

            return [
                'store_id' => $store->id,
                'store_code' => $store->code,
                'store_name' => $store->name,
                'area' => $store->area,
                'team' => $team !== '' ? $team : 'Unassigned',
                'total_tickets' => $count,
                'health_bucket' => $healthBucket,
                'health_counts' => $healthCounts,
            ];
        })->sortBy('store_code')->values()->all();

        // Detail table, grouped by entity, reusing the sector reportData shape.
        $reportData = $stores
            ->groupBy('company_id')
            ->map(function ($companyStores) use ($openCountsByStore, $thresholdBands) {
                $company = $companyStores->first()->company;

                $storeRows = $companyStores->map(fn ($store) => [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => (int) ($openCountsByStore->get($store->id) ?? 0),
                    'health_bucket' => $this->healthBucket((int) ($openCountsByStore->get($store->id) ?? 0), $thresholdBands),
                ])->sortBy([['code', 'asc'], ['name', 'asc']])->values();

                return [
                    'id' => 'office-company-'.($company?->id ?? 'none'),
                    'name' => $company?->name ?? 'Unassigned Entity',
                    'sub_unit' => null,
                    'stores' => $storeRows,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'summary' => ['office' => $cards, 'is_office_mode' => true],
            'reportData' => $reportData,
            'entityHealth' => $this->buildEntityHealthHeatmap($companyIds, $storeId, $asOfDate, $thresholdBands, 'only_office'),
        ];
    }

    private function getThresholdsForScope($subUnit, $allThresholds, $departmentId = null, $departmentNodeId = null)
    {
        $colors = ['green', 'yellow', 'orange', 'red'];
        $suffixes = ['min', 'max', 'label'];
        $thresholds = [];

        $subUnitSlugs = [];
        if ($subUnit && $subUnit !== 'all') {
            $frontendSlug = strtolower((string)$subUnit);
            $frontendSlug = preg_replace('/\s+/', '_', $frontendSlug);
            $frontendSlug = preg_replace('/[^\w-]+/', '', $frontendSlug);
            $frontendSlug = preg_replace('/--+/', '_', $frontendSlug);
            $frontendSlug = trim($frontendSlug, '-');

            $subUnitSlugs = array_values(array_unique(array_filter([
                \Illuminate\Support\Str::slug($subUnit, '_'),
                $frontendSlug,
            ])));
        }

        foreach ($colors as $color) {
            foreach ($suffixes as $suffix) {
                if ($color === 'red' && $suffix === 'max') continue;
                
                $globalKey = "threshold_{$color}_{$suffix}";
                $nodeKey = $departmentNodeId ? "threshold_{$color}_{$suffix}_node_{$departmentNodeId}" : null;
                $departmentKey = $departmentId ? "threshold_{$color}_{$suffix}_department_{$departmentId}" : null;
                $subUnitKeys = array_map(
                    fn ($slug) => "threshold_{$color}_{$suffix}_{$slug}",
                    $subUnitSlugs
                );
                
                $val = null;
                if ($nodeKey && isset($allThresholds[$nodeKey])) {
                    $val = $allThresholds[$nodeKey];
                }

                if ($val === null && $departmentKey && isset($allThresholds[$departmentKey])) {
                    $val = $allThresholds[$departmentKey];
                }

                foreach ($subUnitKeys as $subUnitKey) {
                    if ($val !== null) {
                        break;
                    }

                    if (isset($allThresholds[$subUnitKey])) {
                        $val = $allThresholds[$subUnitKey];
                    }
                }
                
                if ($val === null && isset($allThresholds[$globalKey])) {
                    $val = $allThresholds[$globalKey];
                }

                $thresholds[$globalKey] = $val;
            }
        }

        foreach (self::DEFAULT_THRESHOLDS as $key => $default) {
            if ($thresholds[$key] === null || $thresholds[$key] === '') {
                $thresholds[$key] = $default;
            }
        }

        return $thresholds;
    }

    private function thresholdBands(array $thresholds): array
    {
        return [
            ['key' => 'green', 'label' => (string) $thresholds['threshold_green_label'], 'min' => (int) $thresholds['threshold_green_min'], 'max' => (int) $thresholds['threshold_green_max']],
            ['key' => 'yellow', 'label' => (string) $thresholds['threshold_yellow_label'], 'min' => (int) $thresholds['threshold_yellow_min'], 'max' => (int) $thresholds['threshold_yellow_max']],
            ['key' => 'orange', 'label' => (string) $thresholds['threshold_orange_label'], 'min' => (int) $thresholds['threshold_orange_min'], 'max' => (int) $thresholds['threshold_orange_max']],
            ['key' => 'red', 'label' => (string) $thresholds['threshold_red_label'], 'min' => (int) $thresholds['threshold_red_min'], 'max' => null],
        ];
    }

    private function healthBucket(int $ticketCount, array $thresholdBands): string
    {
        foreach ($thresholdBands as $band) {
            $withinMaximum = $band['max'] === null || $ticketCount <= $band['max'];
            if ($ticketCount >= $band['min'] && $withinMaximum) {
                return $band['key'];
            }
        }

        // Saved settings are validated as continuous. This defensive fallback only
        // protects reports created before that validation was introduced.
        return $ticketCount >= $thresholdBands[array_key_last($thresholdBands)]['min'] ? 'red' : 'green';
    }

    private function sectorAssignments(): array
    {
        $sectorNodes = DepartmentNode::query()
            ->where('name', 'like', 'Sector %')
            ->get();

        $byUserId = [];
        $usersBySector = [];

        foreach ($sectorNodes as $node) {
            if (!preg_match('/^Sector\s+(\d+)$/i', $node->name, $matches)) {
                continue;
            }

            $sector = (int) $matches[1];
            $nodeIds = array_merge([$node->id], DepartmentNode::getAllDescendantIds($node->id));
            $users = User::query()
                ->whereIn('department_node_id', $nodeIds)
                ->orderBy('name')
                ->get(['id', 'name']);

            $usersBySector[$sector] = array_values(array_unique(array_merge(
                $usersBySector[$sector] ?? [],
                $users->pluck('name')->all()
            )));

            foreach ($users as $user) {
                $byUserId[$user->id] ??= [];
                $byUserId[$user->id][] = $sector;
            }
        }

        foreach ($byUserId as $userId => $sectors) {
            $byUserId[$userId] = array_values(array_unique($sectors));
        }

        return [
            'by_user_id' => $byUserId,
            'users_by_sector' => $usersBySector,
        ];
    }

    /**
     * Sector health is computed purely from the ticket's configured store sector.
     * A ticket is reportable as long as it sits on an active store that has a
     * sector assigned — the ticket's assignee (and which sector that assignee
     * happens to belong to) is intentionally ignored here.
     */
    private function ticketHasReportableStoreSector(Ticket $ticket): bool
    {
        return $ticket->store
            && $ticket->store->is_active
            && $ticket->store->sector !== null;
    }

    private function ticketMatchesDisplayUser(Ticket $ticket, $userId, array $sectorAssignments): bool
    {
        if (!$userId || $userId === 'all') {
            return true;
        }

        if ($userId === 'unassigned') {
            return $ticket->assignee_id === null;
        }

        $ownedSectors = $sectorAssignments['by_user_id'][(int) $userId] ?? [];

        if (!empty($ownedSectors)) {
            return in_array((int) $ticket->store->sector, $ownedSectors, true);
        }

        return (int) $ticket->assignee_id === (int) $userId;
    }

    private function isCorporateTechnologyFilter($departmentNodeId, $subUnit): bool
    {
        if ($departmentNodeId) {
            $node = DepartmentNode::find((int) $departmentNodeId);

            while ($node) {
                if (strcasecmp((string) $node->code, 'CT') === 0 || strcasecmp((string) $node->name, 'Corporate Technology') === 0) {
                    return true;
                }

                $node = $node->parent_id ? DepartmentNode::find($node->parent_id) : null;
            }
        }

        $label = strtolower((string) $subUnit);

        return str_contains($label, 'corporate technology') || preg_match('/(^|[\s\/>-])ct($|[\s\/<-])/i', (string) $subUnit) === 1;
    }



    private function buildCtSummary($tickets, array $thresholdBands): array
    {
        return $tickets
            ->groupBy('store_id')
            ->map(function ($storeTickets) use ($thresholdBands) {
                $store = $storeTickets->first()->store;

                if (!$store || !$store->is_active) {
                    return null;
                }

                $ticketCount = $storeTickets->count();
                $healthTicketCounts = [
                    'green' => 0,
                    'yellow' => 0,
                    'orange' => 0,
                    'red' => 0,
                ];
                $healthStoreCounts = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0];
                $bucket = $this->healthBucket($ticketCount, $thresholdBands);
                $healthTicketCounts[$bucket] = $ticketCount;
                $healthStoreCounts[$bucket] = 1;

                return [
                    'store_id' => $store->id,
                    'store_code' => $store->code,
                    'store_name' => $store->name,
                    'area' => $store->area,
                    'store_count' => 1,
                    'total_tickets' => $ticketCount,
                    'health_counts' => $healthTicketCounts,
                    'health_ticket_counts' => $healthTicketCounts,
                    'health_store_counts' => $healthStoreCounts,
                ];
            })
            ->filter()
            ->sortBy('store_code')
            ->values()
            ->all();
    }
}
