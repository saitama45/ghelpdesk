<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Partner Performance — how the work we hand to external partners is actually going.
 *
 * The unit of measure is the PARTNER ESCALATION CHILD: the ticket created by
 * "Escalate to Partner" on a parent ticket. It is identified exactly the way
 * Ticket::scopeVendorEscalated defines it — vendor_id AND parent_id both set — because
 * a vendor_id alone only means the ticket was tagged with a partner for reporting
 * (including the "None - Remote" placeholder) and still belongs to an internal owner.
 * Keep this in step with `isVendorEscalationChild` in Tickets/Edit.vue.
 *
 * Open vs Closed follows the dashboard-wide tally: closed = resolved + closed, open =
 * everything else, so Total always equals Open + Closed. See [[project_ticket_open_closed_tally]].
 *
 * The brand axis is the owning company of the store the escalation sits on
 * (stores.company_id), mirroring Live Brand Health. Unlike that tab this one does NOT
 * restrict itself to companies of type 'Brand': a partner escalation on an
 * entity-owned store is still work a partner owes us, so every owning company gets a
 * row and storeless escalations fold into a single "No Brand" bucket. Nothing is
 * silently dropped from a partner's scorecard.
 */
class PartnerPerformanceService
{
    /** Statuses that count as closed for the whole dashboard. */
    private const TERMINAL = ['resolved', 'closed'];

    /** Bucket id used for escalations that sit on no store (no owning company). */
    private const NO_BRAND = 0;

    /**
     * @param  array|null  $companyIds  Effective Entity/Company selection from the dashboard
     *                                  filter. Null means "unscoped" (never used by the tab).
     * @param  int|null  $year   Escalation-date year filter (shared dashboard filter bar).
     * @param  int|null  $month  Escalation-date month filter.
     */
    public function build($user, ?array $companyIds = null, ?int $year = null, ?int $month = null): array
    {
        $agingDays = (int) Setting::get('waiting_aging_alarm_days', 3);
        $facts = $this->facts($user, $companyIds, $year, $month, $agingDays);

        $base = [
            'as_of' => Carbon::now('Asia/Manila')->format('M j, Y'),
            'period_label' => $this->periodLabel($year, $month),
            'aging_days' => $agingDays,
            'entity_scoped' => $companyIds !== null,
        ];

        if ($facts->isEmpty()) {
            return array_merge($base, [
                'totals' => $this->metrics(collect()),
                'partners' => [],
                'brands' => [],
                'aging_register' => [],
            ]);
        }

        // Per-partner scorecards, each carrying its own per-brand split so the
        // Partner × Brand matrix needs no second payload.
        $partners = $facts
            ->groupBy('vendor_id')
            ->map(fn (Collection $rows) => array_merge(
                $rows->first()['partner'],
                $this->metrics($rows),
                ['brands' => $this->splitBy($rows, 'brand')],
            ))
            ->sortByDesc(fn ($row) => [$row['open'], $row['total']])
            ->values();

        // The same escalations pivoted on the brand axis.
        $brands = $facts
            ->groupBy('brand_id')
            ->map(fn (Collection $rows) => array_merge(
                $rows->first()['brand'],
                $this->metrics($rows),
                ['partners' => $this->splitBy($rows, 'partner')],
            ))
            ->sortByDesc(fn ($row) => [$row['open'], $row['total']])
            ->values();

        return array_merge($base, [
            'totals' => array_merge($this->metrics($facts), [
                'partners' => $partners->count(),
                'brands' => $brands->count(),
            ]),
            'partners' => $partners->all(),
            'brands' => $brands->all(),
            'aging_register' => $this->agingRegister($facts),
        ]);
    }

    /**
     * Drill-down behind every clickable number on the tab: the escalation children
     * for one partner / brand / state slice.
     *
     * @param array{vendor_id?:int|null, brand_id?:int|string|null, state?:string|null, year?:int|null, month?:int|null} $filters
     * @param  array|null  $companyIds  Same entity selection the tab was built with, so a
     *                                  drill-down can never reach outside it.
     */
    public function tickets($user, array $filters, ?array $companyIds = null): array
    {
        $agingDays = (int) Setting::get('waiting_aging_alarm_days', 3);
        $state = $filters['state'] ?? 'all';
        $brandId = $filters['brand_id'] ?? null;

        $facts = $this->facts($user, $companyIds, $filters['year'] ?? null, $filters['month'] ?? null, $agingDays)
            ->when(!empty($filters['vendor_id']), fn (Collection $rows) => $rows->where('vendor_id', (int) $filters['vendor_id']))
            // 'none' is a real bucket (escalations with no store), distinct from "no filter".
            ->when($brandId !== null && $brandId !== '', fn (Collection $rows) => $rows->where(
                'brand_id',
                $brandId === 'none' ? self::NO_BRAND : (int) $brandId
            ))
            ->when($state === 'open', fn (Collection $rows) => $rows->where('is_closed', false))
            ->when($state === 'closed', fn (Collection $rows) => $rows->where('is_closed', true))
            ->when($state === 'aging', fn (Collection $rows) => $rows->where('is_aging', true))
            ->when($state === 'breached', fn (Collection $rows) => $rows->where('is_breached', true))
            ->sortByDesc(fn ($row) => $row['created_sort'])
            ->values();

        return [
            'count' => $facts->count(),
            'metrics' => $this->metrics($facts),
            'tickets' => $facts->take(500)->map(fn ($row) => $this->presentTicket($row))->values()->all(),
        ];
    }

    /**
     * Every partner escalation in scope, decorated once with the derived fields each
     * roll-up needs (closed?, days to close, age, brand, partner).
     */
    private function facts($user, ?array $companyIds, ?int $year, ?int $month, int $agingDays): Collection
    {
        $query = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->vendorEscalated()
            // Pinned columns — tickets.description is nvarchar(MAX) and would drag the
            // whole escalation history over the Azure link. See [[project_lob_columns_perf]].
            ->select('id', 'ticket_key', 'title', 'status', 'priority', 'vendor_id', 'parent_id', 'store_id', 'company_id', 'created_at', 'updated_at')
            ->with([
                'slaMetric:id,ticket_id,resolved_at,is_resolution_breached',
                'parent:id,ticket_key,title',
            ]);

        if ($year) {
            $query->whereYear('created_at', $year);
        }
        if ($month) {
            $query->whereMonth('created_at', $month);
        }

        if ($user?->hasRole('User')) {
            // A plain requester only ever sees escalations raised off their own tickets.
            $query->whereHas('parent', fn ($p) => $p->where('reporter_id', $user->id));
        }

        if ($companyIds !== null) {
            if (empty($companyIds)) {
                return collect();
            }
            $this->applyEntityScope($query, $companyIds);
        }

        $tickets = $query->get();

        if ($tickets->isEmpty()) {
            return collect();
        }

        $stores = Store::query()
            ->whereIn('id', $tickets->pluck('store_id')->filter()->unique()->values())
            ->get(['id', 'code', 'name', 'company_id'])
            ->keyBy('id');

        $companies = Company::query()
            ->whereIn('id', $stores->pluck('company_id')->filter()->unique()->values())
            ->get(['id', 'name', 'code', 'type'])
            ->keyBy('id');

        $vendors = Vendor::query()
            ->whereIn('id', $tickets->pluck('vendor_id')->unique()->values())
            ->get(['id', 'code', 'name', 'vendor_type', 'email', 'is_active'])
            ->keyBy('id');

        $now = Carbon::now('Asia/Manila');

        return $tickets->map(function (Ticket $ticket) use ($stores, $companies, $vendors, $now, $agingDays) {
            $store = $ticket->store_id ? $stores->get($ticket->store_id) : null;
            $company = $store?->company_id ? $companies->get($store->company_id) : null;
            $vendor = $vendors->get($ticket->vendor_id);

            $isClosed = in_array($ticket->status, self::TERMINAL, true);
            $created = Carbon::parse($ticket->created_at);
            // No closed_at column exists on tickets; the SLA metric records when the
            // ticket was resolved, and updated_at is the fallback proxy (same one the
            // WCF register ages off).
            $closedAt = $isClosed
                ? ($ticket->slaMetric?->resolved_at ?: $ticket->updated_at)
                : null;

            $days = $closedAt ? round(abs($created->diffInMinutes(Carbon::parse($closedAt))) / 1440, 1) : null;
            $ageDays = $isClosed ? null : round(abs($created->diffInMinutes($now)) / 1440, 1);

            return [
                'id' => $ticket->id,
                'key' => $ticket->ticket_key ?? (string) $ticket->id,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'vendor_id' => (int) $ticket->vendor_id,
                'brand_id' => $company ? (int) $company->id : self::NO_BRAND,
                'partner' => [
                    'id' => (int) $ticket->vendor_id,
                    'name' => $vendor?->name ?? 'Unknown Partner',
                    'code' => $vendor?->code,
                    'vendor_type' => $vendor?->vendor_type,
                    'email' => $vendor?->email,
                    'is_active' => (bool) ($vendor?->is_active ?? false),
                ],
                'brand' => [
                    'id' => $company ? (int) $company->id : self::NO_BRAND,
                    'name' => $company?->name ?? 'No Brand',
                    'code' => $company?->code,
                    'is_brand' => $company?->type === 'Brand',
                ],
                'store' => $store
                    ? trim(($store->code ? '[' . $store->code . '] ' : '') . $store->name)
                    : null,
                'parent_key' => $ticket->parent?->ticket_key,
                'parent_id' => $ticket->parent_id,
                'is_closed' => $isClosed,
                'is_breached' => (bool) $ticket->slaMetric?->is_resolution_breached,
                'is_aging' => !$isClosed && $ageDays !== null && $ageDays >= $agingDays,
                'days_to_close' => $days,
                'age_days' => $ageDays,
                'created_sort' => $created->getTimestamp(),
                'created_at' => $created->format('M j, Y'),
                'closed_at' => $closedAt ? Carbon::parse($closedAt)->format('M j, Y') : null,
            ];
        });
    }

    /**
     * Scope escalations to an entity selection.
     *
     * Primary axis is the STORE's owning company, matching every other dashboard tab.
     * Storeless escalations (a corporate concern with no location) fall back to their
     * own stamped company so a partner's scorecard never silently loses work — this
     * tab measures partner accountability, not the store-health population.
     */
    private function applyEntityScope($query, array $companyIds)
    {
        return $query->where(function ($q) use ($companyIds) {
            $q->whereHas('store', fn ($s) => $s->whereIn('company_id', $companyIds)->where('is_active', true))
                ->orWhere(fn ($noStore) => $noStore->whereNull('store_id')->whereIn('company_id', $companyIds));
        });
    }

    /** The scorecard numbers for any set of escalations. */
    private function metrics(Collection $rows): array
    {
        $total = $rows->count();
        $closedRows = $rows->where('is_closed', true);
        $openRows = $rows->where('is_closed', false);
        $closed = $closedRows->count();
        $open = $openRows->count();

        $durations = $closedRows->pluck('days_to_close')->filter(fn ($d) => $d !== null);
        $breached = $rows->where('is_breached', true)->count();

        return [
            'total' => $total,
            'open' => $open,
            'closed' => $closed,
            'closure_rate' => $total > 0 ? round(($closed / $total) * 100) : 0,
            'avg_days' => $durations->isNotEmpty() ? round($durations->avg(), 1) : null,
            'slowest_days' => $durations->isNotEmpty() ? round($durations->max(), 1) : null,
            'breached' => $breached,
            'on_time' => max(0, $closed - $closedRows->where('is_breached', true)->count()),
            'sla_rate' => $closed > 0
                ? round((($closed - $closedRows->where('is_breached', true)->count()) / $closed) * 100)
                : null,
            'aging_open' => $rows->where('is_aging', true)->count(),
            'oldest_open_days' => $openRows->isNotEmpty() ? round($openRows->max('age_days'), 1) : null,
        ];
    }

    /**
     * Split a set of escalations along the other axis (a partner's brands, or a
     * brand's partners), each slice carrying the same metric shape.
     */
    private function splitBy(Collection $rows, string $axis): array
    {
        return $rows
            ->groupBy($axis === 'brand' ? 'brand_id' : 'vendor_id')
            ->map(fn (Collection $slice) => array_merge($slice->first()[$axis], $this->metrics($slice)))
            ->sortByDesc(fn ($row) => [$row['open'], $row['total']])
            ->values()
            ->all();
    }

    /**
     * The follow-up list: open escalations a partner has been sitting on the longest,
     * oldest first. This is the tab's actionable register.
     */
    private function agingRegister(Collection $facts, int $limit = 25): array
    {
        return $facts
            ->where('is_closed', false)
            ->sortByDesc('age_days')
            ->take($limit)
            ->map(fn ($row) => $this->presentTicket($row))
            ->values()
            ->all();
    }

    /** Shape one escalation for a list/table row. */
    private function presentTicket(array $row): array
    {
        return [
            'id' => $row['id'],
            'key' => $row['key'],
            'title' => $row['title'],
            'status' => $row['status'],
            'priority' => $row['priority'],
            'partner' => $row['partner']['name'],
            'brand' => $row['brand']['name'],
            'store' => $row['store'],
            'parent_key' => $row['parent_key'],
            'parent_url' => $row['parent_id'] ? route('tickets.edit', $row['parent_id']) : null,
            'is_closed' => $row['is_closed'],
            'is_breached' => $row['is_breached'],
            'is_aging' => $row['is_aging'],
            'age_days' => $row['age_days'],
            'days_to_close' => $row['days_to_close'],
            'created_at' => $row['created_at'],
            'closed_at' => $row['closed_at'],
            'url' => route('tickets.edit', $row['id']),
        ];
    }

    private function periodLabel(?int $year, ?int $month): string
    {
        if ($year && $month) {
            return Carbon::create($year, $month, 1)->format('F Y');
        }

        return $year ? (string) $year : 'All time';
    }
}
