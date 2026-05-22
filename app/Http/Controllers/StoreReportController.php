<?php

namespace App\Http\Controllers;

use App\Services\StoreReportService;
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
    protected $reportService;

    public function __construct(StoreReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.store_health', only: ['index', 'pdf', 'getTickets']),
        ];
    }

    public function index(Request $request)
    {
        $filters = [
            'user_id' => $request->input('user_id'),
            'store_id' => $request->input('store_id'),
            'sub_unit' => $request->input('sub_unit'),
            'as_of_date' => $request->input('as_of_date', Carbon::now()->format('Y-m-d')),
        ];

        $data = $this->reportService->getStoreHealthData($filters);

        $allUsers = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();

        $allStores = Store::where('is_active', true)->orderBy('name')->get();
        $subUnits = User::whereNotNull('org_path')->distinct()->pluck('org_path');

        return Inertia::render('Reports/StoreHealth', [
            'reportData' => $data['reportData'],
            'summary' => $data['summary'],
            'users' => $allUsers,
            'stores' => $allStores,
            'subUnits' => $subUnits,
            'thresholds' => $data['thresholds'],
            'filters' => [
                'user_id' => $filters['user_id'] ?? 'all',
                'store_id' => $filters['store_id'] ?? 'all',
                'sub_unit' => $filters['sub_unit'] ?? 'all',
                'as_of_date' => $filters['as_of_date'],
            ]
        ]);
    }

    public function pdf(Request $request)
    {
        $filters = [
            'user_id' => $request->input('user_id'),
            'store_id' => $request->input('store_id'),
            'sub_unit' => $request->input('sub_unit'),
            'as_of_date' => $request->input('as_of_date', Carbon::now()->format('Y-m-d')),
        ];

        $data = $this->reportService->getStoreHealthData($filters);

        // Convert array data to objects for PDF compatibility
        $reportDataObjects = collect($data['reportData'])->map(function($u) {
            $u['stores'] = collect($u['stores'])->map(fn($s) => (object)$s);
            return (object)$u;
        });

        $summaryObjects = [
            'north' => collect($data['summary']['north'])->map(fn($s) => (object)$s),
            'south' => collect($data['summary']['south'])->map(fn($s) => (object)$s),
        ];

        $pdf = Pdf::loadView('pdf.store-health', [
            'reportData' => $reportDataObjects,
            'summary' => $summaryObjects,
            'thresholds' => $data['thresholds'],
            'asOfDate' => Carbon::parse($filters['as_of_date'])->format('F d, Y'),
            'filters' => [
                'user_id' => $filters['user_id'] ?? 'all',
                'store_id' => $filters['store_id'] ?? 'all',
                'sub_unit' => $filters['sub_unit'] ?? 'all',
            ]
        ]);

        return $pdf->setPaper('a4', 'portrait')->stream('store-health-report.pdf');
    }

    public function getTickets(Request $request, Store $store)
    {
        $asOfDate = $request->input('as_of_date');
        $userId = $request->input('user_id');
        $departmentId = $request->input('department_id');
        $departmentNodeId = $request->input('department_node_id');
        
        $query = $store->tickets()
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->with('assignee:id,name')
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at', 'tickets.assignee_id');

        if ($asOfDate) {
            $query->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        if ($userId && $userId !== 'all') {
            $query->where('tickets.assignee_id', $userId);
        }

        if ($departmentId) {
            $query->whereHas('assignee', function($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        } elseif ($departmentNodeId) {
            $nodeIds = array_merge([(int) $departmentNodeId], \App\Models\DepartmentNode::getAllDescendantIds((int) $departmentNodeId));
            $query->whereHas('assignee', function($q) use ($nodeIds) {
                $q->whereIn('department_node_id', $nodeIds);
            });
        }

        $tickets = $query->latest()->get();

        return response()->json([
            'store_name' => $store->name,
            'tickets' => $tickets
        ]);
    }

    public function getSectorTickets(Request $request, $sector)
    {
        $asOfDate = $request->input('as_of_date');
        $userId = $request->input('user_id');
        $storeId = $request->input('store_id');
        $subUnit = $request->input('sub_unit');
        $departmentId = $request->input('department_id');
        $departmentNodeId = $request->input('department_node_id');
        
        $query = Ticket::whereHas('store', function($q) use ($sector) {
                $q->where('sector', $sector);
            })
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->with(['store:id,name,code', 'assignee:id,name'])
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at', 'tickets.store_id', 'tickets.assignee_id');

        if ($asOfDate) {
            $query->whereDate('tickets.created_at', '<=', $asOfDate);
        }

        if ($userId && $userId !== 'all') {
            $query->where('tickets.assignee_id', $userId);
        }

        if ($storeId && $storeId !== 'all') {
            $query->where('tickets.store_id', $storeId);
        }

        if ($departmentId) {
            $query->whereHas('assignee', function($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        } elseif ($departmentNodeId) {
            $nodeIds = array_merge([(int) $departmentNodeId], \App\Models\DepartmentNode::getAllDescendantIds((int) $departmentNodeId));
            $query->whereHas('assignee', function($q) use ($nodeIds) {
                $q->whereIn('department_node_id', $nodeIds);
            });
        } elseif ($subUnit && $subUnit !== 'all') {
            $query->whereHas('assignee', function($q) use ($subUnit) {
                $q->where('org_path', 'like', '%'.$subUnit.'%');
            });
        }

        $tickets = $query->latest()->get();

        return response()->json([
            'store_name' => 'Sector ' . $sector,
            'tickets' => $tickets
        ]);
    }
}
