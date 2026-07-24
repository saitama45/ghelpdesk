<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Store;
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

        // The WCF confirmation register: the actual tickets awaiting brand confirmation.
        $wcfByBrand = $this->wcfRegister($allStoreIds, $storesByBrand, $asOfDate, $agingDays);

        $brandRows = $brands->map(function (Company $brand) use ($storesByBrand, $statusCountsByStore, $bands, $wcfByBrand) {
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
                'wcf_register' => $wcfByBrand->get($brand->id, collect())->values()->all(),
            ];
        })->values();

        return [
            'as_of' => Carbon::parse($asOfDate)->format('M j, Y'),
            'aging_days' => $agingDays,
            'thresholds' => $this->thresholdLabels($bands),
            'can_close' => (bool) $user?->can('tickets.close'),
            'can_reopen' => (bool) $user?->can('tickets.edit'),
            'totals' => $this->aggregateTotals($brandRows),
            'brands' => $brandRows->all(),
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
