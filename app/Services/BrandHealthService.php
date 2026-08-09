<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Live Brand Health — a brand-centric read of the ticket backlog.
 *
 * A "brand" is a Company row with type = 'Brand'. Every store belongs to exactly
 * one company via stores.company_id (kept in sync with the legacy `brand` string,
 * see the backfill_store_company_id_from_brand migration), so a brand's stores are
 * simply Store::where('company_id', brand.id). This mirrors the entity-based
 * grouping used by the Live Store Health heatmap, but pivoted around brands and
 * their confirmation workflow (OPEN / WCF / WSP) instead of sectors.
 */
class BrandHealthService
{
    /** Thresholds identical to StoreReportService so both tabs bucket stores the same way. */
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

    /** Ticket status → workflow lane. Terminal statuses are excluded upstream. */
    private const WORKFLOW = [
        'open' => ['open', 'for_schedule', 'in_progress'],
        'wsp'  => ['waiting_service_provider'],
        'wcf'  => ['waiting_client_feedback'],
    ];

    public function build($user, ?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?: Carbon::now()->format('Y-m-d');
        $agingDays = (int) Setting::get('waiting_aging_alarm_days', 3);
        $bands = $this->thresholdBands();

        // Every active brand (Company type = 'Brand'). Ordered by name for stable tabs.
        $brands = Company::query()
            ->where('is_active', true)
            ->where('type', 'Brand')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'logo']);

        if ($brands->isEmpty()) {
            return [
                'as_of' => Carbon::parse($asOfDate)->format('M j, Y'),
                'aging_days' => $agingDays,
                'thresholds' => $this->thresholdLabels($bands),
                'can_close' => (bool) $user?->can('tickets.close'),
                'can_reopen' => (bool) $user?->can('tickets.edit'),
                'totals' => $this->emptyTotals(),
                'brands' => [],
            ];
        }

        $brandIds = $brands->pluck('id');

        // All active stores owned by these brands, grouped by owning brand.
        $storesByBrand = Store::query()
            ->where('is_active', true)
            ->whereIn('company_id', $brandIds)
            ->get(['id', 'code', 'name', 'company_id'])
            ->groupBy('company_id');

        $allStoreIds = $storesByBrand->flatten(1)->pluck('id');

        // Per-store open (non-terminal) ticket counts broken down by status. One
        // grouped query for the whole tab. Scoped by store ownership, so every open
        // ticket sitting on the brand's store counts regardless of its stamped company.
        $statusCountsByStore = collect();
        if ($allStoreIds->isNotEmpty()) {
            $statusCountsByStore = Ticket::query()
                ->withoutGlobalScope(ActiveEntityScope::class)
                ->whereNull('tickets.parent_id')
                ->whereNotIn('tickets.status', ['resolved', 'closed'])
                ->whereIn('tickets.store_id', $allStoreIds)
                ->whereDate('tickets.created_at', '<=', $asOfDate)
                ->selectRaw('store_id, status, COUNT(*) as c')
                ->groupBy('store_id', 'status')
                ->get()
                ->groupBy('store_id')
                ->map(fn ($rows) => $rows->pluck('c', 'status')->map(fn ($c) => (int) $c)->all());
        }

        // Same open backlog, sliced by sub-category (the /items taxonomy) so each brand
        // can be ranked by what is actually breaking. Grouped per store so the rows can
        // be folded up per brand without a second round trip.
        $subCategoryRowsByStore = collect();
        if ($allStoreIds->isNotEmpty()) {
            $subCategoryRowsByStore = Ticket::query()
                ->withoutGlobalScope(ActiveEntityScope::class)
                ->whereNull('tickets.parent_id')
                ->whereNotIn('tickets.status', ['resolved', 'closed'])
                ->whereIn('tickets.store_id', $allStoreIds)
                ->whereDate('tickets.created_at', '<=', $asOfDate)
                ->selectRaw('store_id, sub_category_id, COUNT(*) as c')
                ->groupBy('store_id', 'sub_category_id')
                ->get()
                ->groupBy('store_id');
        }

        $subCategoryNames = SubCategory::query()
            ->whereIn('id', $subCategoryRowsByStore->flatten(1)->pluck('sub_category_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        // The WCF confirmation register: the actual tickets awaiting brand confirmation.
        $wcfByBrand = $this->wcfRegister($allStoreIds, $storesByBrand, $asOfDate, $agingDays);

        $brandLabels = $brands->mapWithKeys(fn (Company $brand) => [
            (int) $brand->id => $brand->code ?: $brand->name,
        ])->all();

        $brandRows = $brands->map(function (Company $brand) use ($storesByBrand, $statusCountsByStore, $bands, $wcfByBrand, $subCategoryRowsByStore, $subCategoryNames, $brandLabels) {
            $stores = $storesByBrand->get($brand->id, collect());

            $health = ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0];
            $workflow = ['open' => 0, 'wsp' => 0, 'wcf' => 0];
            $storesWithTickets = 0;

            foreach ($stores as $store) {
                $counts = $statusCountsByStore->get($store->id, []);
                $openTotal = array_sum($counts);

                if ($openTotal > 0) {
                    $storesWithTickets++;
                }
                $health[$this->healthBucket($openTotal, $bands)]++;

                foreach (self::WORKFLOW as $lane => $statuses) {
                    foreach ($statuses as $status) {
                        $workflow[$lane] += (int) ($counts[$status] ?? 0);
                    }
                }
            }

            $activeTickets = $workflow['open'] + $workflow['wsp'] + $workflow['wcf'];
            // Priority = At-risk (orange) + Critical (red) stores, matching Live Store Health.
            $priorityStores = $health['orange'] + $health['red'];

            return [
                'id' => (int) $brand->id,
                'name' => $brand->name,
                'code' => $brand->code,
                'logo' => $brand->logo,
                'total_stores' => $stores->count(),
                'stores_with_tickets' => $storesWithTickets,
                'priority_stores' => $priorityStores,
                'active_tickets' => $activeTickets,
                'health' => $health,
                'workflow' => $workflow,
                'top_subcategories' => $this->topSubCategories($stores, $subCategoryRowsByStore, $subCategoryNames),
                'top_stores' => $this->topStores($stores, $statusCountsByStore, $bands, $brandLabels),
                'wcf_register' => $wcfByBrand->get($brand->id, collect())->values()->all(),
            ];
        })->values();

        $allStores = $storesByBrand->flatten(1);

        return [
            'as_of' => Carbon::parse($asOfDate)->format('M j, Y'),
            'aging_days' => $agingDays,
            'thresholds' => $this->thresholdLabels($bands),
            'can_close' => (bool) $user?->can('tickets.close'),
            'can_reopen' => (bool) $user?->can('tickets.edit'),
            'totals' => array_merge($this->aggregateTotals($brandRows), [
                'top_subcategories' => $this->topSubCategories($allStores, $subCategoryRowsByStore, $subCategoryNames),
                'top_stores' => $this->topStores($allStores, $statusCountsByStore, $bands, $brandLabels),
            ]),
            'brands' => $brandRows->all(),
        ];
    }

    /**
     * Top sub-categories (the /items taxonomy) by open-ticket volume for a set of
     * stores. Tickets with no sub-category fold into a single "Unspecified" row so
     * the list always adds up to the brand's open backlog.
     */
    private function topSubCategories(Collection $stores, Collection $subCategoryRowsByStore, Collection $names, int $limit = 10): array
    {
        $counts = [];

        foreach ($stores as $store) {
            foreach ($subCategoryRowsByStore->get($store->id, collect()) as $row) {
                $key = (int) ($row->sub_category_id ?? 0);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->c;
            }
        }

        arsort($counts);

        return collect($counts)
            ->take($limit)
            ->map(fn ($count, $key) => [
                // 'none' keeps the drill-down honest: it means sub_category_id IS NULL,
                // which is a different filter from "sub-category 0".
                'id' => $key ? $key : 'none',
                'name' => $key ? ($names[$key] ?? "Sub-category #{$key}") : 'Unspecified',
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /** Top stores by open-ticket volume, carrying their health band for colouring. */
    private function topStores(Collection $stores, Collection $statusCountsByStore, array $bands, array $brandLabels, int $limit = 10): array
    {
        return $stores
            ->map(function (Store $store) use ($statusCountsByStore, $bands, $brandLabels) {
                $counts = $statusCountsByStore->get($store->id, []);
                $total = array_sum($counts);

                $workflow = ['open' => 0, 'wsp' => 0, 'wcf' => 0];
                foreach (self::WORKFLOW as $lane => $statuses) {
                    foreach ($statuses as $status) {
                        $workflow[$lane] += (int) ($counts[$status] ?? 0);
                    }
                }

                return [
                    'id' => (int) $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'brand' => $brandLabels[(int) $store->company_id] ?? null,
                    'count' => $total,
                    'band' => $this->healthBucket($total, $bands),
                    'workflow' => $workflow,
                ];
            })
            ->filter(fn ($row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Drill-down behind the Top 10 lists: the open tickets for a brand slice, plus a
     * per-item roll-up so a sub-category click answers "which items and concerns".
     *
     * @param array{brand_id?:int|null, sub_category_id?:int|string|null, store_id?:int|null, as_of_date?:string|null} $filters
     */
    public function tickets(array $filters): array
    {
        $asOfDate = $filters['as_of_date'] ?? Carbon::now()->format('Y-m-d');
        $brandId = $filters['brand_id'] ?? null;
        $storeId = $filters['store_id'] ?? null;
        $subCategory = $filters['sub_category_id'] ?? null;

        $brands = Company::query()
            ->where('is_active', true)
            ->where('type', 'Brand')
            ->when($brandId, fn ($q) => $q->where('id', $brandId))
            ->get(['id', 'name', 'code']);

        $stores = $brands->isEmpty()
            ? collect()
            : Store::query()
                ->where('is_active', true)
                ->whereIn('company_id', $brands->pluck('id'))
                ->when($storeId, fn ($q) => $q->where('id', $storeId))
                ->get(['id', 'code', 'name', 'company_id']);

        if ($stores->isEmpty()) {
            return ['tickets' => [], 'items' => [], 'count' => 0];
        }

        $tickets = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereNull('tickets.parent_id')
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->whereIn('tickets.store_id', $stores->pluck('id'))
            ->whereDate('tickets.created_at', '<=', $asOfDate)
            ->when($subCategory === 'none', fn ($q) => $q->whereNull('tickets.sub_category_id'))
            ->when($subCategory !== null && $subCategory !== '' && $subCategory !== 'none',
                fn ($q) => $q->where('tickets.sub_category_id', (int) $subCategory))
            ->with(['store:id,code,name', 'item:id,name,concern_type', 'subCategory:id,name', 'assignee:id,name'])
            // Pinned columns — tickets.description is nvarchar(MAX) and would drag the
            // whole backlog over the wire for a modal that never shows it.
            ->select('id', 'ticket_key', 'title', 'status', 'priority', 'store_id', 'item_id', 'sub_category_id', 'assignee_id', 'created_at')
            ->latest('created_at')
            ->limit(500)
            ->get();

        // Per-item roll-up: "its specific items and concerns" for the clicked slice.
        $items = $tickets
            ->groupBy(fn (Ticket $ticket) => $ticket->item?->name ?? 'Unspecified')
            ->map(fn ($rows, $name) => [
                'name' => $name,
                'concern_type' => $rows->first()->item?->concern_type,
                'sub_category' => $rows->first()->subCategory?->name,
                'count' => $rows->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'count' => $tickets->count(),
            'items' => $items,
            'tickets' => $tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'key' => $ticket->ticket_key ?? (string) $ticket->id,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'store' => $ticket->store
                    ? trim(($ticket->store->code ? '[' . $ticket->store->code . '] ' : '') . $ticket->store->name)
                    : null,
                'sub_category' => $ticket->subCategory?->name ?? 'Unspecified',
                'item' => $ticket->item?->name ?? 'Unspecified',
                'concern_type' => $ticket->item?->concern_type,
                'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                'created_at' => $ticket->created_at?->format('M j, Y'),
                'url' => route('tickets.edit', $ticket->id),
            ])->all(),
        ];
    }

    /**
     * Build the per-brand list of tickets awaiting client (brand) confirmation —
     * i.e. tickets in the waiting_client_feedback status sitting on a brand's store.
     */
    private function wcfRegister($allStoreIds, Collection $storesByBrand, string $asOfDate, int $agingDays): Collection
    {
        if ($allStoreIds->isEmpty()) {
            return collect();
        }

        // store_id → owning brand (company) id, so each WCF ticket lands under its brand.
        $storeToBrand = [];
        foreach ($storesByBrand as $companyId => $stores) {
            foreach ($stores as $store) {
                $storeToBrand[$store->id] = (int) $companyId;
            }
        }

        $now = Carbon::now('Asia/Manila');

        return Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereNull('tickets.parent_id')
            ->where('tickets.status', 'waiting_client_feedback')
            ->whereIn('tickets.store_id', $allStoreIds)
            ->whereDate('tickets.created_at', '<=', $asOfDate)
            ->with(['store:id,code,name'])
            ->select('id', 'ticket_key', 'title', 'status', 'store_id', 'updated_at', 'created_at')
            ->latest('updated_at')
            ->get()
            ->map(function (Ticket $ticket) use ($storeToBrand, $now, $agingDays) {
                // The ticket has been waiting since it last changed — updated_at is the
                // best available proxy for when it entered WCF (matches the Overview
                // waiting-aging alarm, which also ages off updated_at).
                $enteredAt = Carbon::parse($ticket->updated_at);
                $ageDays = round($enteredAt->diffInMinutes($now) / (60 * 24), 1);

                return [
                    'brand_id' => $storeToBrand[$ticket->store_id] ?? null,
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key ?? (string) $ticket->id,
                    'title' => $ticket->title,
                    'store' => $ticket->store
                        ? trim(($ticket->store->code ? '[' . $ticket->store->code . '] ' : '') . $ticket->store->name)
                        : null,
                    'entered_at' => $enteredAt->format('M j, Y'),
                    'age_days' => $ageDays,
                    'over_threshold' => $ageDays >= $agingDays,
                    'url' => route('tickets.edit', $ticket->id),
                ];
            })
            ->groupBy('brand_id');
    }

    private function aggregateTotals(Collection $brandRows): array
    {
        $totals = $this->emptyTotals();
        $totals['brands'] = $brandRows->count();

        foreach ($brandRows as $brand) {
            $totals['total_stores'] += $brand['total_stores'];
            $totals['stores_with_tickets'] += $brand['stores_with_tickets'];
            $totals['priority_stores'] += $brand['priority_stores'];
            $totals['active_tickets'] += $brand['active_tickets'];
            foreach (['green', 'yellow', 'orange', 'red'] as $key) {
                $totals['health'][$key] += $brand['health'][$key];
            }
            foreach (['open', 'wsp', 'wcf'] as $key) {
                $totals['workflow'][$key] += $brand['workflow'][$key];
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'brands' => 0,
            'total_stores' => 0,
            'stores_with_tickets' => 0,
            'priority_stores' => 0,
            'active_tickets' => 0,
            'health' => ['green' => 0, 'yellow' => 0, 'orange' => 0, 'red' => 0],
            'workflow' => ['open' => 0, 'wsp' => 0, 'wcf' => 0],
            'top_subcategories' => [],
            'top_stores' => [],
        ];
    }

    /** Resolve the configured threshold bands (or the shared defaults). */
    private function thresholdBands(): array
    {
        $saved = Setting::where('group', 'thresholds')->pluck('value', 'key');
        $t = fn ($key) => $saved->get($key, self::DEFAULT_THRESHOLDS[$key] ?? null);

        return [
            ['key' => 'green', 'label' => (string) $t('threshold_green_label'), 'min' => (int) $t('threshold_green_min'), 'max' => (int) $t('threshold_green_max')],
            ['key' => 'yellow', 'label' => (string) $t('threshold_yellow_label'), 'min' => (int) $t('threshold_yellow_min'), 'max' => (int) $t('threshold_yellow_max')],
            ['key' => 'orange', 'label' => (string) $t('threshold_orange_label'), 'min' => (int) $t('threshold_orange_min'), 'max' => (int) $t('threshold_orange_max')],
            ['key' => 'red', 'label' => (string) $t('threshold_red_label'), 'min' => (int) $t('threshold_red_min'), 'max' => null],
        ];
    }

    private function thresholdLabels(array $bands): array
    {
        return collect($bands)->mapWithKeys(fn ($band) => [$band['key'] => [
            'label' => $band['label'],
            'min' => $band['min'],
            'max' => $band['max'],
        ]])->all();
    }

    private function healthBucket(int $ticketCount, array $bands): string
    {
        foreach ($bands as $band) {
            $withinMax = $band['max'] === null || $ticketCount <= $band['max'];
            if ($ticketCount >= $band['min'] && $withinMax) {
                return $band['key'];
            }
        }

        return $ticketCount >= $bands[array_key_last($bands)]['min'] ? 'red' : 'green';
    }
}
