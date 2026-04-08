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
        $subUnits = User::whereNotNull('sub_unit')->distinct()->pluck('sub_unit');

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
        
        $query = $store->tickets()
            ->whereIn('tickets.status', ['open', 'in_progress', 'waiting_service_provider', 'waiting_client_feedback'])
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
