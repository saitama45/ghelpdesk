<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Ticket;
use App\Models\User;
use App\Models\PosRequest;
use App\Models\SapRequest;
use App\Models\RequestType;
use App\Models\Project;
use App\Models\Asset;
use App\Models\StockIn;
use App\Models\Schedule;
use App\Models\AttendanceLog;
use App\Models\Scopes\ActiveEntityScope;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Auth;

class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('query');
        $tab   = $request->input('tab', 'all');
        $sort  = $request->input('sort', 'relevance');
        $user  = Auth::user();

        if (empty($query) || strlen($query) < 2) {
            return response()->json($this->emptyResults());
        }

        $entityContext = $this->entitySearchContext($user);

        $results = $this->emptyResults();

        if ($tab === 'all' || $tab === 'navigation') {
            $results['menus'] = $this->searchMenus($query, $user);
        }
        if ($tab === 'all' || $tab === 'tickets') {
            $results['tickets'] = $this->searchTickets($query, $user, $sort, $entityContext);
        }
        if ($tab === 'all' || $tab === 'requests') {
            $results['requests'] = $this->searchRequests($query, $user, $sort, $entityContext);
        }
        if ($tab === 'all' || $tab === 'users') {
            $results['users'] = $this->searchUsers($query, $user, $sort);
        }
        if ($tab === 'all' || $tab === 'forms') {
            $results['forms'] = $this->searchRequestTypes($query, $user, $sort);
        }
        if ($tab === 'all' || $tab === 'projects') {
            $results['projects'] = $this->searchProjects($query, $user, $sort, $entityContext);
        }
        if ($tab === 'all' || $tab === 'inventory') {
            $results['inventory'] = $this->searchInventory($query, $user, $sort, $entityContext);
        }
        if ($tab === 'all' || $tab === 'schedules') {
            $results['schedules'] = $this->searchSchedules($query, $user, $sort, $entityContext);
        }
        if ($tab === 'all' || $tab === 'attendance') {
            $results['attendance'] = $this->searchAttendance($query, $user, $sort);
        }

        return response()->json($results);
    }

    private function emptyResults(): array
    {
        return [
            'menus'      => [],
            'tickets'    => [],
            'requests'   => [],
            'users'      => [],
            'forms'      => [],
            'projects'   => [],
            'inventory'  => [],
            'schedules'  => [],
            'attendance' => [],
        ];
    }

    private function applySort(Builder $query, string $sort, string $relevanceCase, array $bindings = []): Builder
    {
        return match ($sort) {
            'date_created'  => $query->orderBy('created_at', 'desc'),
            'last_modified' => $query->orderBy('updated_at', 'desc'),
            default         => $query->orderByRaw($relevanceCase, $bindings)->orderBy('created_at', 'desc'),
        };
    }

    private function entitySearchContext(User $user): array
    {
        $user->loadMissing('departmentReference:id,code');
        $companies = CompanyContext::accessibleCompanies($user);

        return [
            'search_across_entities' => strcasecmp(trim((string) $user->departmentReference?->code), 'TAS') === 0,
            'accessible_ids' => $companies->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'company_names' => $companies->mapWithKeys(
                fn ($company) => [(int) $company->id => $company->name]
            )->all(),
        ];
    }

    private function applyEntitySearchScope(Builder $query, array $entityContext): Builder
    {
        if (!$entityContext['search_across_entities']) {
            return $query;
        }

        $query->withoutGlobalScope(ActiveEntityScope::class);
        $companyColumn = $query->getModel()->qualifyColumn('company_id');

        if (empty($entityContext['accessible_ids'])) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($companyColumn, $entityContext['accessible_ids']);
    }

    private function entityResultFields($record, array $entityContext): array
    {
        $companyId = $record->company_id ? (int) $record->company_id : null;

        return [
            'company_id' => $companyId,
            'company_name' => $companyId ? ($entityContext['company_names'][$companyId] ?? 'Unknown Entity') : null,
        ];
    }

    private function searchMenus($query, $user): array
    {
        $allMenus = [
            ['name' => 'Dashboard',              'url' => route('dashboard', [], false),              'path' => 'Dashboard',                        'permission' => null],
            ['name' => 'Project Tracker',        'url' => route('projects.index', [], false),         'path' => 'Project Tracker',                  'permission' => 'projects.view'],
            ['name' => 'DTR',                    'url' => route('attendance.index', [], false),        'path' => 'Administrative > DTR',             'permission' => 'attendance.view'],
            ['name' => 'Attendance Logs',        'url' => route('attendance.logs', [], false),         'path' => 'Administrative > Attendance Logs', 'permission' => 'attendance.logs'],
            ['name' => 'NPC Status',             'url' => route('npc-statuses.index', [], false),      'path' => 'Monitoring > NPC Status',          'permission' => 'npc_status.view'],
            ['name' => 'WIGS',                   'url' => route('wigs.index', [], false),              'path' => 'Monitoring > WIGS',                'permission' => 'wigs.view'],
            ['name' => 'Tickets',                'url' => route('tickets.index', [], false),           'path' => 'Services > Tickets',               'permission' => 'tickets.view'],
            ['name' => 'Task Board',             'url' => route('task-boards.index', [], false),       'path' => 'Services > Task Board',            'permission' => 'task_boards.view'],
            ['name' => 'POS Requests',           'url' => route('pos-requests.index', [], false),      'path' => 'Services > POS Requests',          'permission' => 'pos_requests.view'],
            ['name' => 'SAP Requests',           'url' => route('sap-requests.index', [], false),      'path' => 'Services > SAP Requests',          'permission' => 'sap_requests.view'],
            ['name' => 'Stock In',               'url' => route('stock-ins.index', [], false),         'path' => 'Inventory > Stock In',             'permission' => 'stock_ins.view'],
            ['name' => 'Assets',                 'url' => route('assets.index', [], false),            'path' => 'Inventory > Assets',               'permission' => 'assets.view'],
            ['name' => 'Inventory Report',       'url' => route('reports.inventory', [], false),       'path' => 'Inventory > Inventory Report',     'permission' => 'reports.inventory'],
            ['name' => 'Scheduling',             'url' => route('schedules.index', [], false),         'path' => 'Administrative > Scheduling',      'permission' => 'schedules.view'],
            ['name' => 'SLA Performance Report', 'url' => route('reports.sla-performance', [], false), 'path' => 'Reports > SLA Performance',        'permission' => 'reports.sla_performance'],
            ['name' => 'Departments',            'url' => route('departments.index', [], false),       'path' => 'References > Departments',         'permission' => 'departments.view'],
            ['name' => 'Users',                  'url' => route('users.index', [], false),             'path' => 'User Management > Users',          'permission' => 'users.view'],
            ['name' => 'Roles & Permissions',    'url' => route('roles.index', [], false),             'path' => 'User Management > Roles & Permissions', 'permission' => 'roles.view'],
            ['name' => 'System Settings',        'url' => route('settings.index', [], false),          'path' => 'Settings > System Settings',       'permission' => 'settings.view'],
            ['name' => 'Ticket Archive',         'url' => route('ticket-archive.index', [], false),    'path' => 'Settings > Ticket Archive',        'permission' => 'settings.view'],
            ['name' => 'Canned Messages',        'url' => route('canned-messages.index', [], false),   'path' => 'Settings > Canned Messages',       'permission' => 'canned_messages.view'],
            ['name' => 'My Profile',             'url' => route('profile.edit', [], false),            'path' => 'Settings > My Profile',            'permission' => null],
        ];

        $matched   = [];
        $lowerQuery = strtolower($query);

        foreach ($allMenus as $menu) {
            if ($menu['permission'] === null || $user->can($menu['permission'])) {
                if (str_contains(strtolower($menu['name']), $lowerQuery) ||
                    str_contains(strtolower($menu['path']), $lowerQuery)) {
                    $matched[] = $menu;
                }
            }
        }

        return array_slice($matched, 0, 5);
    }

    private function searchTickets($query, $user, string $sort, array $entityContext): array
    {
        if (!$user->can('tickets.view')) {
            return [];
        }

        $q = $this->applyEntitySearchScope(Ticket::query(), $entityContext)
            ->with(['assignee:id,name', 'company:id,name']);

        if ($user->hasRole('User')) {
            $q->where('reporter_id', $user->id);
        }

        if (!$user->hasRole('User')) {
            if (empty($entityContext['accessible_ids'])) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('tickets.company_id', $entityContext['accessible_ids']);
            }
        }

        $q->where(function ($sub) use ($query) {
            $sub->where('ticket_key', 'like', "%{$query}%")
                ->orWhere('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->orWhereHas('assignee', fn($r) => $r->where('name', 'like', "%{$query}%"));
        });

        $relevance  = "CASE WHEN ticket_key = ? THEN 0 WHEN ticket_key LIKE ? THEN 1 WHEN title LIKE ? THEN 2 WHEN title LIKE ? THEN 3 ELSE 4 END";
        $bindings   = [$query, "{$query}%", "{$query}%", "%{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(10)
            ->get()
            ->map(fn($t) => [
                'id'            => $t->id,
                'ticket_key'    => $t->ticket_key,
                'title'         => $t->title,
                'status'        => $t->status,
                'company_name'  => $t->company?->name ?? 'N/A',
                'assignee_name' => $t->assignee?->name ?? 'Unassigned',
                'created_at'    => $t->created_at?->toDateTimeString(),
                'updated_at'    => $t->updated_at?->toDateTimeString(),
                ...$this->entityResultFields($t, $entityContext),
            ])
            ->toArray();
    }

    private function searchRequests($query, $user, string $sort, array $entityContext): array
    {
        $results = [];

        if ($user->can('pos_requests.view')) {
            $posQ = $this->applyEntitySearchScope(PosRequest::query(), $entityContext)
                ->with(['company:id,name', 'requestType:id,name', 'user:id,name', 'ticket:id,ticket_key'])
                ->where(function ($q) use ($query) {
                    $q->where('requester_name', 'like', "%{$query}%")
                      ->orWhere('requester_email', 'like', "%{$query}%")
                      ->orWhereHas('requestType', fn($r) => $r->where('name', 'like', "%{$query}%"))
                      ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$query}%"))
                      ->orWhereHas('ticket', fn($r) => $r->where('ticket_key', 'like', "%{$query}%"));
                });

            $relevance = "CASE WHEN requester_name LIKE ? THEN 0 WHEN requester_name LIKE ? THEN 1 ELSE 2 END";
            $bindings  = ["{$query}%", "%{$query}%"];

            $pos = $this->applySort($posQ, $sort, $relevance, $bindings)
                ->take(5)
                ->get()
                ->map(fn($r) => [
                    'id'           => $r->id,
                    'source'       => 'pos',
                    'request_type' => $r->requestType?->name ?? 'N/A',
                    'company'      => $r->company?->name ?? 'N/A',
                    'requester'    => $r->user?->name ?? $r->requester_name ?? 'Public',
                    'status'       => $r->status,
                    'ticket_key'   => $r->ticket?->ticket_key,
                    'created_at'   => $r->created_at?->toDateTimeString(),
                    'updated_at'   => $r->updated_at?->toDateTimeString(),
                    ...$this->entityResultFields($r, $entityContext),
                ]);
            $results = array_merge($results, $pos->toArray());
        }

        if ($user->can('sap_requests.view')) {
            $sapQ = $this->applyEntitySearchScope(SapRequest::query(), $entityContext)
                ->with(['company:id,name', 'requestType:id,name', 'user:id,name', 'ticket:id,ticket_key'])
                ->where(function ($q) use ($query) {
                    $q->where('requester_name', 'like', "%{$query}%")
                      ->orWhere('requester_email', 'like', "%{$query}%")
                      ->orWhereHas('requestType', fn($r) => $r->where('name', 'like', "%{$query}%"))
                      ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$query}%"))
                      ->orWhereHas('ticket', fn($r) => $r->where('ticket_key', 'like', "%{$query}%"));
                });

            $relevance = "CASE WHEN requester_name LIKE ? THEN 0 WHEN requester_name LIKE ? THEN 1 ELSE 2 END";
            $bindings  = ["{$query}%", "%{$query}%"];

            $sap = $this->applySort($sapQ, $sort, $relevance, $bindings)
                ->take(5)
                ->get()
                ->map(fn($r) => [
                    'id'           => $r->id,
                    'source'       => 'sap',
                    'request_type' => $r->requestType?->name ?? 'N/A',
                    'company'      => $r->company?->name ?? 'N/A',
                    'requester'    => $r->user?->name ?? $r->requester_name ?? 'Public',
                    'status'       => $r->status,
                    'ticket_key'   => $r->ticket?->ticket_key,
                    'created_at'   => $r->created_at?->toDateTimeString(),
                    'updated_at'   => $r->updated_at?->toDateTimeString(),
                    ...$this->entityResultFields($r, $entityContext),
                ]);
            $results = array_merge($results, $sap->toArray());
        }

        return $results;
    }

    private function searchUsers($query, $user, string $sort): array
    {
        if (!$user->can('users.view')) {
            return [];
        }

        $q = User::where(function ($sub) use ($query) {
            $sub->where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%");
        });

        $relevance = "CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 WHEN email LIKE ? THEN 2 ELSE 3 END";
        $bindings  = ["{$query}%", "%{$query}%", "{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(5)
            ->get(['id', 'name', 'email', 'profile_photo', 'created_at', 'updated_at'])
            ->toArray();
    }

    private function searchRequestTypes($query, $user, string $sort): array
    {
        if (!$user->can('pos_requests.view') && !$user->can('sap_requests.view')) {
            return [];
        }

        $q = RequestType::where('is_active', true)
            ->where(function ($sub) use ($query) {
                $sub->where('name', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%");
            })
            ->where(function ($sub) {
                $sub->whereJsonContains('request_for', 'POS')
                    ->orWhereJsonContains('request_for', 'SAP');
            });

        $relevance = "CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 WHEN code LIKE ? THEN 2 ELSE 3 END";
        $bindings  = ["{$query}%", "%{$query}%", "{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(8)
            ->get(['id', 'name', 'code', 'request_for', 'created_at', 'updated_at'])
            ->map(fn($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'code'        => $r->code,
                'request_for' => $r->request_for,
                'created_at'  => $r->created_at?->toDateTimeString(),
                'updated_at'  => $r->updated_at?->toDateTimeString(),
            ])
            ->toArray();
    }

    private function searchProjects($query, $user, string $sort, array $entityContext): array
    {
        if (!$user->can('projects.view')) {
            return [];
        }

        $q = $this->applyEntitySearchScope(Project::query(), $entityContext)
            ->with('store:id,name')
            ->where(function ($sub) use ($query) {
                $sub->where('name', 'like', "%{$query}%")
                    ->orWhere('remarks', 'like', "%{$query}%")
                    ->orWhere('status', 'like', "%{$query}%")
                    ->orWhereHas('store', fn($r) => $r->where('name', 'like', "%{$query}%"));
            });

        $relevance = "CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 WHEN status LIKE ? THEN 2 ELSE 3 END";
        $bindings  = ["{$query}%", "%{$query}%", "%{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(8)
            ->get()
            ->map(fn($p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'status'     => $p->status,
                'store_name' => $p->store?->name ?? 'N/A',
                'created_at' => $p->created_at?->toDateTimeString(),
                'updated_at' => $p->updated_at?->toDateTimeString(),
                ...$this->entityResultFields($p, $entityContext),
            ])
            ->toArray();
    }

    private function searchInventory($query, $user, string $sort, array $entityContext): array
    {
        if (!$user->can('assets.view')) {
            return [];
        }

        $results = [];

        // Assets
        $assetQ = $this->applyEntitySearchScope(Asset::query(), $entityContext)
            ->where(function ($sub) use ($query) {
                $sub->where('item_code', 'like', "%{$query}%")
                    ->orWhere('brand', 'like', "%{$query}%")
                    ->orWhere('model', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            });

        $assetRelevance  = "CASE WHEN item_code LIKE ? THEN 0 WHEN item_code LIKE ? THEN 1 WHEN brand LIKE ? THEN 2 WHEN model LIKE ? THEN 3 ELSE 4 END";
        $assetBindings   = ["{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];

        $assets = $this->applySort($assetQ, $sort, $assetRelevance, $assetBindings)
            ->take(5)
            ->get(['id', 'item_code', 'brand', 'model', 'description', 'company_id', 'created_at', 'updated_at'])
            ->map(fn($a) => [
                'id'          => $a->id,
                'type'        => 'asset',
                'item_code'   => $a->item_code,
                'label'       => trim("{$a->brand} {$a->model}"),
                'description' => $a->description,
                'created_at'  => $a->created_at?->toDateTimeString(),
                'updated_at'  => $a->updated_at?->toDateTimeString(),
                ...$this->entityResultFields($a, $entityContext),
            ]);

        $results = array_merge($results, $assets->toArray());

        // Stock Ins (uses stock_ins.view permission under assets.view umbrella)
        if ($user->can('stock_ins.view')) {
            $stockQ = $this->applyEntitySearchScope(StockIn::query(), $entityContext)
                ->where(function ($sub) use ($query) {
                    $sub->where('dr_no', 'like', "%{$query}%")
                        ->orWhere('vendor', 'like', "%{$query}%")
                        ->orWhere('serial_no', 'like', "%{$query}%")
                        ->orWhere('received_by', 'like', "%{$query}%");
                });

            $stockRelevance = "CASE WHEN dr_no LIKE ? THEN 0 WHEN dr_no LIKE ? THEN 1 WHEN vendor LIKE ? THEN 2 ELSE 3 END";
            $stockBindings  = ["{$query}%", "%{$query}%", "%{$query}%"];

            $stocks = $this->applySort($stockQ, $sort, $stockRelevance, $stockBindings)
                ->take(5)
                ->get(['id', 'dr_no', 'vendor', 'serial_no', 'received_by', 'status', 'company_id', 'created_at', 'updated_at'])
                ->map(fn($s) => [
                    'id'          => $s->id,
                    'type'        => 'stock_in',
                    'dr_no'       => $s->dr_no,
                    'vendor'      => $s->vendor,
                    'serial_no'   => $s->serial_no,
                    'status'      => $s->status,
                    'created_at'  => $s->created_at?->toDateTimeString(),
                    'updated_at'  => $s->updated_at?->toDateTimeString(),
                    ...$this->entityResultFields($s, $entityContext),
                ]);

            $results = array_merge($results, $stocks->toArray());
        }

        return $results;
    }

    private function searchSchedules($query, $user, string $sort, array $entityContext): array
    {
        if (!$user->can('schedules.view')) {
            return [];
        }

        $q = $this->applyEntitySearchScope(Schedule::query(), $entityContext)
            ->with('user:id,name')
            ->where(function ($sub) use ($query) {
                $sub->where('status', 'like', "%{$query}%")
                    ->orWhere('remarks', 'like', "%{$query}%")
                    ->orWhereHas('user', fn($r) => $r->where('name', 'like', "%{$query}%"));
            });

        $relevance = "CASE WHEN status LIKE ? THEN 0 WHEN status LIKE ? THEN 1 ELSE 2 END";
        $bindings  = ["{$query}%", "%{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(8)
            ->get()
            ->map(fn($s) => [
                'id'         => $s->id,
                'user_name'  => $s->user?->name ?? 'Unknown',
                'status'     => $s->status,
                'start_time' => $s->start_time?->toDateTimeString(),
                'end_time'   => $s->end_time?->toDateTimeString(),
                'created_at' => $s->created_at?->toDateTimeString(),
                'updated_at' => $s->updated_at?->toDateTimeString(),
                ...$this->entityResultFields($s, $entityContext),
            ])
            ->toArray();
    }

    private function searchAttendance($query, $user, string $sort): array
    {
        if (!$user->can('attendance.view') && !$user->can('attendance.logs')) {
            return [];
        }

        $q = AttendanceLog::with('user:id,name')
            ->where(function ($sub) use ($query) {
                $sub->where('type', 'like', "%{$query}%")
                    ->orWhere('location_client', 'like', "%{$query}%")
                    ->orWhereHas('user', fn($r) => $r->where('name', 'like', "%{$query}%"));
            });

        $relevance = "CASE WHEN type LIKE ? THEN 0 WHEN type LIKE ? THEN 1 ELSE 2 END";
        $bindings  = ["{$query}%", "%{$query}%"];

        return $this->applySort($q, $sort, $relevance, $bindings)
            ->take(8)
            ->get()
            ->map(fn($a) => [
                'id'              => $a->id,
                'user_name'       => $a->user?->name ?? 'Unknown',
                'type'            => $a->type,
                'log_time'        => $a->log_time?->toDateTimeString(),
                'location_client' => $a->location_client,
                'created_at'      => $a->created_at?->toDateTimeString(),
                'updated_at'      => $a->updated_at?->toDateTimeString(),
            ])
            ->toArray();
    }
}
