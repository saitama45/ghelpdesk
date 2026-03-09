<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Mail\NewTicketCreated;
use App\Mail\TicketAssigned;
use App\Mail\TicketCommentAdded;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Models\Company;
use App\Models\Store;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TicketController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Ticket::with([
            'reporter:id,name,profile_photo', 
            'assignee:id,name,profile_photo', 
            'company:id,name',
            'store:id,name', 
            'item:id,name,priority',
            'slaMetric', 
            'children' => function($q) {
                $q->select('id', 'parent_id', 'ticket_key', 'title', 'assignee_id', 'status')
                  ->with('assignee:id,name,profile_photo');
            }
        ])
            ->whereNull('parent_id'); // Only show top-level tickets

        // If user has 'User' role, only show tickets they reported
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        }

        // Filter by user's company access
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
        
        // If no companies are allowed, show no tickets
        if ($allowedCompanyIds->isEmpty()) {
            $query->whereRaw('1 = 0'); // This will return no results
        } else {
            $query->whereIn('company_id', $allowedCompanyIds);
        }

        // Apply status filters - default to 'open' if not provided
        $statusFilter = $request->get('status', 'open');
        
        if ($statusFilter !== 'all') {
            switch ($statusFilter) {
                case 'my_tickets':
                    $query->where(function($q) use ($user) {
                        $q->where('reporter_id', $user->id)
                          ->orWhere('assignee_id', $user->id);
                    });
                    break;
                case 'unassigned':
                    $query->whereNull('assignee_id');
                    break;
                default:
                    $query->where('status', $statusFilter);
                    break;
            }
        }

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhere('ticket_key', 'like', "%{$request->search}%")
                  ->orWhereHas('assignee', function($aq) use ($request) {
                      $aq->where('name', 'like', "%{$request->search}%");
                  })
                  ->orWhereHas('children', function($cq) use ($request) {
                      $cq->where('title', 'like', "%{$request->search}%")
                        ->orWhere('description', 'like', "%{$request->search}%")
                        ->orWhere('ticket_key', 'like', "%{$request->search}%")
                        ->orWhereHas('assignee', function($aq) use ($request) {
                            $aq->where('name', 'like', "%{$request->search}%");
                        });
                  });
            });
        }
        
        $query->orderBy('created_at', 'desc');
        $tickets = $query->paginate($request->get('per_page', 10))->withQueryString();
        $staff = User::whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'staff' => $staff,
            'companies' => $companies,
            'stores' => $stores,
            'filters' => [
                'status' => $statusFilter,
                'search' => $request->search
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTicketRequest $request)
    {
        $data = $request->validated();
        
        $ticket = DB::transaction(function () use ($data, $request) {
            $company = Company::where('id', $data['company_id'])->lockForUpdate()->first();
            $companyCode = $company->code;

            $maxNumber = Ticket::where('company_id', $data['company_id'])
                ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                ->get(['ticket_key'])
                ->map(function ($ticket) {
                    if (preg_match('/-(\d+)$/', $ticket->ticket_key, $matches)) {
                        return (int) $matches[1];
                    }
                    return 0;
                })
                ->max();

            $nextNumber = ($maxNumber ?? 0) + 1;
            
            $data['ticket_key'] = "{$companyCode}-{$nextNumber}";
            $data['reporter_id'] = auth()->id();
            // Ensure Manila Time
            $data['created_at'] = now('Asia/Manila');

            // Set priority from item
            if (isset($data['item_id'])) {
                $item = \App\Models\Item::find($data['item_id']);
                if ($item) {
                    $data['priority'] = strtolower($item->priority);
                }
            }

            $ticket = Ticket::create($data);

            // Create SLA Metrics
            \App\Models\TicketSlaMetric::create([
                'ticket_id' => $ticket->id,
                'response_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response'),
                'resolution_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution'),
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = $file->storeAs('ticket-attachments', $fileName, 'public');
                    
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_storage_path' => $filePath,
                        'file_size_bytes' => $file->getSize(),
                    ]);
                }
            }

            return $ticket;
        });

        $ticket->load(['reporter', 'assignee']);
        $sentTo = [];

        if ($ticket->reporter && $ticket->reporter->email) {
            Mail::to($ticket->reporter->email)->send(new NewTicketCreated($ticket, $ticket->reporter->name));
            $sentTo[] = $ticket->reporter->email;
        }

        if ($ticket->assignee && $ticket->assignee->email && $ticket->assignee->id !== $ticket->reporter_id) {
            $shouldNotifyAssignee = $ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists();
            if ($shouldNotifyAssignee) {
                Mail::to($ticket->assignee->email)->send(new NewTicketCreated($ticket, $ticket->assignee->name));
                $sentTo[] = $ticket->assignee->email;
            }
        }

        $usersToNotify = User::whereHas('roles', function($q) {
            $q->where('notify_on_ticket_create', true);
        })->get();

        foreach ($usersToNotify as $userToNotify) {
            if ($userToNotify->email && !in_array($userToNotify->email, $sentTo)) {
                Mail::to($userToNotify->email)->send(new NewTicketCreated($ticket, 'Admin'));
            }
        }

        return redirect()->back()->with('success', 'Ticket created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ticket $ticket)
    {
        $ticket->load([
            'comments' => function($query) {
                $query->with(['user:id,name,profile_photo', 'attachments'])->orderBy('created_at', 'desc');
            },
            'histories' => function($query) {
                $query->with('user:id,name,profile_photo')->orderBy('changed_at', 'desc');
            },
            'attachments', 
            'reporter', 
            'assignee', 
            'company',
            'store',
            'item',
            'parent',
            'schedule.store',
            'slaMetric',
            'children' => function($query) {
                $query->with(['schedule.store', 'reporter', 'assignee'])->orderBy('created_at', 'asc');
            }
        ]);

        if (!$ticket->slaMetric) {
            $ticket->slaMetric()->create([
                'response_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response'),
                'resolution_target_at' => \App\Services\SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution'),
            ]);
            $ticket->load('slaMetric');
        }

        $staff = User::whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        $users = User::active()->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $cannedMessages = \App\Models\CannedMessage::where('is_active', true)->orderBy('title')->get();
        
        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket,
            'staff' => $staff,
            'companies' => $companies,
            'users' => $users,
            'stores' => $stores,
            'cannedMessages' => $cannedMessages,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $validated = $request->validated();
        
        // Auto-update priority if item_id changed
        if (isset($validated['item_id']) && $validated['item_id'] != $ticket->item_id) {
            $item = \App\Models\Item::find($validated['item_id']);
            if ($item) {
                $validated['priority'] = strtolower($item->priority);
            }
        }

        $ticket->fill($validated);
        
        if ($ticket->isDirty()) {
            $userId = auth()->id();
            $dirty = $ticket->getDirty();
            $assigneeChanged = false;
            
            foreach ($dirty as $column => $newValue) {
                if ($column === 'assignee_id') $assigneeChanged = true;
                if ($column === 'updated_at') continue;
                
                $oldValue = $ticket->getOriginal($column);
                
                if ($column === 'company_id') {
                    $oldValue = Company::find($oldValue)?->name ?? $oldValue;
                    $newValue = Company::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'store_id') {
                    $oldValue = Store::find($oldValue)?->name ?? $oldValue;
                    $newValue = Store::find($newValue)?->name ?? $newValue;
                } elseif (in_array($column, ['assignee_id', 'reporter_id'])) {
                    $oldValue = User::find($oldValue)?->name ?? $oldValue;
                    $newValue = User::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'category_id') {
                    $oldValue = \App\Models\Category::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\Category::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'sub_category_id') {
                    $oldValue = \App\Models\SubCategory::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\SubCategory::find($newValue)?->name ?? $newValue;
                } elseif ($column === 'item_id') {
                    $oldValue = \App\Models\Item::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\Item::find($newValue)?->name ?? $newValue;
                }
                
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $userId,
                    'column_changed' => $column,
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                    'changed_at' => now('Asia/Manila'),
                ]);
            }
            
            $statusChanged = $ticket->isDirty('status');
            $newStatus = $ticket->status;
            $ticket->save();

            // SYNC STATUS TO PARENT AS A WHOLE
            if ($statusChanged && $ticket->parent_id) {
                $this->syncParentStatus($ticket->parent_id, $newStatus);
            }

            if ($assigneeChanged && $ticket->assignee_id) {
                $ticket->load('assignee');
                if ($ticket->assignee && $ticket->assignee->email) {
                    if ($ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists()) {
                        Mail::to($ticket->assignee->email)->send(new TicketAssigned($ticket, $ticket->assignee->name));
                    }
                }
            }
        }

        return redirect()->back()->with('success', 'Ticket updated successfully.');
    }

    /**
     * Internal helper to sync parent status based on children
     */
    private function syncParentStatus($parentId, $triggeredStatus)
    {
        $parent = Ticket::find($parentId);
        if (!$parent) return;

        $allChildren = Ticket::where('parent_id', $parentId)->get();
        
        if (in_array($triggeredStatus, ['resolved', 'closed'])) {
            // Check if ALL children are terminal (resolved or closed)
            $allDone = $allChildren->every(function($child) {
                return in_array($child->status, ['resolved', 'closed']);
            });

            if ($allDone) {
                // If all are terminal, set parent to the triggered status (resolved or closed)
                $parent->update(['status' => $triggeredStatus]);
            }
        } else {
            // If any child is updated to an active status, parent reflects it
            $parent->update(['status' => $triggeredStatus]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket)
    {
        foreach ($ticket->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->file_storage_path);
        }
        $ticket->delete();
        return redirect()->route('tickets.index')->with('success', 'Ticket deleted successfully.');
    }

    /**
     * Store a child ticket and link it to a schedule.
     */
    public function storeChild(Request $request, Ticket $ticket)
    {
        // Allow creating multiple child tickets as long as the parent is Open or In Progress
        if (!in_array($ticket->status, ['open', 'in_progress'])) {
            return redirect()->back()->withErrors(['error' => 'Child tickets can only be created for Open or In Progress tickets.']);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $childTicket = DB::transaction(function () use ($validated, $ticket) {
            $company = $ticket->company;
            $companyCode = $company->code;

            $maxNumber = Ticket::where('company_id', $ticket->company_id)
                ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                ->get(['ticket_key'])
                ->map(function ($t) {
                    if (preg_match('/-(\d+)$/', $t->ticket_key, $matches)) {
                        return (int) $matches[1];
                    }
                    return 0;
                })
                ->max();

            $nextNumber = ($maxNumber ?? 0) + 1;
            
            $childTicket = Ticket::create([
                'ticket_key' => "{$companyCode}-{$nextNumber}",
                'title' => "Child: {$ticket->title}",
                'description' => "Child of {$ticket->ticket_key}. Remarks: " . ($validated['remarks'] ?? ''),
                'type' => $ticket->type,
                'status' => 'open',
                'priority' => $ticket->priority,
                'severity' => $ticket->severity,
                'reporter_id' => auth()->id(),
                'assignee_id' => $validated['user_id'],
                'company_id' => $ticket->company_id,
                'store_id' => $ticket->store_id,
                'category_id' => $ticket->category_id,
                'sub_category_id' => $ticket->sub_category_id,
                'item_id' => $ticket->item_id,
                'parent_id' => $ticket->id,
                'created_at' => now('Asia/Manila'),
            ]);

            Schedule::create([
                'ticket_id' => $childTicket->id,
                'user_id' => $validated['user_id'],
                'store_id' => $ticket->store_id,
                'status' => $validated['status'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'pickup_start' => $validated['pickup_start'],
                'pickup_end' => $validated['pickup_end'],
                'backlogs_start' => $validated['backlogs_start'],
                'backlogs_end' => $validated['backlogs_end'],
                'remarks' => $validated['remarks'],
                'created_at' => now('Asia/Manila'),
            ]);

            // Set parent to Open when a new child is added
            $ticket->update(['status' => 'open']);

            // Create SLA Metrics for child ticket
            \App\Models\TicketSlaMetric::create([
                'ticket_id' => $childTicket->id,
                'response_target_at' => \App\Services\SlaService::calculateTarget($childTicket->created_at, $childTicket->item_id, 'response'),
                'resolution_target_at' => \App\Services\SlaService::calculateTarget($childTicket->created_at, $childTicket->item_id, 'resolution'),
            ]);

            return $childTicket;
        });

        return redirect()->back()->with('success', 'Child ticket and schedule created successfully.');
    }

    /**
     * Store a new comment for the ticket.
     */
    public function storeComment(Request $request, Ticket $ticket)
    {
        $request->validate([
            'comment_text' => 'required|string|max:65535',
            'status' => 'nullable|string|in:open,in_progress,resolved,closed,waiting',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => $request->comment_text,
            'user_id' => auth()->id(),
            'created_at' => now('Asia/Manila'),
        ]);

        // HANDLE AUTOMATIC STATUS CHANGE
        if ($request->filled('status')) {
            $oldStatus = $ticket->status;
            $newStatus = $request->status;
            
            if ($oldStatus !== $newStatus) {
                $ticket->update(['status' => $newStatus]);
                
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => auth()->id(),
                    'column_changed' => 'status',
                    'old_value' => $oldStatus,
                    'new_value' => $newStatus,
                    'changed_at' => now('Asia/Manila'),
                ]);

                // SYNC TO PARENT IF APPLICABLE
                if ($ticket->parent_id) {
                    $this->syncParentStatus($ticket->parent_id, $newStatus);
                }
            }
        }

        $metric = $ticket->slaMetric;
        if ($metric && !$metric->first_response_at && auth()->id() !== $ticket->reporter_id) {
            $now = now('Asia/Manila');
            $metric->update([
                'first_response_at' => $now,
                'is_response_breached' => $metric->response_target_at && $now->gt($metric->response_target_at),
            ]);
        }

        $ticket->load(['reporter', 'assignee', 'comments.user']);
        $commenterId = auth()->id();
        $recipients = collect();

        if ($ticket->assignee) $recipients->push($ticket->assignee);
        if ($ticket->reporter) $recipients->push($ticket->reporter);
        foreach ($ticket->comments as $prevComment) {
            if ($prevComment->user) $recipients->push($prevComment->user);
        }

        $recipients = $recipients->unique('id')
            ->filter(function ($user) use ($commenterId) {
                return $user->id != $commenterId && $user->email;
            });

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(new TicketCommentAdded($ticket, $comment, $recipient->name));
        }

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('ticket-attachments', $fileName, 'public');
                
                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'comment_id' => $comment->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_storage_path' => $filePath,
                    'file_size_bytes' => $file->getSize(),
                    'created_at' => now('Asia/Manila'),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Comment added and status updated.');
    }

    public function getCategories()
    {
        return response()->json(\App\Models\Category::where('is_active', true)->orderBy('name')->get());
    }

    public function getSubCategories(Request $request)
    {
        $categoryId = $request->query('category_id');
        if (!$categoryId) return response()->json([]);

        $subCategoryIds = \App\Models\Item::where('category_id', $categoryId)
            ->whereNotNull('sub_category_id')
            ->distinct()
            ->pluck('sub_category_id');
            
        $subCategories = \App\Models\SubCategory::whereIn('id', $subCategoryIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($subCategories);
    }

    public function getItems(Request $request)
    {
        $categoryId = $request->query('category_id') ?? $request->input('category_id');
        $subCategoryId = $request->query('sub_category_id') ?? $request->input('sub_category_id');
        
        if (!$categoryId || !$subCategoryId) return response()->json([]);
        
        $items = \App\Models\Item::where('category_id', $categoryId)
            ->where('sub_category_id', $subCategoryId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($items);
    }

    public function downloadAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_storage_path)) abort(404, 'File not found.');
        return Storage::disk('public')->download($attachment->file_storage_path, $attachment->file_name);
    }

    public function sync(\App\Services\EmailTicketService $service)
    {
        $result = $service->fetchAndProcess();
        return response()->json($result);
    }
}
