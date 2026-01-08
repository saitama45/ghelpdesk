<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        
        // Define base query based on role - mirroring TicketController logic
        $query = Ticket::query();
        
        // If user has 'User' role, only show tickets they reported
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        }

        // Filter by user's company access
        // Note: In TicketController, Admin check was commented out to force company check?
        // Let's follow the active logic in TicketController which calculates allowed companies.
        
        // Get companies from roles
        $user->load('roles.companies');
        $allowedCompanyIds = collect();
        
        foreach ($user->roles as $role) {
            if ($role->companies) {
                $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
            }
        }
        
        // Also include direct company assignment
        if ($user->company_id) {
            $allowedCompanyIds->push($user->company_id);
        }
        
        $allowedCompanyIds = $allowedCompanyIds->unique();
        
        // If no companies are allowed, show no tickets (unless restricted by User role above, which adds AND condition? 
        // TicketController logic: if User role -> reporter_id. THEN it proceeds to company logic check (not in else block).
        // Wait, TicketController has:
        // if ($user->hasRole('User')) { ... }
        // ...
        // $allowedCompanyIds = ...
        // if empty -> 1=0 else whereIn
        // So BOTH conditions apply if user is 'User'.
        
        if ($allowedCompanyIds->isEmpty()) {
             // If user has no company access, they shouldn't see any tickets regardless of reporter_id?
             // Or does reporter_id override? TicketController applies BOTH.
             // If I am a User but have no company, I see nothing.
             $query->whereRaw('1 = 0');
        } else {
             $query->whereIn('company_id', $allowedCompanyIds);
        }

        // Stats
        $stats = [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->where('status', 'open')->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'waiting' => (clone $query)->where('status', 'waiting')->count(),
        ];
        
        // Recent Tickets
        $recentTickets = (clone $query)
            ->with(['reporter:id,name', 'assignee:id,name'])
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
                    'created_at' => $ticket->created_at->diffForHumans(),
                    'reporter' => $ticket->reporter ? $ticket->reporter->name : 'Unknown',
                    'assignee' => $ticket->assignee ? $ticket->assignee->name : 'Unassigned',
                ];
            });

        // Recent Activity
        // We need to apply the same filtering to tickets in history
        $recentActivity = TicketHistory::query()
            ->with(['user:id,name', 'ticket:id,ticket_key,title'])
            ->whereHas('ticket', function ($q) use ($user, $allowedCompanyIds) {
                // Apply same logic as above
                if ($user->hasRole('User')) {
                    $q->where('reporter_id', $user->id);
                }
                
                if ($allowedCompanyIds->isEmpty()) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('company_id', $allowedCompanyIds);
                }
            })
            ->latest('changed_at')
            ->take(10)
            ->get()
            ->map(function ($history) {
                return [
                    'id' => $history->id,
                    'user' => $history->user ? $history->user->name : 'System',
                    'action' => $this->formatAction($history),
                    'ticket_id' => $history->ticket_id,
                    'ticket_key' => $history->ticket ? $history->ticket->ticket_key : 'Unknown',
                    'ticket_title' => $history->ticket ? $history->ticket->title : 'Unknown Ticket',
                    'time' => $history->changed_at ? $history->changed_at->diffForHumans() : '',
                ];
            });

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentTickets' => $recentTickets,
            'recentActivity' => $recentActivity,
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
