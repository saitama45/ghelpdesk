<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Models\Setting;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StoreReportService
{
    public function getStoreHealthData(array $filters)
    {
        $userId = $filters['user_id'] ?? 'all';
        $storeId = $filters['store_id'] ?? 'all';
        $subUnit = $filters['sub_unit'] ?? 'all';
        $asOfDate = $filters['as_of_date'] ?? Carbon::now()->format('Y-m-d');

        // Query active tickets that have an assignee
        $ticketsQuery = Ticket::whereIn('status', ['open', 'in_progress', 'waiting_service_provider', 'waiting_client_feedback'])
            ->whereNotNull('assignee_id');

        if ($asOfDate) {
            $ticketsQuery->whereDate('created_at', '<=', $asOfDate);
        }

        if ($storeId && $storeId !== 'all') {
            $ticketsQuery->where('store_id', $storeId);
        }

        if ($subUnit && $subUnit !== 'all') {
            $ticketsQuery->whereHas('assignee', function($q) use ($subUnit) {
                $q->where('sub_unit', $subUnit);
            });
        }

        // Apply user filter to report data
        $displayTicketsQuery = clone $ticketsQuery;
        if ($userId && $userId !== 'all') {
            $displayTicketsQuery->where('assignee_id', $userId);
        }

        $activeTickets = $displayTicketsQuery->with(['assignee', 'store'])->get();

        $reportData = $activeTickets->groupBy('assignee_id')->map(function ($tickets, $assigneeId) {
            $assignee = $tickets->first()->assignee;
            $stores = $tickets->groupBy('store_id')->map(function ($storeTickets, $storeId) {
                $store = $storeTickets->first()->store;
                if (!$store) return null;
                return [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => $storeTickets->count(),
                ];
            })->filter()->values();

            return [
                'id' => $assigneeId,
                'name' => $assignee?->name ?? 'Unknown',
                'sub_unit' => $assignee?->sub_unit,
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return count($u['stores']) > 0;
        })->values();

        // Summary logic
        $allStores = Store::where('is_active', true)->orderBy('name')->get();
        $summary = [
            'north' => [],
            'south' => []
        ];

        $summaryTickets = $ticketsQuery->with(['assignee', 'store'])->get();

        for ($i = 1; $i <= 8; $i++) {
            $sectorStores = $allStores->where('sector', $i);
            $maxTickets = 0;
            $sectorUserNames = [];

            foreach ($sectorStores as $store) {
                $storeTickets = $summaryTickets->where('store_id', $store->id);
                $count = $storeTickets->count();
                $maxTickets = max($maxTickets, $count);
                
                if ($count > 0) {
                    $assigneeNames = $storeTickets->pluck('assignee.name')->filter()->unique()->toArray();
                    foreach ($assigneeNames as $name) {
                        $sectorUserNames[] = $name;
                    }
                }
            }

            $sectorData = [
                'sector' => $i,
                'user' => empty($sectorUserNames) ? 'Unassigned' : implode(', ', array_unique($sectorUserNames)),
                'max_tickets' => $maxTickets
            ];

            if ($i <= 4) {
                $summary['north'][] = $sectorData;
            } else {
                $summary['south'][] = $sectorData;
            }
        }

        // Thresholds
        $allThresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');
        $thresholds = $this->getThresholdsForSubUnit($subUnit, $allThresholds);

        return [
            'reportData' => $reportData,
            'summary' => $summary,
            'thresholds' => $thresholds,
        ];
    }

    private function getThresholdsForSubUnit($subUnit, $allThresholds)
    {
        $colors = ['green', 'yellow', 'orange', 'red'];
        $suffixes = ['min', 'max', 'label'];
        $thresholds = [];

        $subUnitSlug = null;
        if ($subUnit && $subUnit !== 'all') {
            $subUnitSlug = strtolower((string)$subUnit);
            $subUnitSlug = preg_replace('/\s+/', '_', $subUnitSlug);
            $subUnitSlug = preg_replace('/[^\w-]+/', '', $subUnitSlug);
            $subUnitSlug = preg_replace('/--+/', '_', $subUnitSlug);
            $subUnitSlug = trim($subUnitSlug, '-');
        }

        foreach ($colors as $color) {
            foreach ($suffixes as $suffix) {
                if ($color === 'red' && $suffix === 'max') continue;
                
                $globalKey = "threshold_{$color}_{$suffix}";
                $subUnitKey = $subUnitSlug ? "threshold_{$color}_{$suffix}_{$subUnitSlug}" : null;
                
                $val = null;
                if ($subUnitKey && isset($allThresholds[$subUnitKey])) {
                    $val = $allThresholds[$subUnitKey];
                }
                
                if ($val === null && isset($allThresholds[$globalKey])) {
                    $val = $allThresholds[$globalKey];
                }

                $thresholds[$globalKey] = $val;
            }
        }

        return $thresholds;
    }
}
