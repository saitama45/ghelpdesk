<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $year = $request->input('year');
        $month = $request->input('month');
        
        // Define base query based on role
        $query = Ticket::query();
        
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
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
        
        if ($allowedCompanyIds->isEmpty()) {
             $query->whereRaw('1 = 0');
        } else {
             $query->whereIn('company_id', $allowedCompanyIds);
        }

        // Apply Time Filters to the base query for Stats and Recent Tickets
        $filteredQuery = clone $query;
        if ($year) {
            $filteredQuery->whereYear('created_at', $year);
        }
        if ($month) {
            $filteredQuery->whereMonth('created_at', $month);
        }

        // Stats (Filtered)
        $stats = [
            'total' => (clone $filteredQuery)->count(),
            'open' => (clone $filteredQuery)->where('status', 'open')->count(),
            'in_progress' => (clone $filteredQuery)->where('status', 'in_progress')->count(),
            'closed' => (clone $filteredQuery)->where('status', 'closed')->count(),
            'waiting' => (clone $filteredQuery)->where('status', 'waiting')->count(),
            'unassigned' => (clone $filteredQuery)->whereNull('assignee_id')->count(),
        ];
        
        // Recent/Filtered Tickets
        $recentTickets = (clone $filteredQuery)
            ->with(['reporter:id,name', 'assignee:id,name', 'company:id,name'])
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
                    'created_at' => $ticket->created_at->diffForHumans(),
                    'reporter' => $ticket->reporter ? $ticket->reporter->name : 'Unknown',
                    'assignee' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                ];
            });

        // My Tickets (Real-time, not affected by month filter)
        $myTickets = Ticket::query()
            ->where('assignee_id', $user->id)
            ->where('status', '!=', 'closed')
            ->with(['company:id,name'])
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
                ];
            });

        // Activity Stream (Real-time)
        $histories = TicketHistory::query()
            ->with(['user:id,name,profile_photo', 'ticket:id,ticket_key,title'])
            ->whereHas('ticket', function ($q) use ($user, $allowedCompanyIds) {
                if ($user->hasRole('User')) $q->where('reporter_id', $user->id);
                if ($allowedCompanyIds->isEmpty()) $q->whereRaw('1 = 0');
                else $q->whereIn('company_id', $allowedCompanyIds);
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
                if ($user->hasRole('User')) $q->where('reporter_id', $user->id);
                if ($allowedCompanyIds->isEmpty()) $q->whereRaw('1 = 0');
                else $q->whereIn('company_id', $allowedCompanyIds);
            })
            ->latest()->take(10)->get()
            ->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'user' => $comment->user ? $comment->user->name : 'Unknown User',
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

        // Dropdown Data
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

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentTickets' => $recentTickets,
            'myTickets' => $myTickets,
            'recentActivity' => $activities,
            'filters' => [
                'year' => (int)$year ?: null,
                'month' => (int)$month ?: null,
            ],
            'years' => $years,
            'months' => $months,
        ]);
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
