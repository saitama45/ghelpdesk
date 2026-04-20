<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\User;
use App\Models\PosRequest;
use App\Models\SapRequest;
use Illuminate\Support\Facades\Auth;

class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('query');
        $user = Auth::user();

        if (empty($query) || strlen($query) < 2) {
            return response()->json(['menus' => [], 'tickets' => [], 'users' => []]);
        }

        $results = [
            'menus'        => $this->searchMenus($query, $user),
            'tickets'      => $this->searchTickets($query, $user),
            'pos_requests' => $this->searchPosRequests($query, $user),
            'sap_requests' => $this->searchSapRequests($query, $user),
            'users'        => $this->searchUsers($query, $user),
        ];

        return response()->json($results);
    }

    private function searchMenus($query, $user)
    {
        $allMenus = [
            ['name' => 'Dashboard', 'url' => route('dashboard', [], false), 'path' => 'Dashboard', 'permission' => null],
            ['name' => 'Project Tracker', 'url' => route('projects.index', [], false), 'path' => 'Project Tracker', 'permission' => 'projects.view'],
            ['name' => 'DTR', 'url' => route('attendance.index', [], false), 'path' => 'Admin Task > DTR', 'permission' => 'attendance.view'],
            ['name' => 'Attendance Logs', 'url' => route('attendance.logs', [], false), 'path' => 'Admin Task > Attendance Logs', 'permission' => 'attendance.logs'],
            ['name' => 'Tickets', 'url' => route('tickets.index', [], false), 'path' => 'Services > Tickets', 'permission' => 'tickets.view'],
            ['name' => 'POS Requests', 'url' => route('pos-requests.index', [], false), 'path' => 'Services > POS Requests', 'permission' => 'pos_requests.view'],
            ['name' => 'SAP Requests', 'url' => route('sap-requests.index', [], false), 'path' => 'Services > SAP Requests', 'permission' => 'sap_requests.view'],
            ['name' => 'Scheduling', 'url' => route('schedules.index', [], false), 'path' => 'Admin Task > Scheduling', 'permission' => 'schedules.view'],
            ['name' => 'Presence', 'url' => route('presence.index', [], false), 'path' => 'Admin Task > Presence', 'permission' => 'presence.view'],
            ['name' => 'Companies', 'url' => route('companies.index', [], false), 'path' => 'References > Companies', 'permission' => 'companies.view'],
            ['name' => 'Clusters', 'url' => route('clusters.index', [], false), 'path' => 'References > Clusters', 'permission' => 'clusters.view'],
            ['name' => 'Stores', 'url' => route('stores.index', [], false), 'path' => 'References > Stores', 'permission' => 'stores.view'],
            ['name' => 'Activity Templates', 'url' => route('activity-templates.index', [], false), 'path' => 'References > Activity Templates', 'permission' => 'activity_templates.view'],
            ['name' => 'Categories', 'url' => route('categories.index', [], false), 'path' => 'References > Categories', 'permission' => 'categories.view'],
            ['name' => 'Sub-Categories', 'url' => route('sub-categories.index', [], false), 'path' => 'References > Sub-Categories', 'permission' => 'subcategories.view'],
            ['name' => 'Items', 'url' => route('items.index', [], false), 'path' => 'References > Items', 'permission' => 'items.view'],
            ['name' => 'Assets', 'url' => route('assets.index', [], false), 'path' => 'References > Assets', 'permission' => 'assets.view'],
            ['name' => 'Store Health Report', 'url' => route('reports.store-health', [], false), 'path' => 'Reports > Store Health', 'permission' => 'reports.store_health'],
            ['name' => 'SLA Performance Report', 'url' => route('reports.sla-performance', [], false), 'path' => 'Reports > SLA Performance', 'permission' => 'reports.sla_performance'],
            ['name' => 'Users', 'url' => route('users.index', [], false), 'path' => 'User Management > Users', 'permission' => 'users.view'],
            ['name' => 'Roles & Permissions', 'url' => route('roles.index', [], false), 'path' => 'User Management > Roles & Permissions', 'permission' => 'roles.view'],
            ['name' => 'System Settings', 'url' => route('settings.index', [], false), 'path' => 'Settings > System Settings', 'permission' => 'settings.view'],
            ['name' => 'Canned Messages', 'url' => route('canned-messages.index', [], false), 'path' => 'Settings > Canned Messages', 'permission' => 'canned_messages.view'],
            ['name' => 'My Profile', 'url' => route('profile.edit', [], false), 'path' => 'Settings > My Profile', 'permission' => null],
        ];

        $matchedMenus = [];
        $lowerQuery = strtolower($query);

        foreach ($allMenus as $menu) {
            if ($menu['permission'] === null || $user->can($menu['permission'])) {
                if (str_contains(strtolower($menu['name']), $lowerQuery) || str_contains(strtolower($menu['path']), $lowerQuery)) {
                    $matchedMenus[] = $menu;
                }
            }
        }

        return array_slice($matchedMenus, 0, 5); // Limit to 5 menu results
    }

    private function searchTickets($query, $user)
    {
        if (!$user->can('tickets.view')) {
            return [];
        }

        $ticketQuery = Ticket::query()->with(['assignee:id,name', 'company:id,name']);

        if ($user->hasRole('User')) {
            $ticketQuery->where('reporter_id', $user->id);
        }

        // Company Filtering
        $user->load('roles.companies');
        $allowedCompanyIds = collect();
        foreach ($user->roles as $role) {
            if ($role->companies) {
                $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
            }
        }
        if ($user->company_id) $allowedCompanyIds->push($user->company_id);
        $allowedCompanyIds = $allowedCompanyIds->unique();
        
        if (!$user->hasRole('User')) {
            if ($allowedCompanyIds->isEmpty()) {
                 $ticketQuery->whereRaw('1 = 0');
            } else {
                 $ticketQuery->whereIn('company_id', $allowedCompanyIds);
            }
        }

        // Search logic
        $ticketQuery->where(function($q) use ($query) {
            $q->where('ticket_key', 'like', "%{$query}%")
              ->orWhere('title', 'like', "%{$query}%")
              ->orWhere('description', 'like', "%{$query}%")
              ->orWhereHas('assignee', function($q) use ($query) {
                  $q->where('name', 'like', "%{$query}%");
              });
        });

        return $ticketQuery->latest()
            ->take(10)
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'company_name' => $ticket->company ? $ticket->company->name : 'N/A',
                    'assignee_name' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                ];
            });
    }

    private function searchPosRequests($query, $user)
    {
        if (!$user->can('pos_requests.view')) {
            return [];
        }

        return PosRequest::with(['company:id,name', 'requestType:id,name', 'user:id,name,email', 'ticket:id,ticket_key'])
            ->where(function ($q) use ($query) {
                $q->where('requester_name', 'like', "%{$query}%")
                  ->orWhere('requester_email', 'like', "%{$query}%")
                  ->orWhereHas('requestType', fn($r) => $r->where('name', 'like', "%{$query}%"))
                  ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$query}%"))
                  ->orWhereHas('ticket', fn($r) => $r->where('ticket_key', 'like', "%{$query}%"));
            })
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'request_type' => $r->requestType?->name ?? 'N/A',
                'company'      => $r->company?->name ?? 'N/A',
                'requester'    => $r->user?->name ?? $r->requester_name ?? 'Public',
                'status'       => $r->status,
                'ticket_key'   => $r->ticket?->ticket_key,
            ]);
    }

    private function searchSapRequests($query, $user)
    {
        if (!$user->can('sap_requests.view')) {
            return [];
        }

        return SapRequest::with(['company:id,name', 'requestType:id,name', 'user:id,name,email', 'ticket:id,ticket_key'])
            ->where(function ($q) use ($query) {
                $q->where('requester_name', 'like', "%{$query}%")
                  ->orWhere('requester_email', 'like', "%{$query}%")
                  ->orWhereHas('requestType', fn($r) => $r->where('name', 'like', "%{$query}%"))
                  ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$query}%"))
                  ->orWhereHas('ticket', fn($r) => $r->where('ticket_key', 'like', "%{$query}%"));
            })
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'request_type' => $r->requestType?->name ?? 'N/A',
                'company'      => $r->company?->name ?? 'N/A',
                'requester'    => $r->user?->name ?? $r->requester_name ?? 'Public',
                'status'       => $r->status,
                'ticket_key'   => $r->ticket?->ticket_key,
            ]);
    }

    private function searchUsers($query, $user)
    {
        if (!$user->can('users.view')) {
            return [];
        }

        // Filter users based on company if needed, here we'll just allow all if they have users.view permission
        return User::where('name', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->select('id', 'name', 'email', 'profile_photo')
            ->take(5)
            ->get()
            ->toArray();
    }
}
