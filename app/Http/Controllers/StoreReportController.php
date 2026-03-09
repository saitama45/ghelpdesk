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

class StoreReportController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->input('user_id');
        $monthRange = $request->input('month_range'); // format: 'YYYY-MM'

        $usersQuery = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->with('stores');

        if ($userId && $userId !== 'all') {
            $usersQuery->where('id', $userId);
        }

        $usersData = $usersQuery->get()->map(function ($user) use ($monthRange) {
            $stores = $user->stores->map(function ($store) use ($monthRange) {
                $ticketCountQuery = $store->tickets()->where('tickets.status', 'open');
                
                if ($monthRange) {
                    $date = Carbon::parse($monthRange);
                    $ticketCountQuery->whereYear('tickets.created_at', $date->year)
                                     ->whereMonth('tickets.created_at', $date->month);
                }

                $ticketCount = $ticketCountQuery->count();

                return [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => $ticketCount,
                ];
            });

            return [
                'id' => $user->id,
                'name' => $user->name,
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return count($u['stores']) > 0;
        })->values();

        $allUsers = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();

        $thresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');

        // Calculate Summary for North/South Areas
        $summary = [
            'north' => [],
            'south' => []
        ];

        // Fetch all relevant data for summary regardless of user filter
        $allUsersData = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->with('stores')->get();

        for ($i = 1; $i <= 8; $i++) {
            $maxTickets = 0;
            $assignedUser = 'Unassigned';
            
            foreach ($allUsersData as $user) {
                $sectorStores = $user->stores->where('sector', $i);
                if ($sectorStores->isNotEmpty()) {
                    $assignedUser = $user->name;
                    foreach ($sectorStores as $store) {
                        $countQuery = $store->tickets()->where('tickets.status', 'open');
                        if ($monthRange) {
                            $date = Carbon::parse($monthRange);
                            $countQuery->whereYear('tickets.created_at', $date->year)
                                       ->whereMonth('tickets.created_at', $date->month);
                        }
                        $maxTickets = max($maxTickets, $countQuery->count());
                    }
                }
            }

            $sectorData = [
                'sector' => $i,
                'user' => $assignedUser,
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
                'month_range' => $monthRange ?? Carbon::now()->format('Y-m'),
            ]
        ]);
    }

    public function pdf(Request $request)
    {
        $userId = $request->input('user_id');
        $monthRange = $request->input('month_range'); // format: 'YYYY-MM'

        $usersQuery = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->with('stores');

        if ($userId && $userId !== 'all') {
            $usersQuery->where('id', $userId);
        }

        $usersData = $usersQuery->get()->map(function ($user) use ($monthRange) {
            $stores = $user->stores->map(function ($store) use ($monthRange) {
                $ticketCountQuery = $store->tickets()->where('tickets.status', 'open');
                
                if ($monthRange) {
                    $date = Carbon::parse($monthRange);
                    $ticketCountQuery->whereYear('tickets.created_at', $date->year)
                                     ->whereMonth('tickets.created_at', $date->month);
                }

                $ticketCount = $ticketCountQuery->count();

                return (object)[
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'sector' => $store->sector,
                    'area' => $store->area,
                    'ticket_count' => $ticketCount,
                ];
            });

            return (object)[
                'id' => $user->id,
                'name' => $user->name,
                'stores' => $stores,
            ];
        })->filter(function($u) {
            return $u->stores->count() > 0;
        })->values();

        $thresholds = Setting::where('group', 'thresholds')->pluck('value', 'key');

        $summary = [
            'north' => [],
            'south' => []
        ];

        $allUsersData = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->with('stores')->get();

        for ($i = 1; $i <= 8; $i++) {
            $maxTickets = 0;
            $assignedUser = 'Unassigned';
            
            foreach ($allUsersData as $user) {
                $sectorStores = $user->stores->where('sector', $i);
                if ($sectorStores->isNotEmpty()) {
                    $assignedUser = $user->name;
                    foreach ($sectorStores as $store) {
                        $countQuery = $store->tickets()->where('tickets.status', 'open');
                        if ($monthRange) {
                            $date = Carbon::parse($monthRange);
                            $countQuery->whereYear('tickets.created_at', $date->year)
                                       ->whereMonth('tickets.created_at', $date->month);
                        }
                        $maxTickets = max($maxTickets, $countQuery->count());
                    }
                }
            }

            $sectorData = (object)[
                'sector' => $i,
                'user' => $assignedUser,
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
            'monthRange' => $monthRange ? Carbon::parse($monthRange)->format('F Y') : Carbon::now()->format('F Y')
        ]);

        return $pdf->setPaper('a4', 'portrait')->stream('store-health-report.pdf');
    }

    public function getTickets(Request $request, Store $store)
    {
        $monthRange = $request->input('month_range');
        
        $query = $store->tickets()
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at');

        if ($monthRange) {
            $date = Carbon::parse($monthRange);
            $query->whereYear('tickets.created_at', $date->year)
                  ->whereMonth('tickets.created_at', $date->month);
        }

        $tickets = $query->latest()->get();

        return response()->json([
            'store_name' => $store->name,
            'tickets' => $tickets
        ]);
    }
}
