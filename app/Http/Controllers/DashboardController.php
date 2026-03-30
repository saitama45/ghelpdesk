<?php

namespace App\Http\Controllers;

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

        $totalTicketsList = $listWithDetails(clone $filteredQuery);
        $openTicketsList = $listWithDetails((clone $filteredQuery)->where('status', 'open'));
        $newTicketsList = $listWithDetails(clone $newTicketsQuery);
        $closedTicketsList = $listWithDetails((clone $filteredQuery)->where('status', 'closed'));

        // Stats (Filtered)
        $stats = [
            'total' => (clone $filteredQuery)->count(),
            'open' => (clone $filteredQuery)->where('status', 'open')->count(),
            'new' => (clone $newTicketsQuery)->count(),
            'in_progress' => (clone $filteredQuery)->where('status', 'in_progress')->count(),
            'closed' => (clone $filteredQuery)->where('status', 'closed')->count(),
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
            'alarmedWaitingTickets' => $alarmedWaitingTickets,
            'urgentTickets' => $urgentTickets,
            'totalTicketsList' => $totalTicketsList,
            'openTicketsList' => $openTicketsList,
            'newTicketsList' => $newTicketsList,
            'closedTicketsList' => $closedTicketsList,
            'recentActivity' => $activities,
            'filters' => [
                'year' => (int)$year ?: null,
                'month' => (int)$month ?: null,
            ],
            'years' => $years,
            'months' => $months,
        ]);
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
                $query->where('status', 'open');
                $filename = "open_tickets";
                break;
            case 'closed':
                $query->where('status', 'closed');
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
