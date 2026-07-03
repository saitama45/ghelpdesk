<?php

namespace App\Http\Controllers;

use App\Services\StoreReportService;
use App\Models\Store;
use App\Models\User;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\OrganizationReferenceService;
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
    protected $organizationReferenceService;

    public function __construct(StoreReportService $reportService, OrganizationReferenceService $organizationReferenceService)
    {
        $this->reportService = $reportService;
        $this->organizationReferenceService = $organizationReferenceService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.store_health', only: ['index', 'pdf', 'getTickets']),
        ];
    }

    public function index(Request $request)
    {
        $departmentId = $request->input('department_id');
        $departmentNodeId = $request->input('department_node_id');

        if (
            !$request->boolean('skip_default_department')
            && !$request->has('department_id')
            && !$request->has('department_node_id')
        ) {
            $user = $request->user();
            $departmentId = $user?->department_id
                ?? optional($user?->loadMissing('departmentNode')->departmentNode)->department_id;
        }

        $selectedDepartmentLabel = $this->selectedDepartmentLabel($departmentId, $departmentNodeId);

        $filters = [
            'user_id' => $request->input('user_id'),
            'store_id' => $request->input('store_id'),
            'sub_unit' => $selectedDepartmentLabel,
            'department_id' => $departmentId,
            'department_node_id' => $departmentNodeId,
            'as_of_date' => $request->input('as_of_date', Carbon::now()->format('Y-m-d')),
        ];

        $data = $this->reportService->getStoreHealthData($filters);

        $allUsers = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name', 'org_path', 'department_id', 'department_node_id')->orderBy('name')->get();

        $allStores = Store::where('is_active', true)->orderBy('name')->get();
        $subUnits = User::whereNotNull('org_path')->distinct()->pluck('org_path');
        $hierarchicalDepartments = $this->organizationReferenceService->tree(true);

        return Inertia::render('Reports/StoreHealth', [
            'reportData' => $data['reportData'],
            'summary' => $data['summary'],
            'users' => $allUsers,
            'stores' => $allStores,
            'subUnits' => $subUnits,
            'hierarchicalDepartments' => $hierarchicalDepartments,
            'thresholds' => $data['thresholds'],
            'entityHealth' => $data['entityHealth'],
            'filters' => [
                'user_id' => $filters['user_id'] ?? 'all',
                'store_id' => $filters['store_id'] ?? 'all',
                'sub_unit' => $filters['sub_unit'] ?? 'all',
                'department_id' => $filters['department_id'],
                'department_node_id' => $filters['department_node_id'],
                'as_of_date' => $filters['as_of_date'],
            ]
        ]);
    }

    public function pdf(Request $request)
    {
        $departmentId = $request->input('department_id');
        $departmentNodeId = $request->input('department_node_id');
        $selectedDepartmentLabel = $this->selectedDepartmentLabel($departmentId, $departmentNodeId);

        $filters = [
            'user_id' => $request->input('user_id'),
            'store_id' => $request->input('store_id'),
            'sub_unit' => $selectedDepartmentLabel,
            'department_id' => $departmentId,
            'department_node_id' => $departmentNodeId,
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
            'ct' => collect($data['summary']['ct'])->map(fn($s) => (object)$s),
            'is_ct_mode' => $data['summary']['is_ct_mode'],
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
                'department_id' => $filters['department_id'],
                'department_node_id' => $filters['department_node_id'],
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
            ->with(['store:id,name,code,sector,is_active', 'assignee:id,name,department_node_id'])
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at', 'tickets.store_id', 'tickets.assignee_id');

        if ($asOfDate) {
            $query->whereDate('tickets.created_at', '<=', $asOfDate);
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

        $sectorAssignments = $this->sectorAssignments();
        $tickets = $query->latest()->get()
            ->filter(fn ($ticket) => $this->ticketHasReportableStoreSector($ticket))
            ->filter(fn ($ticket) => $this->ticketMatchesDisplayUser($ticket, $userId, $sectorAssignments))
            ->values();

        return response()->json([
            'store_name' => $store->name,
            'tickets' => $tickets
        ]);
    }

    public function getSectorTickets(Request $request, $sector)
    {
        $asOfDate = $request->input('as_of_date');
        $storeId = $request->input('store_id');
        $userId = $request->input('user_id');
        $departmentId = $request->input('department_id');
        $departmentNodeId = $request->input('department_node_id');
        
        $query = Ticket::whereHas('store', function($q) use ($sector) {
                $q->where('sector', $sector)
                    ->where('is_active', true);
            })
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->with(['store:id,name,code,sector,is_active', 'assignee:id,name,department_node_id'])
            ->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at', 'tickets.store_id', 'tickets.assignee_id');

        if ($asOfDate) {
            $query->whereDate('tickets.created_at', '<=', $asOfDate);
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
        }

        $sectorAssignments = $this->sectorAssignments();
        $tickets = $query->latest()->get()
            ->filter(fn ($ticket) => $this->ticketHasReportableStoreSector($ticket))
            ->filter(fn ($ticket) => $this->ticketMatchesDisplayUser($ticket, $userId, $sectorAssignments))
            ->values();

        return response()->json([
            'store_name' => 'Sector ' . $sector,
            'tickets' => $tickets
        ]);
    }

    private function selectedDepartmentLabel($departmentId, $departmentNodeId): string
    {
        if ($departmentNodeId) {
            return $this->organizationReferenceService->payloadFromNodeId((int) $departmentNodeId)['org_path'] ?? 'all';
        }

        if ($departmentId) {
            return \App\Models\Department::find($departmentId)?->name ?? 'all';
        }

        return 'all';
    }

    private function sectorAssignments(): array
    {
        $sectorNodes = \App\Models\DepartmentNode::query()
            ->where('name', 'like', 'Sector %')
            ->get();

        $byUserId = [];

        foreach ($sectorNodes as $node) {
            if (!preg_match('/^Sector\s+(\d+)$/i', $node->name, $matches)) {
                continue;
            }

            $sector = (int) $matches[1];
            $nodeIds = array_merge([$node->id], \App\Models\DepartmentNode::getAllDescendantIds($node->id));
            $userIds = User::query()
                ->whereIn('department_node_id', $nodeIds)
                ->pluck('id');

            foreach ($userIds as $userId) {
                $byUserId[$userId] ??= [];
                $byUserId[$userId][] = $sector;
            }
        }

        foreach ($byUserId as $userId => $sectors) {
            $byUserId[$userId] = array_values(array_unique($sectors));
        }

        return $byUserId;
    }

    /**
     * Drill-downs mirror the sector summary: a ticket is reportable purely on the
     * basis of its active store's configured sector, regardless of which sector the
     * assignee belongs to.
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

        $ownedSectors = $sectorAssignments[(int) $userId] ?? [];

        if (!empty($ownedSectors)) {
            return in_array((int) $ticket->store->sector, $ownedSectors, true);
        }

        return (int) $ticket->assignee_id === (int) $userId;
    }
}
