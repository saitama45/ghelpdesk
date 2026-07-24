<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DepartmentService;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ServiceVehicleTrip;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BrandHealthService;
use App\Services\StoreReportService;
use App\Support\CompanyContext;
use App\Support\DepartmentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class HubController extends Controller
{
    /** Ticket statuses considered closed (mirrors the dashboard's definition). */
    private const CLOSED_STATUSES = ['resolved', 'closed'];
    /**
     * Section ids that have a hub landing page. Must mirror the group (non-direct)
     * section ids in resources/js/Composables/useModuleRegistry.js. Direct
     * sections (dashboard, projectTracker) never route here.
     */
    private const HUB_SECTIONS = [
        'services',
        'inventory',
        'monitoring',
        'adminTask',
        'references',
        'reports',
        'userManagement',
        'settings',
    ];

    /**
     * Render a section hub. The page itself reads the module registry to draw the
     * tile grid and gates each tile with the shared auth permissions, so the
     * controller only validates the section id and passes it through.
     */
    public function show(Request $request, string $section)
    {
        abort_unless(in_array($section, self::HUB_SECTIONS, true), 404);

        return Inertia::render('Hub/Show', [
            'section' => $section,
            // Section-specific real-data widgets. Only the sections that have a
            // built content panel return data; the rest render tiles only.
            'sectionData' => $this->sectionData($request, $section),
        ]);
    }

    /**
     * Per-section content payload. Returns null for sections that only show the
     * module tile grid.
     */
    private function sectionData(Request $request, string $section): ?array
    {
        return match ($section) {
            'services' => $this->servicesData($request),
            'monitoring' => $this->monitoringData($request),
            'reports' => $this->reportsData($request),
            'adminTask' => $this->adminData($request),
            'references' => $this->referencesData($request),
            default => null,
        };
    }

    /**
     * Administrative hub: today's People & Fleet snapshot (schedules, attendance,
     * service-vehicle trips). Gated on any of the administrative view permissions.
     */
    private function adminData(Request $request): ?array
    {
        $user = $request->user();
        if (! ($user->can('schedules.view') || $user->can('attendance.view') || $user->can('service_vehicle_trips.view'))) {
            return null;
        }

        $today = Carbon::today('Asia/Manila');

        return [
            'eyebrow' => 'People & Fleet Administration · Today',
            'kpis' => [
                ['label' => 'Scheduled Today', 'value' => Schedule::whereDate('start_time', $today)->count(), 'note' => 'Shifts planned', 'tone' => 'blue'],
                ['label' => 'Attendance Logs', 'value' => AttendanceLog::whereDate('log_time', $today)->count(), 'note' => 'Captured today', 'tone' => 'green'],
                ['label' => 'Vehicle Trips', 'value' => ServiceVehicleTrip::whereDate('date_used', $today)->count(), 'note' => 'Booked today', 'tone' => 'blue'],
                ['label' => 'Trips In Progress', 'value' => ServiceVehicleTrip::whereDate('date_used', $today)->whereNotNull('actual_departure_time')->whereNull('actual_arrival_time')->count(), 'note' => 'On the road', 'tone' => 'amber'],
            ],
        ];
    }

    /**
     * References hub: reference/master-data at a glance (counts scope to the active
     * entity for entity-scoped models). Gated on any reference view permission.
     */
    private function referencesData(Request $request): ?array
    {
        $user = $request->user();
        if (! ($user->can('companies.view') || $user->can('stores.view') || $user->can('vendors.view') || $user->can('items.view'))) {
            return null;
        }

        return [
            'eyebrow' => 'Reference Data at a Glance',
            'kpis' => [
                ['label' => 'Companies', 'value' => Company::count(), 'note' => 'Entities & brands', 'tone' => 'blue'],
                ['label' => 'Stores', 'value' => Store::count(), 'note' => 'Active entity', 'tone' => 'green'],
                ['label' => 'Vendors', 'value' => Vendor::count(), 'note' => 'Suppliers', 'tone' => 'blue'],
                ['label' => 'Items', 'value' => Item::count(), 'note' => 'Catalogue', 'tone' => 'green'],
            ],
        ];
    }

    /**
     * Reports hub content: an at-a-glance SLA performance summary (response and
     * resolution compliance for the month). The full per-assignee report lives in
     * the SLA Performance module (a tile below). Gated on reports.sla_performance.
     */
    private function reportsData(Request $request): ?array
    {
        $user = $request->user();
        if (! $user->can('reports.sla_performance')) {
            return null;
        }

        $start = Carbon::now('Asia/Manila')->startOfMonth();
        $end = Carbon::now('Asia/Manila')->endOfMonth();

        // Entity-scoped via the ticket (ActiveEntityScope applies through whereHas).
        $metrics = TicketSlaMetric::query()
            ->whereHas('ticket', fn ($q) => $q->whereBetween('tickets.created_at', [$start, $end]))
            ->get(['id', 'first_response_at', 'resolved_at', 'is_response_breached', 'is_resolution_breached']);

        $total = $metrics->count();
        $responseMet = $metrics->filter(fn ($m) => $m->first_response_at && ! $m->is_response_breached)->count();
        $responsePending = $metrics->filter(fn ($m) => ! $m->first_response_at && ! $m->is_response_breached)->count();
        $resolutionMet = $metrics->filter(fn ($m) => $m->resolved_at && ! $m->is_resolution_breached)->count();
        $resolutionPending = $metrics->filter(fn ($m) => ! $m->resolved_at && ! $m->is_resolution_breached)->count();
        $resolved = $metrics->filter(fn ($m) => (bool) $m->resolved_at)->count();

        $pct = fn ($met, $pending) => $total > 0 ? round(($met / max($total - $pending, 1)) * 100, 1) : 0;

        return [
            'eyebrow' => 'Performance Intelligence · SLA · ' . Carbon::now('Asia/Manila')->format('F Y') . ' · MTD',
            'link' => ['route' => 'reports.sla-performance', 'label' => 'Full SLA report'],
            'kpis' => [
                ['label' => 'Total Tickets', 'value' => $total, 'note' => 'This month', 'tone' => 'blue'],
                ['label' => 'Resolved / Closed', 'value' => $resolved, 'note' => 'Completed', 'tone' => 'green'],
                ['label' => 'Response SLA', 'value' => $pct($responseMet, $responsePending) . '%', 'note' => $responseMet . ' met', 'tone' => 'green'],
                ['label' => 'Resolution SLA', 'value' => $pct($resolutionMet, $resolutionPending) . '%', 'note' => $resolutionMet . ' met', 'tone' => 'green'],
            ],
        ];
    }

    /**
     * Monitoring hub content: the Live Store Health report — the SAME shared
     * StoreHealthReport component and StoreReportService data as the dashboard's
     * Live Store Health tab, so there is one centralised view. Gated on
     * reports.store_health; without it the hub shows the module tiles only.
     */
    private function monitoringData(Request $request): ?array
    {
        $user = $request->user();
        if (! $user->can('reports.store_health')) {
            return null;
        }

        $storeHealth = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'sub_unit' => null,
            'department_id' => null,
            'department_node_id' => null,
            'user_id' => null,
            'store_id' => null,
            'company_ids' => CompanyContext::accessibleCompanyIds($user),
            'split_office' => true,
        ]);

        return [
            'storeHealth' => $storeHealth,
            'entityIds' => CompanyContext::accessibleCompanyIds($user),
        ];
    }

    /**
     * Services hub content. Provider view (viewing your home department) shows a
     * live service-desk: department-scoped ticket KPIs + a recent-requests table.
     * Customer view (visiting another department) shows what that department
     * currently handles. All ticket queries are entity-scoped automatically by
     * the ActiveEntityScope on the Ticket model, and department-scoped here.
     */
    private function servicesData(Request $request): ?array
    {
        $user = $request->user();

        // The provider DESK exposes the department's internal ticket queue, so it
        // requires ticket visibility. The customer view (catalogue + the user's own
        // requests) does NOT — a customer is precisely someone without that access.
        $canSeeTickets = (bool) $user->can('tickets.view');

        $viewedId = DepartmentContext::resolveViewedId($user);
        $accessView = DepartmentContext::accessView($user);
        $department = collect(DepartmentContext::accessibleDepartments($user))
            ->firstWhere('id', $viewedId);

        // No department resolved (e.g. entity has no departments) → tiles only.
        if (! $viewedId) {
            return null;
        }

        $base = fn () => Ticket::query()->where('department_id', $viewedId);
        $monthStart = Carbon::now('Asia/Manila')->startOfMonth();

        // The viewed department's service catalogue (Service Exchange) — what it
        // OFFERS to internal customers, independent of the visitor's module perms.
        $catalog = DepartmentService::query()
            ->where('department_id', $viewedId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'description', 'eta', 'route_name'])
            ->map(fn (DepartmentService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'eta' => $s->eta,
                'route_name' => $s->route_name,
            ])->all();

        $open = (clone $base())->whereNotIn('status', self::CLOSED_STATUSES)->count();
        $resolvedMtd = (clone $base())->whereIn('status', self::CLOSED_STATUSES)
            ->where('updated_at', '>=', $monthStart)->count();

        // Recent activity: the department's most recently updated requests. This is
        // the department's INTERNAL activity, so it is provider-desk data (gated on
        // ticket visibility). Customers see their own requests via requestsTo instead.
        $recentRequests = $canSeeTickets
            ? (clone $base())
                ->with(['assignee:id,name', 'reporter:id,name'])
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Ticket $t) => [
                    'id' => $t->id,
                    'key' => $t->ticket_key,
                    'title' => $t->title,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'requester' => $t->reporter?->name ?? $t->sender_name ?? $t->sender_email,
                ])->all()
            : [];

        // Ticket Board: the department's open requests grouped into status lanes,
        // for the in-page "Ticket Board" sub-tab (prototype parity).
        $boardLanes = [
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'for_schedule' => 'For Schedule',
            'waiting_service_provider' => 'Waiting · Provider',
            'waiting_client_feedback' => 'Waiting · Client',
        ];
        $boardTickets = (clone $base())
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->with(['assignee:id,name', 'reporter:id,name'])
            ->orderByDesc('created_at')
            ->limit(80)
            ->get();
        $board = [];
        foreach ($boardLanes as $status => $label) {
            $laneTickets = $boardTickets->where('status', $status);
            $board[] = [
                'status' => $status,
                'label' => $label,
                'count' => $laneTickets->count(),
                'tickets' => $laneTickets->take(12)->map(fn (Ticket $t) => [
                    'id' => $t->id,
                    'key' => $t->ticket_key,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'assignee' => $t->assignee?->name,
                    'requester' => $t->reporter?->name ?? $t->sender_name ?? $t->sender_email,
                ])->values()->all(),
            ];
        }

        // Inventory Management sub-tab: shown on the TAS desk when the user can see
        // inventory (it is a TAS work tool in the prototype).
        $canInventory = ($department?->code) === 'TAS' && (
            $user->can('assets.view') || $user->can('stock_ins.view') || $user->can('reports.inventory')
        );

        // Brand Health: only meaningful on the TAS desk (services the brands).
        // Pass the FULL BrandHealthService payload so the hub renders the SAME
        // shared BrandHealthReport component as the dashboard's Live Brand Health
        // tab — one centralised view, any change shows in both places.
        $brandHealth = ($canSeeTickets && ($department?->code) === 'TAS')
            ? app(BrandHealthService::class)->build($user)
            : null;

        if ($accessView === 'provider') {
            // The provider desk is the department's internal service desk — without
            // ticket visibility there is nothing to show, so fall back to tiles.
            if (! $canSeeTickets) {
                return null;
            }

            $unassigned = (clone $base())->whereNotIn('status', self::CLOSED_STATUSES)
                ->whereNull('assignee_id')->count();
            $highPriority = (clone $base())->whereNotIn('status', self::CLOSED_STATUSES)
                ->whereIn('priority', ['high', 'urgent'])->count();

            // The desk's request table is internal — only with ticket visibility.
            $requests = $canSeeTickets
                ? (clone $base())->whereNotIn('status', self::CLOSED_STATUSES)
                    ->with(['assignee:id,name', 'reporter:id,name'])
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get()
                    ->map(fn (Ticket $t) => [
                        'id' => $t->id,
                        'key' => $t->ticket_key,
                        'title' => $t->title,
                        'priority' => $t->priority,
                        'status' => $t->status,
                        'assignee' => $t->assignee?->name,
                        'requester' => $t->reporter?->name ?? $t->sender_name ?? $t->sender_email,
                    ])->all()
                : [];

            $kpis = [
                ['label' => 'Open Requests', 'value' => $open, 'note' => 'Assigned to this department', 'tone' => 'blue'],
                ['label' => 'Unassigned', 'value' => $unassigned, 'note' => 'Awaiting an owner', 'tone' => $unassigned > 0 ? 'amber' : 'green'],
                ['label' => 'High / Urgent', 'value' => $highPriority, 'note' => 'Priority attention', 'tone' => $highPriority > 0 ? 'red' : 'green'],
                ['label' => 'Resolved (MTD)', 'value' => $resolvedMtd, 'note' => 'This month', 'tone' => 'green'],
            ];

            return [
                'accessView' => 'provider',
                'department' => $department ? ['id' => $department->id, 'name' => $department->name, 'code' => $department->code] : null,
                'kpis' => $kpis,
                'requests' => $requests,
                'recentRequests' => $recentRequests,
                'brandHealth' => $brandHealth,
                'board' => $board,
                'canInventory' => $canInventory,
                'catalog' => $catalog,
            ];
        }

        // Customer view: the viewed department's catalogue + this user's home
        // department's own requests to it (no internal queue).
        $homeId = DepartmentContext::homeDepartmentId($user);
        $homeName = $homeId
            ? collect(DepartmentContext::accessibleDepartments($user))->firstWhere('id', $homeId)?->name
            : null;

        $requestsTo = null;
        if ($homeId) {
            $homeUserIds = User::where('department_id', $homeId)->pluck('id');
            $toBase = fn () => Ticket::query()->where('department_id', $viewedId)->whereIn('reporter_id', $homeUserIds);
            $requestsTo = [
                'active' => (clone $toBase())->whereNotIn('status', self::CLOSED_STATUSES)->count(),
                'completed_mtd' => (clone $toBase())->whereIn('status', self::CLOSED_STATUSES)->where('updated_at', '>=', $monthStart)->count(),
                'latest' => (clone $toBase())->orderByDesc('updated_at')->limit(3)->get()
                    ->map(fn (Ticket $t) => ['id' => $t->id, 'key' => $t->ticket_key, 'title' => $t->title, 'status' => $t->status])->all(),
            ];
        }

        return [
            'accessView' => 'customer',
            'department' => $department ? ['id' => $department->id, 'name' => $department->name, 'code' => $department->code] : null,
            'homeName' => $homeName,
            'requestsTo' => $requestsTo,
            'kpis' => [
                ['label' => 'Open Requests', 'value' => $open, 'note' => $department?->name . ' is handling', 'tone' => 'blue'],
                ['label' => 'Resolved (MTD)', 'value' => $resolvedMtd, 'note' => 'This month', 'tone' => 'green'],
            ],
            'requests' => [],
            // A visitor does not see the department's internal activity feed; their
            // own requests appear in the requestsTo strip above.
            'recentRequests' => [],
            'brandHealth' => null,
            'board' => $board,
            'canInventory' => false,
            'catalog' => $catalog,
        ];
    }
}
