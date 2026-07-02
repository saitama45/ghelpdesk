<?php

namespace App\Services;

use App\Models\DepartmentNode;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;

class StoreReportService
{
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

        // Query active tickets. Sector summaries intentionally stay based on
        // the ticket's configured store sector, not the assignee hierarchy.
        $baseTicketsQuery = Ticket::query();

        if (is_array($companyIds)) {
            $baseTicketsQuery->withoutGlobalScope(ActiveEntityScope::class)
                ->whereIn('tickets.company_id', $companyIds);
        }

        $baseTicketsQuery->whereNotIn('tickets.status', ['resolved', 'closed']);

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
        })->map(function ($tickets, $groupKey) use ($sectorAssignments, $isCtMode) {
            $firstTicket = $tickets->first();
            $sector = (int) $firstTicket->store->sector;
            $assignee = $firstTicket->assignee;
            $firstStore = $firstTicket->store;

            $stores = $tickets->groupBy('store_id')->map(function ($storeTickets, $storeId) {
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

        // Thresholds
        $allThresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');
        $thresholds = $this->getThresholdsForScope($subUnit, $allThresholds, $departmentId, $departmentNodeId);

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
            ->filter(fn ($ticket) => $this->ticketMatchesDisplayUser($ticket, $userId, $sectorAssignments));

        if ($isCtMode) {
            $summary['ct'] = $this->buildCtSummary($summaryTickets, $thresholds);

            return [
                'reportData' => $reportData,
                'summary' => $summary,
                'thresholds' => $thresholds,
            ];
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
            $healthCounts = [
                'green' => 0,
                'yellow' => 0,
                'orange' => 0,
                'red' => 0,
            ];
            $totalTickets = 0;

            foreach ($sectorStoreTicketCounts as $ticketCount) {
                $ticketCount = (int) $ticketCount;
                $totalTickets += $ticketCount;
                $healthCounts[$this->healthBucket($ticketCount, $thresholds)] += $ticketCount;
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
                'health_counts' => $healthCounts,
            ];

            if ($i <= 4) {
                $summary['north'][] = $sectorData;
            } else {
                $summary['south'][] = $sectorData;
            }
        }

        return [
            'reportData' => $reportData,
            'summary' => $summary,
            'thresholds' => $thresholds,
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

        return $thresholds;
    }

    private function healthBucket(int $ticketCount, array $thresholds): string
    {
        $redMin = (int) ($thresholds['threshold_red_min'] ?? 5);
        $orangeMin = (int) ($thresholds['threshold_orange_min'] ?? 4);
        $yellowMin = (int) ($thresholds['threshold_yellow_min'] ?? 3);
        $greenMax = (int) ($thresholds['threshold_green_max'] ?? 2);

        if ($ticketCount >= $redMin) {
            return 'red';
        }

        if ($ticketCount >= $orangeMin) {
            return 'orange';
        }

        if ($ticketCount >= $yellowMin) {
            return 'yellow';
        }

        if ($ticketCount <= $greenMax) {
            return 'green';
        }

        return 'green';
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



    private function buildCtSummary($tickets, array $thresholds): array
    {
        return $tickets
            ->groupBy('store_id')
            ->map(function ($storeTickets) use ($thresholds) {
                $store = $storeTickets->first()->store;

                if (!$store || !$store->is_active) {
                    return null;
                }

                $ticketCount = $storeTickets->count();
                $healthCounts = [
                    'green' => 0,
                    'yellow' => 0,
                    'orange' => 0,
                    'red' => 0,
                ];
                $healthCounts[$this->healthBucket($ticketCount, $thresholds)] = $ticketCount;

                return [
                    'store_id' => $store->id,
                    'store_code' => $store->code,
                    'store_name' => $store->name,
                    'area' => $store->area,
                    'store_count' => 1,
                    'total_tickets' => $ticketCount,
                    'health_counts' => $healthCounts,
                ];
            })
            ->filter()
            ->sortBy('store_code')
            ->values()
            ->all();
    }
}
