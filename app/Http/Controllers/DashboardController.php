<?php

namespace App\Http\Controllers;

use App\Models\DepartmentNode;
use App\Models\Item;
use App\Models\Scopes\ActiveEntityScope;
use App\Services\StoreReportService;
use App\Services\OrganizationReferenceService;
use App\Support\CompanyContext;
use App\Models\Project;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DashboardController extends Controller
{
    protected $reportService;
    protected $organizationReferenceService;

    public function __construct(StoreReportService $reportService, OrganizationReferenceService $organizationReferenceService)
    {
        $this->reportService = $reportService;
        $this->organizationReferenceService = $organizationReferenceService;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $year = $request->input('year');
        $month = $request->input('month');
        // Department filtering was removed from the dashboard — every widget spans all
        // departments. Any incoming department params are intentionally ignored so the
        // results are never narrowed by the viewer's (or a selected) department.
        $departmentIdFilter = null;
        $departmentNodeIdFilter = null;
        $userIdFilter = $request->input('user_id', 'all');
        $storeIdFilter = $request->input('store_id', 'all');
        $pipelineYear = (int) $request->input('pipeline_year', (int) date('Y'));
        $pipelineStatus = trim((string) $request->input('pipeline_status', ''));
        $pipelineType = trim((string) $request->input('pipeline_type', ''));
        $selectedSubUnitLabel = 'all';

        // Entity/Company filter. Defaults to the active sidebar entity; permitted
        // users can widen it to any subset of their accessible entities. Resolved
        // early so every widget (incl. store health) shares the same entity scope.
        $canEntityFilter = $user->can('dashboard.filter_entity');
        $selectedEntityIds = (array) $request->input('entity_ids', []);
        $effectiveCompanyIds = CompanyContext::effectiveEntityIds($user, $selectedEntityIds, $canEntityFilter);

        // We take explicit control of entity scoping (bypassing the active-entity
        // global scope) so the multi-entity selection works across every widget.
        $allowedCompanyIds = collect($effectiveCompanyIds);

        // Base query (Ticket Flow Board + every lazy tab derive from this).
        $query = Ticket::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereNull('parent_id');

        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        } elseif ($allowedCompanyIds->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            $this->applyEntityScope($query, $allowedCompanyIds);
        }

        $filteredQuery = clone $query;
        if ($year) {
            $filteredQuery->whereYear('created_at', $year);
        }
        if ($month) {
            $filteredQuery->whereMonth('created_at', $month);
        }

        $currentYear = date('Y');
        $years = range($currentYear, $currentYear - 3);
        $months = [
            ['id' => 1, 'name' => 'January'], ['id' => 2, 'name' => 'February'],
            ['id' => 3, 'name' => 'March'], ['id' => 4, 'name' => 'April'],
            ['id' => 5, 'name' => 'May'], ['id' => 6, 'name' => 'June'],
            ['id' => 7, 'name' => 'July'], ['id' => 8, 'name' => 'August'],
            ['id' => 9, 'name' => 'September'], ['id' => 10, 'name' => 'October'],
            ['id' => 11, 'name' => 'November'], ['id' => 12, 'name' => 'December'],
        ];

        // Memoize multi-prop builders so a partial reload that requests several
        // keys at once (e.g. the whole Overview tab) computes them only once.
        $kanban = null;
        $kanbanData = function () use (&$kanban, $filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter) {
            return $kanban ??= $this->buildKanban($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter);
        };
        $overview = null;
        $overviewData = function () use (&$overview, $query, $filteredQuery, $user, $allowedCompanyIds) {
            return $overview ??= $this->buildOverview($query, $filteredQuery, $user, $allowedCompanyIds);
        };

        return Inertia::render('Dashboard', [
            // Filter-bar data — eager closures: present on first paint, skipped on
            // tab-only partial reloads.
            'users' => fn () => \App\Models\User::active()->whereHas('roles', fn ($q) => $q->where('is_assignable', true))
                ->select('id', 'name', 'org_path', 'department_id', 'department_node_id')->orderBy('name')->get(),
            'stores' => fn () => \App\Models\Store::where('is_active', true)->orderBy('name')->get(),
            'subUnits' => fn () => \App\Models\User::whereNotNull('org_path')->distinct()->pluck('org_path'),
            'hierarchicalDepartments' => fn () => $this->organizationReferenceService->tree(true),
            'years' => $years,
            'months' => $months,
            'filters' => [
                'year' => (int)$year ?: null,
                'month' => (int)$month ?: null,
                'department_id' => $departmentIdFilter ? (int) $departmentIdFilter : null,
                'department_node_id' => $departmentNodeIdFilter ? (int) $departmentNodeIdFilter : null,
                'user_id' => $userIdFilter,
                'store_id' => $storeIdFilter,
                'pipeline_year' => $pipelineYear,
                'pipeline_status' => $pipelineStatus ?: null,
                'pipeline_type' => $pipelineType ?: null,
            ],
            'entityFilter' => fn () => [
                'enabled' => $canEntityFilter,
                'options' => CompanyContext::accessibleCompanies($user)
                    ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'code' => $c->code])
                    ->values(),
                'selected' => array_values($effectiveCompanyIds),
            ],

            // Default tab — Ticket Flow Board (loads on first paint; fetchable via partial).
            'kanbanReport' => fn () => $kanbanData()['report'],
            'kanbanProjects' => fn () => $kanbanData()['projects'],

            // Lazy tabs — excluded from the initial load, fetched on first tab click.
            'storeHealth' => Inertia::optional(fn () => $this->buildStoreHealth($selectedSubUnitLabel, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter, $effectiveCompanyIds)),
            'ticketCharts' => Inertia::optional(fn () => $this->buildTicketCharts($filteredQuery, $user, $effectiveCompanyIds, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter)),
            'leaderboard' => Inertia::optional(fn () => $this->buildLeaderboard($filteredQuery, $year ? (int) $year : null, $month ? (int) $month : null, $departmentIdFilter, $departmentNodeIdFilter ? (int) $departmentNodeIdFilter : null, $userIdFilter, $storeIdFilter, $effectiveCompanyIds)),
            'stats' => Inertia::optional(fn () => $overviewData()['stats']),
            'recentTickets' => Inertia::optional(fn () => $overviewData()['recentTickets']),
            'myTickets' => Inertia::optional(fn () => $overviewData()['myTickets']),
            'recentActivity' => Inertia::optional(fn () => $overviewData()['recentActivity']),
            'alarmedWaitingTickets' => Inertia::optional(fn () => $overviewData()['alarmedWaitingTickets']),
            'urgentTickets' => Inertia::optional(fn () => $overviewData()['urgentTickets']),
            'totalTicketsList' => Inertia::optional(fn () => $overviewData()['totalTicketsList']),
            'openTicketsList' => Inertia::optional(fn () => $overviewData()['openTicketsList']),
            'newTicketsList' => Inertia::optional(fn () => $overviewData()['newTicketsList']),
            'closedTicketsList' => Inertia::optional(fn () => $overviewData()['closedTicketsList']),
            // CASA Pipeline is the default landing tab, so its data must be present
            // on the initial paint. Guarded by permission so project data is never
            // sent to users who can't view projects (the tab is hidden for them).
            'storePipeline' => fn () => $user->can('projects.view')
                ? $this->buildStorePipeline($pipelineYear, $effectiveCompanyIds, $pipelineStatus, $pipelineType)
                : null,
        ]);
    }

    public function chartTickets(Request $request)
    {
        $query = $this->dashboardChartTicketQuery($request);
        $total = (clone $query)->count();

        $tickets = $query
            ->with(['assignee:id,name', 'company:id,name', 'store:id,name,code', 'item:id,name,concern_type'])
            ->latest('created_at')
            ->limit(2000)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'company' => $ticket->company?->name,
                'store' => $ticket->store ? trim(($ticket->store->code ? $ticket->store->code . ' - ' : '') . $ticket->store->name) : null,
                'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                'concern_type' => $ticket->item?->concern_type,
                'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'tickets' => $tickets,
            'count' => $total,
            'shown_count' => $tickets->count(),
        ]);
    }

    public function exportChartTickets(Request $request)
    {
        $tickets = $this->dashboardChartTicketQuery($request)
            ->with(['assignee:id,name', 'company:id,name', 'store:id,name,code', 'item:id,name,concern_type'])
            ->latest('created_at')
            ->limit(5000)
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Chart Tickets');
        foreach (['Ticket ID', 'Title', 'Status', 'Concern Type', 'Company', 'Location', 'Assignee', 'Created At'] as $index => $header) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . '1', $header);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        foreach ($tickets as $index => $ticket) {
            $row = $index + 2;
            $sheet->fromArray([
                $ticket->ticket_key,
                $ticket->title,
                str_replace('_', ' ', $ticket->status),
                $ticket->item?->concern_type,
                $ticket->company?->name,
                $ticket->store ? trim(($ticket->store->code ? $ticket->store->code . ' - ' : '') . $ticket->store->name) : null,
                $ticket->assignee?->name ?? 'Unassigned',
                $ticket->created_at?->format('Y-m-d H:i:s'),
            ], null, "A{$row}");
        }
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $bucket = $request->input('bucket');
        $concern = $request->input('concern_type');
        $filename = strtolower(trim(($concern ? str_replace(' ', '_', $concern) . '_' : '') . $bucket . '_tickets_' . date('Y-m-d_His'), '_')) . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function dashboardChartTicketQuery(Request $request)
    {
        $validated = $request->validate([
            'bucket' => ['required', 'in:all,open,closed'],
            'concern_type' => ['nullable', 'in:Incident,Service Request,Problem'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'department_id' => ['nullable', 'integer'],
            'department_node_id' => ['nullable', 'integer'],
            'user_id' => ['nullable'],
            'store_id' => ['nullable'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
        ]);

        $user = $request->user();
        $effectiveCompanyIds = CompanyContext::effectiveEntityIds(
            $user,
            (array) $request->input('entity_ids', []),
            $user->can('dashboard.filter_entity')
        );

        $query = Ticket::withoutGlobalScope(ActiveEntityScope::class)->whereNull('parent_id');
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        } elseif (empty($effectiveCompanyIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $this->applyEntityScope($query, $effectiveCompanyIds);
        }

        $query->when($validated['year'] ?? null, fn ($q, $year) => $q->whereYear('created_at', $year))
            ->when($validated['month'] ?? null, fn ($q, $month) => $q->whereMonth('created_at', $month));

        $this->applyAssigneeScopeFilters(
            $query,
            $validated['department_id'] ?? null,
            $validated['department_node_id'] ?? null,
            $validated['user_id'] ?? 'all',
            $validated['store_id'] ?? 'all'
        );

        if ($validated['bucket'] === 'closed') {
            $query->whereIn('status', ['resolved', 'closed']);
        } elseif ($validated['bucket'] === 'open') {
            $query->whereNotIn('status', ['resolved', 'closed']);
        }
        if (!empty($validated['concern_type'])) {
            $query->whereHas('item', fn ($q) => $q->where('concern_type', $validated['concern_type']));
        }

        return $query;
    }

    /**
     * Store / Project Pipeline timeline for a given year (lazy dashboard tab).
     *
     * Derived live from the Projects table: every project whose target_go_live
     * falls in the selected year is placed on that month; projects with no
     * target date become "Standby" backlog cards. Cards are colored by status.
     */
    private function buildStorePipeline(int $year, array $effectiveCompanyIds = [], string $statusFilter = '', string $typeFilter = ''): array
    {
        $projects = Project::query()
            ->with('store:id,name,code,brand,company_id')
            ->where(function ($query) use ($year) {
                $query->whereYear('target_go_live', $year)
                    ->orWhereNull('target_go_live');
            })
            // Entity scope: store-based projects are limited to the selected
            // entities; org-wide projects with no store stay visible.
            ->when(!empty($effectiveCompanyIds), function ($query) use ($effectiveCompanyIds) {
                $query->where(function ($sub) use ($effectiveCompanyIds) {
                    $sub->whereNull('store_id')
                        ->orWhereHas('store', fn ($s) => $s->whereIn('company_id', $effectiveCompanyIds));
                });
            })
            ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
            ->when($typeFilter !== '', fn ($query) => $query->where('project_type', $typeFilter))
            ->orderByRaw('CASE WHEN target_go_live IS NULL THEN 1 ELSE 0 END')
            ->orderBy('target_go_live')
            ->orderBy('name')
            ->get();

        $mapCard = function (Project $project) {
            $store = $project->store;
            $goLive = $project->target_go_live;

            return [
                'id'             => $project->id,
                'name'           => $project->name,
                'label'          => $store?->code ?: $project->name,
                'store_name'     => $store?->name,
                'store_code'     => $store?->code,
                'store_brand'    => $store?->brand,
                'type'           => $project->project_type,
                'status'         => $project->status ?: 'Planning',
                'target_go_live' => $goLive?->format('Y-m-d'),
                'go_live_label'  => $goLive?->format('M j'),
                'day'            => $goLive ? (int) $goLive->format('j') : null,
                'url'            => route('projects.show', $project->id),
            ];
        };

        $dated = $projects->filter(fn (Project $p) => $p->target_go_live !== null);

        $months = collect(range(1, 12))->map(function (int $month) use ($dated, $mapCard) {
            $cards = $dated
                ->filter(fn (Project $p) => (int) $p->target_go_live->format('n') === $month)
                ->sortBy(fn (Project $p) => $p->target_go_live->timestamp)
                ->map($mapCard)
                ->values();

            return [
                'month' => $month,
                'label' => Carbon::create(2000, $month, 1)->format('M'),
                'cards' => $cards,
            ];
        })->values();

        // Standby = undated projects that are still active (not finished/cancelled).
        $standby = $projects
            ->filter(fn (Project $p) => $p->target_go_live === null
                && !in_array($p->status, ['Completed', 'Cancelled'], true))
            ->map($mapCard)
            ->values();

        // Status legend + counts across this year's dated cards.
        $statusLegend = $dated
            ->groupBy(fn (Project $p) => $p->status ?: 'Planning')
            ->map(fn ($group, $status) => ['status' => (string) $status, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values();

        // Years that actually have dated projects, plus current & selected year.
        $availableYears = Project::query()
            ->whereNotNull('target_go_live')
            ->selectRaw('YEAR(target_go_live) as y')
            ->distinct()
            ->pluck('y')
            ->map(fn ($y) => (int) $y);

        $availableYears = $availableYears
            ->push($year)
            ->push((int) date('Y'))
            ->unique()
            ->sortDesc()
            ->values();

        return [
            'year'           => $year,
            'availableYears' => $availableYears,
            'months'         => $months,
            'standby'        => $standby,
            'statusLegend'   => $statusLegend,
            'totals'         => [
                'total'   => $dated->count() + $standby->count(),
                'dated'   => $dated->count(),
                'standby' => $standby->count(),
            ],
            // Active filter selections + the option lists that drive the dropdowns.
            'statusFilter'   => $statusFilter,
            'typeFilter'     => $typeFilter,
            'statusOptions'  => ['Planning', 'Pending', 'In Progress', 'Delayed', 'Completed', 'Cancelled'],
            'typeOptions'    => Project::projectTypes(),
        ];
    }

    /**
     * Live Store Health data (lazy dashboard tab).
     */
    private function buildStoreHealth($selectedSubUnitLabel, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter, array $effectiveCompanyIds)
    {
        return $this->reportService->getStoreHealthData([
            'as_of_date' => Carbon::now()->format('Y-m-d'),
            'sub_unit' => $selectedSubUnitLabel,
            'department_id' => $departmentIdFilter,
            'department_node_id' => $departmentNodeIdFilter,
            'user_id' => $userIdFilter,
            'store_id' => $storeIdFilter,
            'company_ids' => $effectiveCompanyIds,
            // Carve corporate-office stores (class = "Office") into their own block so
            // the dashboard can show Store Sectors vs Corporate Office Sector sub-tabs.
            'split_office' => true,
        ]);
    }

    /**
     * Overall Open vs Closed + Per Store Brand + Per Corporate Office + Concern Type charts (lazy dashboard tab).
     */
    /**
     * Scope a ticket query to an entity selection by the STORE's owning company,
     * not the ticket's stamped company_id. A ticket counts for the entity that owns
     * the store it sits on, regardless of how the ticket was stamped. Tickets with
     * NO store are intentionally excluded so every dashboard tab counts the exact
     * same universe as Live Store Health (which can only report on-store tickets),
     * making the tabs tally precisely. See [[project_entity_health_heatmap]].
     */
    private function applyEntityScope($query, $companyIds)
    {
        return $query->whereHas('store', fn ($s) => $s
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true));
    }

    /**
     * Applies the shared Management Filters scope (department/user/store) to a
     * ticket query. Used by every widget that ranks/counts tickets directly
     * (Ticket Flow Board, Open vs Closed charts, Top Brands, Top Teams).
     */
    private function applyAssigneeScopeFilters($query, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter)
    {
        if ($departmentIdFilter) {
            $query->whereHas('assignee', fn ($q) => $q->where('department_id', $departmentIdFilter));
        }

        if ($departmentNodeIdFilter) {
            $nodeIds = array_merge([(int) $departmentNodeIdFilter], DepartmentNode::getAllDescendantIds((int) $departmentNodeIdFilter));
            $query->whereHas('assignee', fn ($q) => $q->whereIn('department_node_id', $nodeIds));
        }

        if ($userIdFilter === 'unassigned') {
            $query->whereNull('assignee_id');
        } elseif ($userIdFilter && $userIdFilter !== 'all') {
            $query->where('assignee_id', $userIdFilter);
        }

        if ($storeIdFilter && $storeIdFilter !== 'all') {
            $query->where('store_id', $storeIdFilter);
        }

        return $query;
    }

    private function buildTicketCharts($filteredQuery, $user, array $effectiveCompanyIds, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter): array
    {
        $applyDashboardChartFilters = fn ($chartQuery) => $this->applyAssigneeScopeFilters($chartQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter);

        $chartQuery = $applyDashboardChartFilters(clone $filteredQuery);

        $overallChartRow = (clone $chartQuery)
            ->selectRaw(
                "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
            )
            ->first();

        // Office stores (Stores "Class" = "Office") are broken out into their
        // own "Per Corporate Office" grouping instead of rolling up under a brand.
        $officeStores = Store::query()
            ->where('class', 'Office')
            ->where('is_active', true)
            ->whereIn('company_id', $effectiveCompanyIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $officeStoreIds = $officeStores->pluck('id');

        // Per Store Brand: one row per selected entity, counting tickets that sit on
        // that entity's NON-office stores (store ownership, matching Live Store Health).
        // Grouping by the store's company — not the ticket's stamp — keeps this tallied
        // with the store-owner-scoped Overall chart above.
        $brandCompanies = CompanyContext::accessibleCompanies($user)
            ->whereIn('id', $effectiveCompanyIds)
            ->values();
        $brandChartQuery = $applyDashboardChartFilters(clone $filteredQuery);

        $brandChartRows = $brandCompanies
            ->map(function ($company) use ($brandChartQuery) {
                $row = (clone $brandChartQuery)
                    ->whereHas('store', fn ($s) => $s
                        ->where('company_id', $company->id)
                        ->where('class', '!=', 'Office'))
                    ->selectRaw(
                        "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                        "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
                    )
                    ->first();

                return [
                    'id' => (int) $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'open' => (int) ($row->open_count ?? 0),
                    'closed' => (int) ($row->closed_count ?? 0),
                ];
            })
            ->sortBy('name')
            ->values();

        // Per Corporate Office: one card per individual Office Store, counting only
        // tickets raised from that store.
        $officeChartQuery = $applyDashboardChartFilters(clone $filteredQuery);

        $officeCounts = (clone $officeChartQuery)
            ->whereIn('store_id', $officeStoreIds)
            ->selectRaw(
                "store_id, " .
                "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
            )
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $officeChartRows = $officeStores
            ->map(function ($store) use ($officeCounts) {
                $row = $officeCounts->get($store->id);

                return [
                    'id' => (int) $store->id,
                    'name' => $store->name,
                    'code' => $store->code,
                    'open' => (int) ($row->open_count ?? 0),
                    'closed' => (int) ($row->closed_count ?? 0),
                ];
            })
            ->values();

        $ticketCountsByItem = (clone $chartQuery)
            ->whereNotNull('item_id')
            ->selectRaw(
                "item_id, " .
                "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
            )
            ->groupBy('item_id')
            ->get()
            ->keyBy('item_id');

        $concernCounts = Item::query()
            ->whereIn('id', $ticketCountsByItem->keys())
            ->whereIn('concern_type', ['Incident', 'Service Request', 'Problem'])
            ->get(['id', 'concern_type'])
            ->reduce(function ($counts, $item) use ($ticketCountsByItem) {
                $itemCounts = $ticketCountsByItem[$item->id] ?? null;

                if (!isset($counts[$item->concern_type])) {
                    $counts[$item->concern_type] = ['open' => 0, 'closed' => 0];
                }

                $counts[$item->concern_type]['open'] += (int) ($itemCounts->open_count ?? 0);
                $counts[$item->concern_type]['closed'] += (int) ($itemCounts->closed_count ?? 0);

                return $counts;
            }, []);

        $overallOpen = (int) ($overallChartRow->open_count ?? 0);
        $overallClosed = (int) ($overallChartRow->closed_count ?? 0);

        $concernTypes = collect(['Incident', 'Service Request', 'Problem'])
            ->map(fn ($type) => [
                'key' => $type,
                'label' => $type,
                'open' => (int) ($concernCounts[$type]['open'] ?? 0),
                'closed' => (int) ($concernCounts[$type]['closed'] ?? 0),
                'total' => (int) (($concernCounts[$type]['open'] ?? 0) + ($concernCounts[$type]['closed'] ?? 0)),
            ])
            ->values();

        // Tickets with no item / a concern type outside the three above still belong to
        // the overall total, so surface them as "Uncategorized" — this makes the Concern
        // Type section tally to the Overall Open vs Closed count (no silent gap).
        $uncategorizedOpen = max(0, $overallOpen - $concernTypes->sum('open'));
        $uncategorizedClosed = max(0, $overallClosed - $concernTypes->sum('closed'));
        if ($uncategorizedOpen > 0 || $uncategorizedClosed > 0) {
            $concernTypes->push([
                'key' => 'Uncategorized',
                'label' => 'Uncategorized',
                'open' => $uncategorizedOpen,
                'closed' => $uncategorizedClosed,
                'total' => $uncategorizedOpen + $uncategorizedClosed,
            ]);
        }

        return [
            'overall' => [
                'open' => $overallOpen,
                'closed' => $overallClosed,
            ],
            'perStoreBrand' => $brandChartRows,
            'perCorporateOffice' => $officeChartRows,
            'concernTypes' => $concernTypes->values(),
        ];
    }

    /**
     * Ticket Flow Board + Projects Board (default dashboard tab).
     *
     * @return array{report: array, projects: array}
     */
    private function buildKanban($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter): array
    {
        $kanbanColumns = [
            ['key' => 'backlogs', 'label' => 'Backlogs', 'statuses' => ['open', 'for_schedule']],
            ['key' => 'in_progress', 'label' => 'In Progress', 'statuses' => ['in_progress', 'waiting_service_provider', 'waiting_client_feedback']],
            ['key' => 'resolved', 'label' => 'Resolved', 'statuses' => ['resolved']],
            ['key' => 'closed', 'label' => 'Closed', 'statuses' => ['closed']],
        ];
        $kanbanStatusToColumn = collect($kanbanColumns)
            ->flatMap(fn ($column) => collect($column['statuses'])->mapWithKeys(fn ($status) => [$status => $column['key']]))
            ->all();
        // Use the complete dashboard ticket universe. The status-to-column map
        // handles every current status, while the mapper's Backlogs fallback keeps
        // legacy/unexpected non-terminal statuses visible instead of silently
        // dropping them from the board total.
        $kanbanBaseQuery = clone $filteredQuery;

        $selectedDepartmentNodeId = $departmentNodeIdFilter ? (int) $departmentNodeIdFilter : null;
        $kanbanSectorContext = $this->resolveKanbanSectorContext(
            $departmentIdFilter ? (int) $departmentIdFilter : null,
            $selectedDepartmentNodeId
        );
        $kanbanDepartmentGrouping = [];

        if ($selectedDepartmentNodeId) {
            $immediateChildren = DepartmentNode::query()
                ->where('parent_id', $selectedDepartmentNodeId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($immediateChildren as $childNode) {
                $kanbanDepartmentGrouping[$childNode->id] = [
                    'key' => 'node_'.$childNode->id,
                    'label' => $childNode->name,
                ];

                foreach (DepartmentNode::getAllDescendantIds($childNode->id) as $descendantId) {
                    $kanbanDepartmentGrouping[$descendantId] = [
                        'key' => 'node_'.$childNode->id,
                        'label' => $childNode->name,
                    ];
                }
            }

            $selectedNode = DepartmentNode::find($selectedDepartmentNodeId);
            $kanbanDepartmentGrouping[$selectedDepartmentNodeId] = [
                'key' => 'node_'.$selectedDepartmentNodeId,
                'label' => $selectedNode?->name ?? 'Selected Department',
            ];
        }

        $applyAssigneeKanbanFilters = fn ($query) => $this->applyAssigneeScopeFilters($query, $departmentIdFilter, $selectedDepartmentNodeId, $userIdFilter, $storeIdFilter);

        $departmentKanbanQuery = clone $kanbanBaseQuery;

        if ($kanbanSectorContext['active']) {
            // Sector mode: filter by store.sector at the DB level — no assignee filtering, regardless of who is assigned
            $departmentKanbanQuery->whereHas('store', fn ($q) => $q->whereIn('sector', $kanbanSectorContext['sectors']));
            if ($storeIdFilter && $storeIdFilter !== 'all') {
                $departmentKanbanQuery->where('store_id', $storeIdFilter);
            }
        } else {
            $departmentKanbanQuery = $applyAssigneeKanbanFilters($departmentKanbanQuery);
        }

        $userKanbanQuery = $applyAssigneeKanbanFilters(clone $kanbanBaseQuery);

        $mapKanbanTickets = function ($query, bool $useSectorGrouping = false) use ($kanbanStatusToColumn, $kanbanDepartmentGrouping, $kanbanSectorContext) {
            $tickets = $query
                ->with([
                    'assignee:id,name,org_path,department_node_id',
                    'company:id,name',
                    'store:id,code,name,sector',
                    'item:id,priority',
                    'parent:id,ticket_key',
                ])
                ->select('id', 'ticket_key', 'title', 'status', 'priority', 'created_at', 'updated_at', 'assignee_id', 'company_id', 'store_id', 'item_id', 'parent_id')
                ->latest('updated_at')
                ->get();

            // SQL Server accepts at most 2,100 bound parameters. Loading this
            // per-ticket relationship for the entire board in one query exceeds
            // that limit as soon as the dashboard contains enough tickets.
            $tickets->chunk(1000)->each(
                fn ($ticketChunk) => $ticketChunk->load('survey:ticket_id,rating,feedback')
            );

            return $tickets->filter(function ($ticket) use ($useSectorGrouping, $kanbanSectorContext) {
                if (!$useSectorGrouping) {
                    return true;
                }

                $sector = $ticket->store?->sector ? (int) $ticket->store->sector : null;

                return $sector !== null && in_array($sector, $kanbanSectorContext['sectors'], true);
            })
            ->map(function ($ticket) use ($kanbanStatusToColumn, $kanbanDepartmentGrouping, $useSectorGrouping) {
                $priority = $ticket->item?->priority ?? $ticket->priority ?? 'low';
                $departmentNodeId = $ticket->assignee?->department_node_id;
                $grouping = $departmentNodeId ? ($kanbanDepartmentGrouping[$departmentNodeId] ?? null) : null;
                $departmentGroupKey = $grouping['key'] ?? ($ticket->assignee?->org_path ?: 'No Org Path');
                $departmentGroupLabel = $grouping['label'] ?? $this->extractLastOrgPathSegment($ticket->assignee?->org_path);
                $departmentGroupSort = null;
                // Grouping is driven purely by the ticket's store sector, mirroring the
                // Live Store Health report. Sector 0 (Corporate Technology stores) is a
                // real sector, so test against null — not truthiness — otherwise a
                // sector-0 ticket falls through to the assignee org_path and can be
                // mislabeled as the assignee's own sector (e.g. CFE I shown under "Sector 3").
                $storeSector = $ticket->store && $ticket->store->sector !== null
                    ? (int) $ticket->store->sector
                    : null;

                if ($storeSector !== null) {
                    $departmentGroupKey = 'sector_'.$storeSector;
                    $departmentGroupLabel = 'Sector '.$storeSector;
                    $departmentGroupSort = $storeSector;
                } elseif ($useSectorGrouping) {
                    $departmentGroupKey = 'no_store_sector';
                    $departmentGroupLabel = 'No Store Sector';
                    $departmentGroupSort = 999;
                }

                return [
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key ?? $ticket->id,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'column' => $kanbanStatusToColumn[$ticket->status] ?? 'backlogs',
                    'priority' => strtolower((string) $priority),
                    'assignee_id' => $ticket->assignee_id,
                    'assignee' => $ticket->assignee?->name ?? 'Unassigned',
                    'sub_unit' => $ticket->assignee?->org_path ?: 'No Org Path',
                    'sub_unit_short' => $this->extractLastOrgPathSegment($ticket->assignee?->org_path),
                    'department_group_key' => $departmentGroupKey,
                    'department_group_label' => $departmentGroupLabel ?: $departmentGroupKey,
                    'department_group_sort' => $departmentGroupSort,
                    'company_name' => $ticket->company?->name ?? 'N/A',
                    'store' => $ticket->store ? [
                        'id' => $ticket->store->id,
                        'label' => trim(($ticket->store->code ? "[{$ticket->store->code}] " : '') . $ticket->store->name),
                        'sector' => $ticket->store->sector,
                    ] : null,
                    'parent_key' => $ticket->parent?->ticket_key,
                    'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $ticket->updated_at?->diffForHumans(),
                    'age' => $ticket->created_at?->diffForHumans(null, true),
                    'survey' => $ticket->survey ? [
                        'rating' => $ticket->survey->rating,
                        'feedback' => $ticket->survey->feedback,
                    ] : null,
                ];
            });
        };

        $departmentKanbanTickets = $mapKanbanTickets($departmentKanbanQuery, $kanbanSectorContext['active']);
        $userKanbanTickets = $mapKanbanTickets($userKanbanQuery);

        $emptyColumnSet = fn () => collect($kanbanColumns)
            ->mapWithKeys(fn ($column) => [$column['key'] => ['count' => 0, 'tickets' => []]])
            ->all();

        $buildKanbanGroups = function ($tickets, string $mode, bool $useSectorGrouping = false) use ($kanbanColumns, $emptyColumnSet, $kanbanSectorContext) {
            $groups = $tickets
                ->groupBy(function ($ticket) use ($mode) {
                    if ($mode === 'user') {
                        return $ticket['assignee_id'] ? (string) $ticket['assignee_id'] : 'unassigned';
                    }

                    return $ticket['department_group_key'] ?: 'No Org Path';
                })
                ->map(function ($groupTickets, $groupKey) use ($mode, $emptyColumnSet, $useSectorGrouping) {
                    $firstTicket = $groupTickets->first();
                    $columns = $emptyColumnSet();

                    foreach ($groupTickets->groupBy('column') as $columnKey => $columnTickets) {
                        $columns[$columnKey] = [
                            'count' => $columnTickets->count(),
                            'tickets' => $columnTickets->values()->all(),
                        ];
                    }

                    return [
                        'key' => (string) $groupKey,
                        'label' => $mode === 'user' ? $firstTicket['assignee'] : (string) ($firstTicket['department_group_label'] ?: $firstTicket['sub_unit_short'] ?: $groupKey),
                        'subtitle' => $mode === 'user'
                            ? $firstTicket['sub_unit']
                            : ($useSectorGrouping
                                ? $groupTickets->pluck('store.id')->filter()->unique()->count() . ' store(s)'
                                : $groupTickets->pluck('assignee')->unique()->count() . ' user(s)'), // sub_unit key now holds org_path value
                        'total' => $groupTickets->count(),
                        'sort' => $firstTicket['department_group_sort'] ?? 999,
                        'columns' => $columns,
                    ];
                });

            if ($useSectorGrouping) {
                foreach ($kanbanSectorContext['sectors'] as $sector) {
                    $key = 'sector_'.$sector;

                    if (!$groups->has($key)) {
                        $groups->put($key, [
                            'key' => $key,
                            'label' => 'Sector '.$sector,
                            'subtitle' => '0 store(s)',
                            'total' => 0,
                            'sort' => $sector,
                            'columns' => $emptyColumnSet(),
                        ]);
                    }
                }
            }

            // Department view is sector-oriented: always order by sector number
            // (non-sector groups carry sort 999 and fall to the bottom). User view
            // stays ranked by workload.
            $groups = $mode === 'user'
                ? $groups->sortBy([['total', 'desc'], ['label', 'asc']])
                : $groups->sortBy([['sort', 'asc'], ['label', 'asc']]);

            return $groups
                ->values()
                ->all();
        };

        $kanbanTotalsFor = fn ($tickets) => collect($kanbanColumns)
            ->mapWithKeys(fn ($column) => [$column['key'] => $tickets->where('column', $column['key'])->count()])
            ->merge(['all' => $tickets->count()])
            ->all();

        $departmentKanbanTotals = $kanbanTotalsFor($departmentKanbanTickets);
        $userKanbanTotals = $kanbanTotalsFor($userKanbanTickets);

        $kanbanReport = [
            'columns' => $kanbanColumns,
            'totals' => [
                ...$departmentKanbanTotals,
                'sub_unit' => $departmentKanbanTotals,
                'user' => $userKanbanTotals,
            ],
            'department_view_mode' => $kanbanSectorContext['active'] ? 'sector' : 'department',
            'department_view_label' => $kanbanSectorContext['active'] ? 'Sector' : 'Department',
            'groups' => [
                'sub_unit' => $buildKanbanGroups($departmentKanbanTickets, 'sub_unit', $kanbanSectorContext['active']),
                'user' => $buildKanbanGroups($userKanbanTickets, 'user'),
            ],
        ];

        // Projects Board Data
        $projectStatuses = ['Pending', 'In Progress', 'Delayed', 'Completed'];
        $projectModels = \App\Models\Project::with(['store', 'tasks'])
            ->orderBy('target_go_live', 'asc')
            ->get();

        $allProjects = $projectModels
            ->map(function ($project) {
                $totalTasks = $project->tasks->count();
                $completionPct = $totalTasks > 0
                    ? round($project->tasks->sum('progress') / $totalTasks)
                    : 0;
                return [
                    'id'              => $project->id,
                    'name'            => $project->name,
                    'status'          => $project->status ?? 'Pending',
                    'store'           => $project->store ? $project->store->name : null,
                    'target_go_live'  => $project->target_go_live?->format('M d, Y'),
                    'turn_over_date'  => $project->turn_over_date?->format('M d, Y'),
                    'completion_pct'  => $completionPct,
                    'total_tasks'     => $totalTasks,
                ];
            });

        // Deployment Summary: projects bucketed into Store Deployment
        // ('Store Opening') vs Project Deployment (every other project type),
        // each broken down across the four project statuses.
        $deploymentDefs = [
            'store'   => 'Store Deployment',
            'project' => 'Project Deployment',
        ];
        $deploymentBucketFor = fn ($type) => $type === 'Store Opening' ? 'store' : 'project';

        // Full canonical status set (includes 'Planning', which the board columns
        // omit) so the summary accounts for every project in each segment.
        $deploymentStatuses = ['Planning', 'Pending', 'In Progress', 'Delayed', 'Completed'];

        $deploymentSummary = collect($deploymentDefs)
            ->map(function ($label, $key) use ($projectModels, $deploymentStatuses, $deploymentBucketFor) {
                $segment = $projectModels->filter(fn ($p) => $deploymentBucketFor($p->project_type) === $key);

                $statusCounts = collect($deploymentStatuses)->map(fn ($status) => [
                    'key'   => $status,
                    'label' => $status,
                    'count' => $segment->filter(fn ($p) => ($p->status ?: 'Pending') === $status)->count(),
                ])->values();

                return [
                    'key'      => $key,
                    'label'    => $label,
                    'total'    => (int) $statusCounts->sum('count'),
                    'statuses' => $statusCounts->all(),
                ];
            })
            ->values()
            ->all();

        $projectColumns = collect($projectStatuses)->map(fn ($s) => ['key' => $s, 'label' => $s])->all();
        $projectGroups  = collect($projectStatuses)->mapWithKeys(fn ($s) => [
            $s => $allProjects->where('status', $s)->values()->all(),
        ])->all();
        $projectTotals  = collect($projectStatuses)->mapWithKeys(fn ($s) => [
            $s => $allProjects->where('status', $s)->count(),
        ])->merge(['all' => $allProjects->count()])->all();

        return [
            'report' => $kanbanReport,
            'projects' => [
                'columns' => $projectColumns,
                'groups'  => $projectGroups,
                'totals'  => $projectTotals,
                'deploymentSummary' => $deploymentSummary,
            ],
        ];
    }

    /**
     * Overview Performance tab: KPI stats, modal lists, recent/my tickets, activity.
     */
    private function buildOverview($query, $filteredQuery, $user, $allowedCompanyIds): array
    {
        // Waiting Aging Alarm Logic
        $agingDays = (int) Setting::get('waiting_aging_alarm_days', 3);
        $alarmDate = Carbon::now('Asia/Manila')->subDays($agingDays);

        $alarmedWaitingQuery = (clone $query)
            ->whereIn('status', ['waiting_service_provider', 'waiting_client_feedback'])
            ->where('updated_at', '<=', $alarmDate);

        $alarmedWaitingTickets = (clone $alarmedWaitingQuery)
            ->with(['assignee:id,name', 'company:id,name', 'parent:id,ticket_key'])
            ->select('id', 'ticket_key', 'title', 'status', 'updated_at', 'assignee_id', 'company_id', 'parent_id')
            ->get()
            ->map(function($ticket) {
                $updatedAt = Carbon::parse($ticket->updated_at);
                $now = Carbon::now('Asia/Manila');
                // Calculate precise days as float and round to 1 decimal
                $agingDays = round($updatedAt->diffInMinutes($now) / (60 * 24), 1);

                return [
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'aging_days' => $agingDays,
                    'assignee' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                    'company_name' => $ticket->company ? $ticket->company->name : 'N/A',
                    'updated_at' => $ticket->updated_at->format('Y-m-d H:i:s'),
                    'parent_key' => $ticket->parent?->ticket_key,
                ];
            });

        // Urgent (P1) Tickets Logic
        $urgentTicketsQuery = (clone $query)
            ->where(function($q) {
                $q->where('priority', 'urgent')
                  ->orWhereHas('item', function($iq) {
                      $iq->where('priority', 'Urgent');
                  });
            })
            ->where('status', '!=', 'closed');

        $urgentTickets = (clone $urgentTicketsQuery)
            ->with(['item', 'assignee:id,name', 'company:id,name', 'parent:id,ticket_key'])
            ->select('id', 'ticket_key', 'title', 'status', 'created_at', 'item_id', 'priority', 'assignee_id', 'company_id', 'parent_id')
            ->get()
            ->map(function($ticket) {
                return [
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'item_priority' => $ticket->item?->priority,
                    'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
                    'assignee' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                    'company_name' => $ticket->company ? $ticket->company->name : 'N/A',
                    'parent_key' => $ticket->parent?->ticket_key,
                ];
            });

        // New Tickets Logic
        $newTicketsQuery = (clone $filteredQuery)
            ->where('status', 'open')
            ->whereNull('category_id')
            ->whereNull('sub_category_id')
            ->whereNull('item_id')
            ->whereNull('assignee_id');

        // Modal Lists (limited to 100 to prevent performance issues)
        $listWithDetails = function($q) {
            return $q->with(['assignee:id,name', 'company:id,name', 'parent:id,ticket_key'])
                ->select('id', 'ticket_key', 'title', 'status', 'created_at', 'priority', 'assignee_id', 'company_id', 'parent_id')
                ->latest()
                ->take(100)
                ->get()
                ->map(function($t) {
                    return [
                        'id' => $t->id,
                        'key' => $t->ticket_key,
                        'title' => $t->title,
                        'status' => $t->status,
                        'priority' => $t->priority,
                        'created_at' => $t->created_at->format('Y-m-d H:i:s'),
                        'assignee' => $t->assignee ? $t->assignee->name : 'Unassigned',
                        'company_name' => $t->company ? $t->company->name : 'N/A',
                        'parent_key' => $t->parent?->ticket_key,
                    ];
                });
        };

        // "Open" and "Closed" use the same definition as the Open vs Closed tab and
        // the Store Health report: open = anything not yet terminal, closed = resolved
        // OR closed. This keeps every dashboard tab tallied to the same universe, so
        // Total = Open + Closed and no resolved ticket goes uncounted.
        $totalTicketsList = $listWithDetails(clone $filteredQuery);
        $openTicketsList = $listWithDetails((clone $filteredQuery)->whereNotIn('status', ['resolved', 'closed']));
        $newTicketsList = $listWithDetails(clone $newTicketsQuery);
        $closedTicketsList = $listWithDetails((clone $filteredQuery)->whereIn('status', ['resolved', 'closed']));

        // Stats (Filtered)
        $stats = [
            'total' => (clone $filteredQuery)->count(),
            'open' => (clone $filteredQuery)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'new' => (clone $newTicketsQuery)->count(),
            'in_progress' => (clone $filteredQuery)->where('status', 'in_progress')->count(),
            'closed' => (clone $filteredQuery)->whereIn('status', ['resolved', 'closed'])->count(),
            'waiting' => (clone $filteredQuery)->whereIn('status', ['waiting_service_provider', 'waiting_client_feedback'])->count(),
            'waiting_alarm' => $alarmedWaitingTickets->count(),
            'urgent' => $urgentTickets->count(),
            'unassigned' => (clone $filteredQuery)->whereNull('assignee_id')->count(),
        ];

        // Recent/Filtered Tickets
        $recentTickets = (clone $filteredQuery)
            ->with(['reporter:id,name', 'assignee:id,name', 'company:id,name', 'parent:id,ticket_key'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($ticket) {
                $reporterName = $ticket->reporter ? $ticket->reporter->name : ($ticket->sender_name ?: 'Unknown');
                if (str_contains($reporterName, '<')) {
                    $reporterName = trim(explode('<', $reporterName)[0]);
                }

                return [
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key ?? $ticket->id,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'company_name' => $ticket->company ? $ticket->company->name : 'N/A',
                    'created_at' => $ticket->created_at->diffForHumans(),
                    'reporter' => $reporterName,
                    'assignee' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                    'parent_key' => $ticket->parent?->ticket_key,
                ];
            });

        // My Tickets (Real-time, not affected by month filter)
        $myTickets = Ticket::query()
            ->where('assignee_id', $user->id)
            ->where('status', '!=', 'closed')
            ->with(['company:id,name', 'parent:id,ticket_key'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'key' => $ticket->ticket_key ?? $ticket->id,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'company_name' => $ticket->company ? $ticket->company->name : 'N/A',
                    'updated_at' => $ticket->updated_at->diffForHumans(),
                    'parent_key' => $ticket->parent?->ticket_key,
                ];
            });

        // Activity Stream (Real-time)
        $histories = TicketHistory::query()
            ->with(['user:id,name,profile_photo', 'ticket:id,ticket_key,title'])
            ->whereHas('ticket', function ($q) use ($user, $allowedCompanyIds) {
                if ($user->hasRole('User')) {
                    $q->where('reporter_id', $user->id);
                } elseif ($allowedCompanyIds->isEmpty()) {
                    $q->whereRaw('1 = 0');
                } else {
                    $this->applyEntityScope($q, $allowedCompanyIds);
                }
            })
            ->latest('changed_at')->take(10)->get()
            ->map(function ($history) {
                return [
                    'type' => 'history',
                    'id' => $history->id,
                    'user' => $history->user ? $history->user->name : 'System',
                    'user_photo' => $history->user ? $history->user->profile_photo : null,
                    'action' => $this->formatAction($history),
                    'ticket_id' => $history->ticket_id,
                    'ticket_key' => $history->ticket ? $history->ticket->ticket_key : 'Unknown',
                    'timestamp' => $history->changed_at,
                    'time' => $history->changed_at ? $history->changed_at->diffForHumans() : '',
                ];
            });

        $comments = TicketComment::query()
            ->with(['user:id,name,profile_photo', 'ticket:id,ticket_key,title'])
            ->whereHas('ticket', function ($q) use ($user, $allowedCompanyIds) {
                if ($user->hasRole('User')) {
                    $q->where('reporter_id', $user->id);
                } elseif ($allowedCompanyIds->isEmpty()) {
                    $q->whereRaw('1 = 0');
                } else {
                    $this->applyEntityScope($q, $allowedCompanyIds);
                }
            })
            ->latest()->take(10)->get()
            ->map(function ($comment) {
                $displayName = $comment->user ? $comment->user->name : ($comment->sender_name ?: 'Unknown User');
                if (str_contains($displayName, '<')) {
                    $displayName = trim(explode('<', $displayName)[0]);
                }

                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'user' => $displayName,
                    'user_photo' => $comment->user ? $comment->user->profile_photo : null,
                    'action' => 'commented on',
                    'comment_text' => $comment->comment_text,
                    'ticket_id' => $comment->ticket_id,
                    'ticket_key' => $comment->ticket ? $comment->ticket->ticket_key : 'Unknown',
                    'timestamp' => $comment->created_at,
                    'time' => $comment->created_at->diffForHumans(),
                ];
            });

        $activities = $histories->concat($comments)->sortByDesc('timestamp')->take(10)->values();

        return [
            'stats' => $stats,
            'recentTickets' => $recentTickets,
            'myTickets' => $myTickets,
            'recentActivity' => $activities,
            'alarmedWaitingTickets' => $alarmedWaitingTickets,
            'urgentTickets' => $urgentTickets,
            'totalTicketsList' => $totalTicketsList,
            'openTicketsList' => $openTicketsList,
            'newTicketsList' => $newTicketsList,
            'closedTicketsList' => $closedTicketsList,
        ];
    }

    private function resolveKanbanSectorContext(?int $departmentId, ?int $selectedDepartmentNodeId): array
    {
        $sectorNumbers = collect();

        if ($selectedDepartmentNodeId) {
            $selectedNode = DepartmentNode::find($selectedDepartmentNodeId, ['id', 'name']);
            $selectedSector = $this->sectorNumberFromNodeName($selectedNode?->name);

            if ($selectedSector) {
                $sectorNumbers->push($selectedSector);
            } else {
                $descendantIds = DepartmentNode::getAllDescendantIds($selectedDepartmentNodeId);
                $sectorNumbers = DepartmentNode::query()
                    ->whereIn('id', $descendantIds)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name')
                    ->map(fn ($name) => $this->sectorNumberFromNodeName($name))
                    ->filter();
            }
        }
        // Dept-level selection (no specific node) stays in normal dept-grouping mode — no sector activation.

        $sectors = $sectorNumbers
            ->map(fn ($sector) => (int) $sector)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'active' => count($sectors) > 0,
            'sectors' => $sectors,
        ];
    }

    private function sectorNumberFromNodeName(?string $name): ?int
    {
        if (!preg_match('/^sector\s*([1-8])$/i', trim((string) $name), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function buildLeaderboard(
        $filteredQuery,
        ?int $year = null,
        ?int $month = null,
        ?string $departmentIdFilter = null,
        ?int $departmentNodeIdFilter = null,
        string|int|null $userIdFilter = 'all',
        string|int|null $storeIdFilter = 'all',
        array $companyIds = []
    ): array
    {
        $now = \Carbon\Carbon::now();
        $filterYear  = $year  ?? $now->year;
        $filterMonth = $month ?? $now->month;

        $userQuery = \App\Models\User::active()
            ->whereHas('roles', fn ($q) => $q->where('is_assignable', true));

        if ($departmentIdFilter) {
            $userQuery->where('department_id', $departmentIdFilter);
        }

        if ($departmentNodeIdFilter) {
            $nodeIds = array_merge([$departmentNodeIdFilter], DepartmentNode::getAllDescendantIds($departmentNodeIdFilter));
            $userQuery->whereIn('department_node_id', $nodeIds);
        }

        if ($userIdFilter === 'unassigned') {
            // No individual agent represents "unassigned" — nothing to rank.
            $techs = collect();
        } else {
            if ($userIdFilter && $userIdFilter !== 'all') {
                $userQuery->where('id', $userIdFilter);
            }

            $techs = $userQuery->get(['id', 'name', 'profile_photo']);
        }

        $eligibleAgentIds = $techs->pluck('id')->all();

        $pointBaseQuery = \App\Models\AgentPointTransaction::query()
            ->leftJoin('tickets as pt', 'pt.id', '=', 'agent_point_transactions.ticket_id')
            ->whereYear('agent_point_transactions.awarded_at', $filterYear)
            ->whereMonth('agent_point_transactions.awarded_at', $filterMonth)
            ->whereIn('agent_point_transactions.agent_id', $eligibleAgentIds);

        // Entity/Company scope: only count points earned on tickets of the
        // selected entities (the joined ticket carries the company_id).
        if (!empty($companyIds)) {
            $pointBaseQuery->whereIn('pt.company_id', $companyIds);
        }

        if ($storeIdFilter && $storeIdFilter !== 'all') {
            $pointBaseQuery->where('pt.store_id', $storeIdFilter);
        }

        $monthlyPointRows = (clone $pointBaseQuery)
            ->selectRaw('agent_point_transactions.agent_id, SUM(agent_point_transactions.points) as total_points, COUNT(DISTINCT agent_point_transactions.ticket_id) as ticket_count')
            ->groupBy('agent_point_transactions.agent_id')
            ->havingRaw('SUM(agent_point_transactions.points) <> 0')
            ->get()
            ->keyBy('agent_id');

        $agentIds = $monthlyPointRows->keys()->map(fn ($id) => (int) $id)->values()->all();

        $breakdownRows = (clone $pointBaseQuery)
            ->whereIn('agent_point_transactions.agent_id', $agentIds)
            ->selectRaw('agent_point_transactions.agent_id, agent_point_transactions.type, SUM(agent_point_transactions.points) as points, COUNT(*) as count')
            ->groupBy('agent_point_transactions.agent_id', 'agent_point_transactions.type')
            ->get()
            ->groupBy('agent_id');

        $scoredTickets = (clone $pointBaseQuery)
            ->whereIn('agent_point_transactions.agent_id', $agentIds)
            ->whereNotNull('agent_point_transactions.ticket_id')
            ->selectRaw('DISTINCT agent_point_transactions.agent_id, agent_point_transactions.ticket_id');

        $avgResponseExpr = $this->minutesDiffExpression('t.created_at', 'sm.first_response_at');
        $avgResolutionExpr = $this->minutesDiffExpression('t.created_at', 'sm.resolved_at');

        $averageTimes = \Illuminate\Support\Facades\DB::query()
            ->fromSub($scoredTickets->toBase(), 'scored')
            ->join('tickets as t', 't.id', '=', 'scored.ticket_id')
            ->leftJoin('ticket_sla_metrics as sm', 'sm.ticket_id', '=', 't.id')
            ->selectRaw("
                scored.agent_id,
                AVG(CASE WHEN sm.first_response_at IS NOT NULL THEN {$avgResponseExpr} END) as avg_response_min,
                AVG(CASE WHEN sm.resolved_at IS NOT NULL THEN {$avgResolutionExpr} END) as avg_resolution_min
            ")
            ->groupBy('scored.agent_id')
            ->get()
            ->keyBy('agent_id');

        $rankings = $techs
            ->filter(fn ($tech) => isset($monthlyPointRows[$tech->id]))
            ->map(function ($tech) use ($monthlyPointRows, $breakdownRows, $averageTimes) {
                $row = $monthlyPointRows[$tech->id];
                $averageRow = $averageTimes[$tech->id] ?? null;

                return [
                    'agent_id' => $tech->id,
                    'name' => $tech->name,
                    'profile_photo' => $tech->profile_photo,
                    'total_points' => (int) $row->total_points,
                    'ticket_count' => (int) $row->ticket_count,
                    'avg_response_min' => $averageRow?->avg_response_min !== null ? (int) round($averageRow->avg_response_min) : null,
                    'avg_resolution_min' => $averageRow?->avg_resolution_min !== null ? (int) round($averageRow->avg_resolution_min) : null,
                    'point_breakdown' => $this->formatPointBreakdown($breakdownRows[$tech->id] ?? collect()),
                ];
            })
            ->sortBy([
                ['total_points', 'desc'],
                ['ticket_count', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->map(fn ($row, $index) => [
                ...$row,
                'rank' => $index + 1,
                'avg_close_min' => $row['avg_resolution_min'],
            ]);

        // Monthly trophies (top agent per category)
        $trophies = $this->buildTrophies(
            \Carbon\Carbon::create($filterYear, $filterMonth, 1),
            $departmentIdFilter,
            $departmentNodeIdFilter,
            $userIdFilter,
            $storeIdFilter,
            $companyIds
        );

        return [
            'top3' => $rankings->take(3)->values()->toArray(),
            'rankings' => $rankings->values()->toArray(),
            'trophies' => $trophies,
            'topBrands' => $this->buildTopBrands($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter, $companyIds),
            'topTeams' => $this->buildTopTeams($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter),
        ];
    }

    /**
     * Top Brands: every entity in scope ranked by total ticket volume
     * (open + closed concerns), same Management Filters scope as the rest of
     * the dashboard.
     */
    private function buildTopBrands($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter, array $companyIds = []): array
    {
        $brandScopeQuery = $this->applyAssigneeScopeFilters(clone $filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter);

        // Rank entities by the tickets sitting on the stores they OWN (store ownership),
        // not by the ticket's stamped company_id — this keeps the ranking tallied with
        // Live Store Health and the Open vs Closed / Per-Store-Brand charts.
        $companies = \App\Models\Company::whereIn('id', $companyIds)->get(['id', 'name', 'code']);

        return $companies
            ->map(function ($company) use ($brandScopeQuery) {
                $row = (clone $brandScopeQuery)
                    ->whereHas('store', fn ($s) => $s->where('company_id', $company->id))
                    ->selectRaw(
                        "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                        "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
                    )
                    ->first();

                $open = (int) ($row->open_count ?? 0);
                $closed = (int) ($row->closed_count ?? 0);

                return [
                    'id' => (int) $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'open' => $open,
                    'closed' => $closed,
                    'total' => $open + $closed,
                ];
            })
            ->filter(fn ($row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->map(fn ($row, $index) => [...$row, 'rank' => $index + 1])
            ->all();
    }

    /**
     * Top Teams: tickets grouped by the assignee's org-chart "team" branch —
     * the node one level below whichever root tree the assignee sits in (e.g.
     * under "Technology" that's Service Delivery / Service Operations /
     * Corporate Technology; under "Solutions" that's Business Solutions /
     * Digital Solutions). Flat single-node trees are their own team. This is
     * intentionally derived from whatever the org chart looks like rather
     * than any hardcoded department pair, so it adapts as the tree changes.
     */
    private function buildTopTeams($filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter): array
    {
        $teamScopeQuery = $this->applyAssigneeScopeFilters(clone $filteredQuery, $departmentIdFilter, $departmentNodeIdFilter, $userIdFilter, $storeIdFilter);

        $assigneeCounts = (clone $teamScopeQuery)
            ->selectRaw(
                "assignee_id, " .
                "SUM(CASE WHEN status NOT IN ('resolved', 'closed') THEN 1 ELSE 0 END) as open_count, " .
                "SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed_count"
            )
            ->groupBy('assignee_id')
            ->get();

        $assigneeIds = $assigneeCounts->pluck('assignee_id')->filter()->values();
        $assigneeNodeIds = \App\Models\User::whereIn('id', $assigneeIds)->pluck('department_node_id', 'id');
        $allNodes = DepartmentNode::all(['id', 'parent_id', 'name'])->keyBy('id');

        $teamTotals = [];

        foreach ($assigneeCounts as $row) {
            $nodeId = $row->assignee_id ? $assigneeNodeIds->get($row->assignee_id) : null;
            $teamNode = $nodeId ? $this->resolveTeamNode((int) $nodeId, $allNodes) : null;

            $key = $teamNode ? $teamNode->id : 'unassigned';
            $label = $teamNode ? $teamNode->name : 'Unassigned';

            $teamTotals[$key] ??= ['id' => $key, 'name' => $label, 'open' => 0, 'closed' => 0];
            $teamTotals[$key]['open'] += (int) $row->open_count;
            $teamTotals[$key]['closed'] += (int) $row->closed_count;
        }

        return collect($teamTotals)
            ->map(fn ($row) => [...$row, 'total' => $row['open'] + $row['closed']])
            ->filter(fn ($row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->map(fn ($row, $index) => [...$row, 'rank' => $index + 1])
            ->all();
    }

    /**
     * Walks up a department node's ancestry to find its "team" — the node one
     * level below the root of whichever tree it belongs to, or the node
     * itself if it has no parent (it is a root/flat branch).
     */
    private function resolveTeamNode(int $nodeId, \Illuminate\Support\Collection $nodesById): ?DepartmentNode
    {
        $current = $nodesById->get($nodeId);

        if (!$current) {
            return null;
        }

        while ($current->parent_id) {
            $parent = $nodesById->get($current->parent_id);

            if (!$parent) {
                break;
            }

            if ($parent->parent_id === null) {
                return $current;
            }

            $current = $parent;
        }

        return $current;
    }

    private function buildTrophies(
        \Carbon\Carbon $now,
        ?string $departmentIdFilter = null,
        ?int $departmentNodeIdFilter = null,
        string|int|null $userIdFilter = 'all',
        string|int|null $storeIdFilter = 'all',
        array $companyIds = []
    ): array
    {
        $allowedAgentIds = null;

        if ($userIdFilter === 'unassigned') {
            // No individual agent represents "unassigned" — no trophies to award.
            $allowedAgentIds = [];
        } elseif ($departmentIdFilter || $departmentNodeIdFilter || ($userIdFilter && $userIdFilter !== 'all')) {
            $userQuery = \App\Models\User::active()
                ->whereHas('roles', fn ($q) => $q->where('is_assignable', true));

            if ($departmentIdFilter) {
                $userQuery->where('department_id', $departmentIdFilter);
            }

            if ($departmentNodeIdFilter) {
                $nodeIds = array_merge([$departmentNodeIdFilter], DepartmentNode::getAllDescendantIds($departmentNodeIdFilter));
                $userQuery->whereIn('department_node_id', $nodeIds);
            }

            if ($userIdFilter && $userIdFilter !== 'all') {
                $userQuery->where('id', $userIdFilter);
            }

            $allowedAgentIds = $userQuery->pluck('id')->toArray();
        }

        $byType = function (array $types) use ($now, $allowedAgentIds, $storeIdFilter, $companyIds) {
            $query = \App\Models\AgentPointTransaction::query()
                ->leftJoin('tickets as pt', 'pt.id', '=', 'agent_point_transactions.ticket_id')
                ->whereYear('agent_point_transactions.awarded_at', $now->year)
                ->whereMonth('agent_point_transactions.awarded_at', $now->month)
                ->whereIn('agent_point_transactions.type', $types);

            if ($allowedAgentIds !== null) {
                $query->whereIn('agent_point_transactions.agent_id', $allowedAgentIds);
            }

            if (!empty($companyIds)) {
                $query->whereIn('pt.company_id', $companyIds);
            }

            if ($storeIdFilter && $storeIdFilter !== 'all') {
                $query->where('pt.store_id', $storeIdFilter);
            }

            $row = $query->selectRaw('agent_point_transactions.agent_id, SUM(points) as total')
                ->groupBy('agent_point_transactions.agent_id')
                ->orderByDesc('total')
                ->first();
            if (!$row) return null;
            $agent = \App\Models\User::find($row->agent_id, ['id', 'name']);
            return ['name' => $agent?->name, 'points' => (int) $row->total];
        };

        return [
            ['icon' => '🏆', 'label' => 'Most Valuable Player',    'winner' => $byType(['fast_resolution','ontime_resolution','late_resolution','fcr_bonus','happy_customer','unhappy_customer','quest_bonus'])],
            ['icon' => '⭐', 'label' => 'Customer Wow Champion',   'winner' => $byType(['happy_customer'])],
            ['icon' => '🧙', 'label' => 'Wizard',                  'winner' => $byType(['fcr_bonus'])],
            ['icon' => '🏎️', 'label' => 'Speed Racer',             'winner' => $byType(['fast_resolution'])],
        ];
    }

    private function minutesDiffExpression(string $startColumn, string $endColumn): string
    {
        return match (\Illuminate\Support\Facades\DB::connection()->getDriverName()) {
            'sqlsrv' => "DATEDIFF(MINUTE, {$startColumn}, {$endColumn})",
            'mysql' => "TIMESTAMPDIFF(MINUTE, {$startColumn}, {$endColumn})",
            'sqlite' => "((julianday({$endColumn}) - julianday({$startColumn})) * 24 * 60)",
            default => "DATEDIFF(MINUTE, {$startColumn}, {$endColumn})",
        };
    }

    private function formatPointBreakdown($rows): array
    {
        $labels = [
            'fast_resolution' => 'Fast Resolution',
            'ontime_resolution' => 'On-Time Resolution',
            'late_resolution' => 'Late Resolution',
            'fcr_bonus' => 'FCR Bonus',
            'happy_customer' => 'Happy Customer',
            'unhappy_customer' => 'Unhappy Customer',
            'quest_bonus' => 'Quest Bonus',
        ];

        return collect($rows)
            ->map(fn ($row) => [
                'type' => $row->type,
                'label' => $labels[$row->type] ?? str_replace('_', ' ', ucfirst($row->type)),
                'points' => (int) $row->points,
                'count' => (int) $row->count,
            ])
            ->sortByDesc(fn ($row) => abs($row['points']))
            ->values()
            ->toArray();
    }

    private function extractLastOrgPathSegment(?string $orgPath): string
    {
        if (!$orgPath) {
            return 'No Org Path';
        }

        $segments = preg_split('/\s*>\s*/', $orgPath);
        $segments = array_values(array_filter($segments, fn ($segment) => filled($segment)));

        return $segments ? end($segments) : $orgPath;
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'total');
        $user = Auth::user();
        $year = $request->input('year');
        $month = $request->input('month');

        // Reuse company filtering logic
        $user->load('roles.companies');
        $allowedCompanyIds = collect();
        foreach ($user->roles as $role) {
            if ($role->companies) {
                $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
            }
        }
        if ($user->company_id) $allowedCompanyIds->push($user->company_id);
        $allowedCompanyIds = $allowedCompanyIds->unique();

        $query = Ticket::query();
        if ($user->hasRole('User')) $query->where('reporter_id', $user->id);
        if ($allowedCompanyIds->isEmpty()) $query->whereRaw('1 = 0');
        else $query->whereIn('company_id', $allowedCompanyIds);

        // Apply filters
        if ($year) $query->whereYear('created_at', $year);
        if ($month) $query->whereMonth('created_at', $month);

        switch ($type) {
            case 'waiting_alarm':
                $agingDays = (int) Setting::get('waiting_aging_alarm_days', 3);
                $alarmDate = Carbon::now('Asia/Manila')->subDays($agingDays);
                $query->whereIn('status', ['waiting_service_provider', 'waiting_client_feedback'])
                      ->where('updated_at', '<=', $alarmDate);
                $filename = "aged_waiting_tickets";
                break;
            case 'urgent':
                $query->where(function($q) {
                    $q->where('priority', 'urgent')
                      ->orWhereHas('item', function($iq) {
                          $iq->where('priority', 'Urgent');
                      });
                })->where('status', '!=', 'closed');
                $filename = "urgent_tickets";
                break;
            case 'new':
                $query->where('status', 'open')
                      ->whereNull('category_id')
                      ->whereNull('sub_category_id')
                      ->whereNull('item_id')
                      ->whereNull('assignee_id');
                $filename = "new_tickets";
                break;
            case 'open':
                $query->whereNotIn('status', ['resolved', 'closed']);
                $filename = "open_tickets";
                break;
            case 'closed':
                $query->whereIn('status', ['resolved', 'closed']);
                $filename = "closed_tickets";
                break;
            default:
                $filename = "total_tickets";
        }

        $tickets = $query->with(['assignee:id,name', 'company:id,name', 'item', 'parent:id,ticket_key'])
            ->latest()
            ->take(500) // Increase limit for export
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = ['Ticket ID', 'Title', 'Status', 'Item Priority', 'Company', 'Assignee', 'Parent Ticket', 'Created At'];
        foreach ($headers as $index => $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($colLetter . '1', $header);
        }

        // Data
        foreach ($tickets as $rowIndex => $ticket) {
            $rowNum = $rowIndex + 2;
            $sheet->setCellValue('A' . $rowNum, $ticket->ticket_key);
            $sheet->setCellValue('B' . $rowNum, $ticket->title);
            $sheet->setCellValue('C' . $rowNum, str_replace('_', ' ', $ticket->status));
            $sheet->setCellValue('D' . $rowNum, $ticket->item?->priority ?? 'N/A');
            $sheet->setCellValue('E' . $rowNum, $ticket->company?->name ?? 'N/A');
            $sheet->setCellValue('F' . $rowNum, $ticket->assignee?->name ?? 'Unassigned');
            $sheet->setCellValue('G' . $rowNum, $ticket->parent?->ticket_key ?? '');
            $sheet->setCellValue('H' . $rowNum, $ticket->created_at->format('Y-m-d H:i:s'));
        }

        $writer = new Xlsx($spreadsheet);
        $fullFilename = $filename . '_' . date('Y-m-d_His') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'. $fullFilename .'"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }
    
    private function formatAction($history)
    {
        if ($history->column_changed === 'status') {
             return "changed status to " . ucfirst($history->new_value);
        }
        if ($history->column_changed === 'priority') {
             return "changed priority to " . ucfirst($history->new_value);
        }
         if ($history->column_changed === 'assignee_id') {
             return "reassigned ticket";
        }
        return "updated " . str_replace('_', ' ', $history->column_changed);
    }
}
