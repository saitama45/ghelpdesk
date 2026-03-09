<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StoreReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.store_health', only: ['index', 'pdf', 'getTickets']),
        ];
    }

    public function index(Request $request)
    {
        $userId = $request->input('user_id');
        $asOfDate = $request->input('as_of_date', Carbon::now()->format('Y-m-d'));

        // Query active tickets that have an assignee
        $ticketsQuery = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])
            ->whereNotNull('assignee_id');

        if ($asOfDate) {
            $ticketsQuery->whereDate('created_at', '<=', $asOfDate);
        }

        // Apply user filter to report data
        $displayTicketsQuery = clone $ticketsQuery;
        if ($userId && $userId !== 'all') {
            $displayTicketsQuery->where('assignee_id', $userId);
        }

        $activeTickets = $displayTicketsQuery->with(['assignee', 'store'])->get();

        $usersData = $activeTickets->groupBy('assignee_id')->map(function ($tickets, $assigneeId) {
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
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return count($u['stores']) > 0;
        })->values();

        $allUsers = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();

        $thresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');

        // Calculate Summary for North/South Areas (Sectors 1-8)
        $summary = [
            'north' => [],
            'south' => []
        ];

        // For summary, we need ALL active tickets regardless of user filter
        $summaryTickets = $ticketsQuery->with(['assignee', 'store'])->get();
        $allStores = Store::where('is_active', true)->get();

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

        return Inertia::render('Reports/StoreHealth', [
            'reportData' => $usersData,
            'summary' => $summary,
            'users' => $allUsers,
            'thresholds' => $thresholds,
            'filters' => [
                'user_id' => $userId ?? 'all',
                'as_of_date' => $asOfDate,
            ]
        ]);
    }

    public function pdf(Request $request)
    {
        $userId = $request->input('user_id');
        $asOfDate = $request->input('as_of_date', Carbon::now()->format('Y-m-d'));

        // Query active tickets that have an assignee
        $ticketsQuery = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])
            ->whereNotNull('assignee_id');

        if ($asOfDate) {
            $ticketsQuery->whereDate('created_at', '<=', $asOfDate);
        }

        // Apply user filter to report data
        $displayTicketsQuery = clone $ticketsQuery;
        if ($userId && $userId !== 'all') {
            $displayTicketsQuery->where('assignee_id', $userId);
        }

        $activeTickets = $displayTicketsQuery->with(['assignee', 'store'])->get();

        $usersData = $activeTickets->groupBy('assignee_id')->map(function ($tickets, $assigneeId) {
            $assignee = $tickets->first()->assignee;
            $stores = $tickets->groupBy('store_id')->map(function ($storeTickets, $storeId) {
                $store = $storeTickets->first()->store;
                if (!$store) return null;
                return (object)[
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => $storeTickets->count(),
                ];
            })->filter()->values();

            return (object)[
                'id' => $assigneeId,
                'name' => $assignee?->name ?? 'Unknown',
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return count($u->stores) > 0;
        })->values();

        $thresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');

        // Calculate Summary for North/South Areas (Sectors 1-8)
        $summary = [
            'north' => [],
            'south' => []
        ];

        // For summary, we need ALL active tickets regardless of user filter
        $summaryTickets = $ticketsQuery->with(['assignee', 'store'])->get();
        $allStores = Store::where('is_active', true)->get();

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

            $sectorData = (object)[
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

        $pdf = Pdf::loadView('pdf.store-health', [
            'reportData' => $usersData,
            'summary' => $summary,
            'thresholds' => $thresholds,
            'asOfDate' => Carbon::parse($asOfDate)->format('F d, Y')
        ]);

        return $pdf->setPaper('a4', 'portrait')->stream('store-health-report.pdf');
    }

    public function getTickets(Request $request, Store $store)
    {
        $asOfDate = $request->input('as_of_date');
        $userId = $request->input('user_id');
        
        $query = $store->tickets()
            ->whereIn('tickets.status', ['open', 'in_progress', 'waiting'])
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at');

        if ($asOfDate) {
            $query->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        if ($userId && $userId !== 'all') {
            $query->where('tickets.assignee_id', $userId);
        }

        $tickets = $query->latest()->get();

        return response()->json([
            'store_name' => $store->name,
            'tickets' => $tickets
        ]);
    }
}
