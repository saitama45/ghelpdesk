<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketAsset;
use Illuminate\Support\Collection;

/**
 * Asset Operational Health — per-physical-unit health, derived from live ticket state.
 *
 * A deployed fixed unit is a `stock_ins` row (serialized, status = Posted) whose
 * catalog `assets.type` is 'Fixed'. Its health is NOT stored anywhere:
 *
 *   IMPACTED (red)    at least one ticket_assets row for this stock_in_id belongs
 *                     to a non-deleted ticket whose status is not resolved/closed
 *   OPERATIONAL (green) otherwise
 *
 * Deriving it means tagging, untagging, resolving, closing, reopening, deleting and
 * the multi-ticket edge case all resolve correctly with no synchronization hook to
 * drift. Resolving one of two linked tickets leaves the unit red for free.
 *
 * This is deliberately a different metric from Live Store Health / Live Brand Health,
 * which count open tickets per store. Those tabs are untouched.
 */
class AssetOperationalHealthService
{
    /** Ticket statuses that stop counting against a unit. */
    private const TERMINAL_STATUSES = ['resolved', 'closed'];

    /** Units with no mapped category fold into this bucket so totals always reconcile. */
    private const UNGROUPED = 'Ungrouped';

    /**
     * The six groups from the reference sheet, in its column order. These are ALWAYS
     * rendered as columns even when a store has nothing in them, because the board is
     * read across stores — a missing column would silently change the shape per row.
     * Any reference_options asset_group outside this list is appended after them.
     */
    private const SLIDE_GROUP_ORDER = [
        'POS Systems',
        'Peripherals',
        'Security',
        'Network & Connectivity',
        'Digital Experience',
        'Back Office',
    ];

    /**
     * Severity legend, matching the sheet's LEGEND box. Health itself stays binary
     * (a unit is impacted or it is not) — this grades HOW BAD the impact is, from the
     * worst active linked ticket's priority. That is why two stores with one issue
     * each can sit on different colours in the reference sheet.
     */
    private const PRIORITY_BANDS = [
        'urgent' => 'critical',
        'high' => 'at_risk',
        'medium' => 'warning',
        'low' => 'warning',
    ];

    public const BANDS = [
        'healthy' => ['key' => 'healthy', 'label' => 'Healthy', 'rank' => 0],
        'warning' => ['key' => 'warning', 'label' => 'Warning', 'rank' => 1],
        'at_risk' => ['key' => 'at_risk', 'label' => 'At Risk', 'rank' => 2],
        'critical' => ['key' => 'critical', 'label' => 'Critical', 'rank' => 3],
    ];

    /** Ticket status → the sheet's "Next Action" phrasing. */
    private const NEXT_ACTION = [
        'open' => 'For Assessment',
        'for_schedule' => 'Scheduled',
        'in_progress' => 'Repair in Progress',
        'waiting_service_provider' => 'Waiting for Partner',
        'waiting_client_feedback' => 'Awaiting Store Confirmation',
    ];

    /**
     * @param  array|null  $companyIds  Effective Entity/Company selection from the dashboard
     *                                  filter. Units are scoped by the OWNING company of the
     *                                  store they currently sit in, so the tab can never show
     *                                  a unit outside the viewer's entity scope.
     * @param  int|string|null  $storeId  Optional single-store narrowing (dashboard store filter).
     * @param  string|null  $group  Optional asset-group narrowing.
     */
    public function build(?array $companyIds = null, $storeId = null, ?string $group = null): array
    {
        $stores = $this->storeUniverse($companyIds, $storeId);

        if ($stores->isEmpty()) {
            return $this->emptyPayload($companyIds !== null, $group);
        }

        $units = $this->deployedUnits($stores);
        $ticketsByUnit = $this->activeTicketsByUnit($units->pluck('id'));
        $groupByCategory = $this->groupByCategoryId();

        $rows = $units->map(function (array $unit) use ($ticketsByUnit, $groupByCategory) {
            $tickets = $ticketsByUnit->get($unit['id'], collect());

            return array_merge($unit, [
                'group' => $groupByCategory[$unit['category_id']] ?? self::UNGROUPED,
                'active_tickets' => $tickets->count(),
                'status' => $tickets->isNotEmpty() ? 'impacted' : 'operational',
                'band' => $this->worstBand($tickets),
                'tickets' => $tickets->values()->all(),
            ]);
        });

        // Group filter is applied after mapping so the group list itself stays complete
        // (a filtered-out group must still be selectable).
        $groups = $this->groupSummaries($rows);
        $visible = $group ? $rows->where('group', $group)->values() : $rows;

        return [
            'entity_scoped' => $companyIds !== null,
            'group' => $group,
            'groups' => $groups,
            // Fixed column axis for the monitoring board, in the reference sheet's order,
            // each carrying the Category names mapped to it (the sheet's second header row).
            'columns' => $this->boardColumns($rows),
            'legend' => array_values(self::BANDS),
            'totals' => $this->totals($visible),
            'stores' => $this->storeSummaries($visible, $stores),
        ];
    }

    /**
     * The board's column axis: the six reference groups (always present, in sheet order),
     * plus any extra configured group, each with the Category names mapped to it.
     *
     * "Ungrouped" is appended only when something actually lands there, so a fully
     * mapped taxonomy shows exactly the sheet's six columns.
     */
    private function boardColumns(Collection $rows): array
    {
        $categoriesByGroup = Category::query()
            ->whereNotNull('asset_group_id')
            ->with('assetGroup:id,label')
            ->get(['id', 'name', 'asset_group_id'])
            ->groupBy(fn (Category $category) => $category->assetGroup?->label ?? self::UNGROUPED)
            ->map(fn (Collection $categories) => $categories->pluck('name')->sort()->values()->all());

        // Configured groups beyond the six (someone can add one on /categories).
        $configured = $categoriesByGroup->keys()
            ->reject(fn ($name) => in_array($name, self::SLIDE_GROUP_ORDER, true) || $name === self::UNGROUPED)
            ->sort()
            ->values()
            ->all();

        $names = array_merge(self::SLIDE_GROUP_ORDER, $configured);

        if ($rows->contains('group', self::UNGROUPED)) {
            $names[] = self::UNGROUPED;
        }

        return collect($names)
            ->map(fn (string $name) => [
                'name' => $name,
                // The sheet's "Category" sub-header. Empty until someone maps categories.
                'categories' => $categoriesByGroup->get($name, []),
            ])
            ->all();
    }

    /**
     * The worst severity band across a set of active tickets.
     *
     * No active ticket is Healthy. Otherwise the highest-priority linked ticket decides,
     * so one urgent issue outranks three low ones — which is how the reference sheet
     * ends up with different colours on stores that both show a single active issue.
     */
    private function worstBand(Collection $tickets): string
    {
        if ($tickets->isEmpty()) {
            return 'healthy';
        }

        return $tickets
            ->map(fn ($ticket) => self::PRIORITY_BANDS[$ticket['priority'] ?? 'medium'] ?? 'warning')
            ->sortByDesc(fn (string $band) => self::BANDS[$band]['rank'])
            ->first() ?? 'warning';
    }

    /** Merge two bands, keeping the worse one. */
    private function mergeBand(string $a, string $b): string
    {
        return self::BANDS[$a]['rank'] >= self::BANDS[$b]['rank'] ? $a : $b;
    }

    /**
     * Drill-down: the individual units behind a store (optionally one group), each with
     * every active linked ticket — not just the newest one.
     *
     * Entity scope is re-applied here from the server side, so a client-supplied store id
     * can never reach a store outside the viewer's companies.
     *
     * @param  array|null  $companyIds  Same entity selection the tab was built with.
     */
    public function units(?array $companyIds, $storeId, ?string $group = null, ?string $status = null): array
    {
        $stores = $this->storeUniverse($companyIds, $storeId);

        if ($stores->isEmpty()) {
            return ['store' => null, 'units' => [], 'count' => 0];
        }

        $units = $this->deployedUnits($stores);
        $ticketsByUnit = $this->activeTicketsByUnit($units->pluck('id'));
        $groupByCategory = $this->groupByCategoryId();

        $rows = $units
            ->map(function (array $unit) use ($ticketsByUnit, $groupByCategory) {
                $tickets = $ticketsByUnit->get($unit['id'], collect());

                return array_merge($unit, [
                    'group' => $groupByCategory[$unit['category_id']] ?? self::UNGROUPED,
                    'active_tickets' => $tickets->count(),
                    'status' => $tickets->isNotEmpty() ? 'impacted' : 'operational',
                    'band' => $this->worstBand($tickets),
                    'tickets' => $tickets->values()->all(),
                ]);
            })
            ->when($group, fn (Collection $rows) => $rows->where('group', $group))
            ->when($status, fn (Collection $rows) => $rows->where('status', $status))
            // Impacted units first — the drill-down exists to triage them.
            ->sortBy([['status', 'asc'], ['serial_no', 'asc']])
            ->values();

        $store = $stores->count() === 1 ? $stores->first() : null;

        return [
            'store' => $store ? [
                'id' => (int) $store->id,
                'code' => $store->code,
                'name' => $store->name,
            ] : null,
            'count' => $rows->count(),
            'units' => $rows->all(),
        ];
    }

    /**
     * Active stores inside the entity selection. An explicit empty selection means
     * "no accessible entity" and must yield nothing rather than everything.
     */
    private function storeUniverse(?array $companyIds, $storeId = null): Collection
    {
        return Store::query()
            ->where('is_active', true)
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds ?: [0]))
            ->when($storeId && $storeId !== 'all', fn ($q) => $q->where('id', (int) $storeId))
            ->get(['id', 'code', 'name', 'company_id']);
    }

    /**
     * Every deployed fixed unit whose CURRENT location is one of the given stores.
     *
     * Current location follows received transfers exactly like
     * LocatesInventoryUnits::fixedUnitsCurrentlyAt, but resolved for the whole store
     * set in one pass instead of per store — the dashboard spans hundreds of stores
     * and a per-store call would be an N+1 by construction.
     *
     * A unit that was transferred away while a ticket is still linked follows the unit:
     * it is counted at its NEW store, and the ticket history stays intact on the ticket.
     *
     * @return Collection<int, array>
     */
    private function deployedUnits(Collection $stores): Collection
    {
        // Both store code and name are accepted, because the denormalized
        // destination_location column holds whichever the encoder typed.
        $storeByLocation = [];
        foreach ($stores as $store) {
            if ($store->code) {
                $storeByLocation[$this->locationKey($store->code)] = $store;
            }
            if ($store->name) {
                $storeByLocation[$this->locationKey($store->name)] = $store;
            }
        }

        $locations = $stores
            ->flatMap(fn (Store $store) => array_filter([$store->code, $store->name]))
            ->unique()
            ->values()
            ->all();

        return StockIn::query()
            // StockIn, Asset and StockTransfer are all entity-scoped models. That scope
            // pins queries to the viewer's single ACTIVE entity, which would silently
            // drop units whenever the dashboard's Entity filter selects more than one.
            // This tab does its own entity scoping — through the store universe above,
            // by the store that physically holds the unit — so the listing scope has to
            // come off every leg of the query, including the relation subqueries.
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->where('stock_ins.status', 'Posted')
            ->whereHas('asset', fn ($q) => $q->withoutGlobalScope(ActiveEntityScope::class)->where('type', 'Fixed'))
            // Narrow in SQL before the PHP pass: a unit is a candidate if it was either
            // stocked into one of these stores or transferred into one. The PHP pass then
            // decides which of the two actually holds it now. Without this the whole
            // deployed fleet crosses the wire only to be discarded.
            ->where(function ($query) use ($locations) {
                $query->whereIn('stock_ins.destination_location', $locations)
                    ->orWhereHas('sourceStockTransfers', fn ($q) => $q
                        ->withoutGlobalScope(ActiveEntityScope::class)
                        ->where('status', 'Received')
                        ->whereIn('destination_location', $locations));
            })
            ->with([
                'asset' => fn ($q) => $q
                    ->withoutGlobalScope(ActiveEntityScope::class)
                    ->select('id', 'item_code', 'brand', 'model', 'category_id', 'sub_category_id'),
                'asset.subCategory:id,name',
                // Only the transfer rows that can move a unit's current location.
                'sourceStockTransfers' => fn ($q) => $q
                    ->withoutGlobalScope(ActiveEntityScope::class)
                    ->where('status', 'Received')
                    ->select('id', 'source_stock_in_id', 'status', 'destination_location')
                    ->orderByDesc('id'),
            ])
            // Pinned columns: stock_ins carries memo_remarks (nvarchar(MAX)) which would
            // drag the whole deployed fleet over the Azure link for fields nobody shows.
            ->select('id', 'asset_id', 'serial_no', 'barcode', 'qrcode', 'destination_location', 'status')
            ->get()
            ->map(function (StockIn $unit) use ($storeByLocation) {
                $received = $unit->sourceStockTransfers->firstWhere('status', 'Received');
                $location = $received?->destination_location ?: $unit->destination_location;
                $store = $storeByLocation[$this->locationKey($location)] ?? null;

                if (! $store) {
                    return null; // Sits outside the entity/store scope of this build.
                }

                return [
                    'id' => (int) $unit->id,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'qrcode' => $unit->qrcode,
                    'item_code' => $unit->asset?->item_code,
                    'brand' => $unit->asset?->brand,
                    'model' => $unit->asset?->model,
                    'sub_category' => $unit->asset?->subCategory?->name,
                    'category_id' => (int) ($unit->asset?->category_id ?? 0),
                    'store_id' => (int) $store->id,
                    'store_code' => $store->code,
                    'store_name' => $store->name,
                ];
            })
            ->filter()
            ->values();
    }

    /** Case/whitespace-insensitive key so "ST001" and "st001 " match the same store. */
    private function locationKey(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Active linked tickets per physical unit — the single source for both the board
     * and the drill-down, so the counts on screen can never disagree with the list
     * behind them.
     *
     * "Active" = the linked ticket is not resolved/closed and not soft-deleted. Child
     * (escalation) tickets count too: per-unit health asks whether ANY active ticket is
     * on the unit, not whether a root ticket is, so the parent_id filter used by the
     * store-level dashboards deliberately does not apply here.
     *
     * @return Collection<int, Collection>
     */
    private function activeTicketsByUnit(Collection $unitIds): Collection
    {
        if ($unitIds->isEmpty()) {
            return collect();
        }

        $links = TicketAsset::query()
            ->whereIn('ticket_assets.stock_in_id', $unitIds)
            ->select('ticket_id', 'stock_in_id')
            ->get();

        if ($links->isEmpty()) {
            return collect();
        }

        // ActiveEntityScope is a listing filter, not an auth boundary — re-querying
        // tickets by id must bypass it or cross-entity links silently vanish. Entity
        // safety for this tab comes from the store universe above.
        $tickets = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereIn('tickets.id', $links->pluck('ticket_id')->unique()->values())
            ->whereNotIn('tickets.status', self::TERMINAL_STATUSES)
            // vendor + SLA target are what fill the sheet's Owner and ETA columns.
            ->with(['assignee:id,name', 'vendor:id,name', 'slaMetric:id,ticket_id,resolution_target_at'])
            ->select('id', 'ticket_key', 'title', 'status', 'priority', 'assignee_id', 'vendor_id', 'created_at')
            ->get()
            ->keyBy('id');

        return $links
            ->filter(fn ($link) => $tickets->has($link->ticket_id))
            ->groupBy('stock_in_id')
            ->map(fn ($rows) => $rows
                ->pluck('ticket_id')
                ->unique()
                ->map(function ($ticketId) use ($tickets) {
                    $ticket = $tickets->get($ticketId);
                    $eta = $ticket->slaMetric?->resolution_target_at;

                    return [
                        'id' => $ticket->id,
                        'key' => $ticket->ticket_key ?? (string) $ticket->id,
                        'title' => $ticket->title,
                        'status' => $ticket->status,
                        'priority' => $ticket->priority,
                        'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                        // Sheet "Owner": a ticket handed to a partner is owned by that
                        // partner; otherwise the assignee carries it.
                        'owner' => $ticket->vendor?->name ?? $ticket->assignee?->name ?? 'Unassigned',
                        'is_partner' => $ticket->vendor_id !== null,
                        // Sheet "Next Action": the committed next step, read off the
                        // ticket's own workflow state rather than typed by hand.
                        'next_action' => self::NEXT_ACTION[$ticket->status] ?? 'Under Monitoring',
                        // Sheet "ETA": the SLA resolution target. Null when no SLA row
                        // exists — shown as an em dash, never faked.
                        'eta' => $eta?->format('M j, Y'),
                        'eta_sort' => $eta?->getTimestamp(),
                        'created_at' => $ticket->created_at?->format('M j, Y'),
                        'url' => route('tickets.edit', $ticket->id),
                    ];
                })
                ->values());
    }

    /**
     * category_id => asset group label, from the reference_options mapping on
     * categories.asset_group_id (slide 07's Groups & Category Triggers).
     *
     * @return array<int,string>
     */
    private function groupByCategoryId(): array
    {
        return Category::query()
            ->whereNotNull('asset_group_id')
            ->with('assetGroup:id,label')
            ->get(['id', 'asset_group_id'])
            ->mapWithKeys(fn (Category $category) => [
                (int) $category->id => $category->assetGroup?->label ?? self::UNGROUPED,
            ])
            ->all();
    }

    /** Per-group unit counts, so the tab can filter and show where breakage concentrates. */
    private function groupSummaries(Collection $rows): array
    {
        return $rows
            ->groupBy('group')
            ->map(fn (Collection $units, string $group) => [
                'name' => $group,
                'total' => $units->count(),
                'impacted' => $units->where('status', 'impacted')->count(),
                'operational' => $units->where('status', 'operational')->count(),
            ])
            ->sortBy(fn ($row) => [$row['name'] === self::UNGROUPED ? 1 : 0, $row['name']])
            ->values()
            ->all();
    }

    /**
     * Per-store roll-up plus a group breakdown per store — the store x group matrix
     * from the reference dashboard. Stores with no deployed units are listed with a
     * zero total rather than dropped, so "no units encoded yet" reads differently
     * from "all units healthy".
     */
    private function storeSummaries(Collection $rows, Collection $stores): array
    {
        $byStore = $rows->groupBy('store_id');

        return $stores
            ->map(function (Store $store) use ($byStore) {
                $units = $byStore->get($store->id, collect());
                $impacted = $units->where('status', 'impacted');

                // Every active ticket touching any unit at this store, de-duplicated —
                // one ticket tagged to three units is one issue, not three.
                $activeTickets = $impacted
                    ->flatMap(fn (array $unit) => $unit['tickets'] ?? [])
                    ->unique('id')
                    ->values();

                // Sheet action columns. The driving ticket is the most urgent one, and
                // among equals the one due soonest — that is the row's real next step.
                $driver = $activeTickets
                    ->sortBy([
                        fn ($ticket) => -self::BANDS[self::PRIORITY_BANDS[$ticket['priority'] ?? 'medium'] ?? 'warning']['rank'],
                        fn ($ticket) => $ticket['eta_sort'] ?? PHP_INT_MAX,
                    ])
                    ->first();

                return [
                    'id' => (int) $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'total' => $units->count(),
                    'operational' => $units->count() - $impacted->count(),
                    'impacted' => $impacted->count(),
                    'active_tickets' => $activeTickets->count(),
                    'status' => $impacted->isNotEmpty() ? 'impacted' : 'operational',
                    'band' => $impacted->reduce(
                        fn (string $carry, array $unit) => $this->mergeBand($carry, $unit['band']),
                        'healthy'
                    ),
                    'owner' => $driver['owner'] ?? null,
                    'is_partner' => (bool) ($driver['is_partner'] ?? false),
                    'next_action' => $driver['next_action'] ?? null,
                    'eta' => $driver['eta'] ?? null,
                    // Keyed by group so the board can render a fixed column axis without
                    // every row having to carry the groups it has nothing in.
                    'groups' => $units
                        ->groupBy('group')
                        ->map(fn (Collection $groupUnits, string $group) => [
                            'name' => $group,
                            'total' => $groupUnits->count(),
                            'impacted' => $groupUnits->where('status', 'impacted')->count(),
                            'band' => $groupUnits->reduce(
                                fn (string $carry, array $unit) => $this->mergeBand($carry, $unit['band']),
                                'healthy'
                            ),
                        ])
                        ->all(),
                ];
            })
            // Worst stores first (Critical before At Risk before Warning), then the
            // busiest fleets — the sheet is read top-down for what needs attention.
            ->sortBy([
                fn ($row) => -self::BANDS[$row['band']]['rank'],
                fn ($row) => -$row['impacted'],
                fn ($row) => -$row['total'],
                fn ($row) => (string) $row['code'],
            ])
            ->values()
            ->all();
    }

    private function totals(Collection $rows): array
    {
        $total = $rows->count();
        $impacted = $rows->where('status', 'impacted');
        $impactedCount = $impacted->count();

        return [
            'units' => $total,
            'operational' => $total - $impactedCount,
            'impacted' => $impactedCount,
            // Guarded: an empty fleet is 0% impacted, not a division by zero.
            'impacted_pct' => $total > 0 ? round($impactedCount / $total * 100, 1) : 0.0,
            'active_tickets' => (int) $impacted->sum('active_tickets'),
            'stores_impacted' => $impacted->pluck('store_id')->unique()->count(),
        ];
    }

    private function emptyPayload(bool $entityScoped, ?string $group): array
    {
        return [
            'entity_scoped' => $entityScoped,
            'group' => $group,
            'groups' => [],
            'columns' => $this->boardColumns(collect()),
            'legend' => array_values(self::BANDS),
            'totals' => [
                'units' => 0,
                'operational' => 0,
                'impacted' => 0,
                'impacted_pct' => 0.0,
                'active_tickets' => 0,
                'stores_impacted' => 0,
            ],
            'stores' => [],
        ];
    }
}
