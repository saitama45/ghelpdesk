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
            'slaMetric', 
            'children' => function($q) {
                $q->select('id', 'parent_id', 'ticket_key', 'title', 'assignee_id')
                  ->with('assignee:id,name,profile_photo');
            }
        ])
            ->whereNull('parent_id'); // Only show top-level tickets

        // If user has 'User' role, only show tickets they reported
        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
        }

        // Filter by user's company access - temporarily disable Admin bypass for debugging
        // if ($user->hasRole('Admin')) {
        //     // Admin sees all tickets
        // } else {
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
            
            // If no companies are allowed, show no tickets
            if ($allowedCompanyIds->isEmpty()) {
                $query->whereRaw('1 = 0'); // This will return no results
            } else {
                $query->whereIn('company_id', $allowedCompanyIds);
            }
        // }

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

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'staff' => $staff,
            'companies' => $companies,
            'filters' => [
                'status' => $statusFilter,
                'search' => $request->search
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Modal used in Index
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTicketRequest $request)
    {
        $data = $request->validated();
        
        $ticket = DB::transaction(function () use ($data, $request) {
            // Lock the company record for update to prevent concurrent ticket key generation for the same company
            $company = Company::where('id', $data['company_id'])->lockForUpdate()->first();
            $companyCode = $company->code;

            // Find the max ticket number for this company
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

            $ticket = Ticket::create($data);

            // Handle file attachments
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

        // Load relationships for email
        $ticket->load(['reporter', 'assignee']);

        $sentTo = [];

        // Send email to reporter
        if ($ticket->reporter && $ticket->reporter->email) {
            Mail::to($ticket->reporter->email)->send(new NewTicketCreated($ticket, $ticket->reporter->name));
            $sentTo[] = $ticket->reporter->email;
        }

        // Send email to assignee (if different from reporter)
        if ($ticket->assignee && $ticket->assignee->email && $ticket->assignee->id !== $ticket->reporter_id) {
            // Check if assignee role allows notification
            $shouldNotifyAssignee = $ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists();
             
            if ($shouldNotifyAssignee) {
                Mail::to($ticket->assignee->email)->send(new NewTicketCreated($ticket, $ticket->assignee->name));
                $sentTo[] = $ticket->assignee->email;
            }
        }

        // Send notification to users with 'notify_on_ticket_create' role
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
     * Display the specified resource.
     */
    public function show(Ticket $ticket)
    {
        return Inertia::render('Tickets/Show', [
            'ticket' => $ticket->load('comments', 'attachments', 'reporter', 'assignee'),
        ]);
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
            'parent',
            'schedule.store',
            'slaMetric',
            'children' => function($query) {
                $query->with(['schedule.store', 'reporter', 'assignee']);
            }
        ]);

        // Auto-create SLA metric if missing but category has targets
        if (!$ticket->slaMetric && $ticket->category) {
            $category = $ticket->category;
            if ($category->response_time_hours || $category->resolution_time_hours) {
                $ticket->slaMetric()->create([
                    'response_target_at' => $category->response_time_hours 
                        ? \App\Services\SlaService::calculateTarget($ticket->created_at, $category->response_time_hours, $category)
                        : null,
                    'resolution_target_at' => $category->resolution_time_hours 
                        ? \App\Services\SlaService::calculateTarget($ticket->created_at, $category->resolution_time_hours, $category)
                        : null,
                ]);
                $ticket->load('slaMetric'); // Reload
            }
        }

        $staff = User::whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        $users = User::active()->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        
        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket,
            'staff' => $staff,
            'companies' => $companies,
            'users' => $users,
            'stores' => $stores,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $validated = $request->validated();
        
        // Fill the model with validated data but don't save yet
        $ticket->fill($validated);
        
        // Check for changes
        if ($ticket->isDirty()) {
            $userId = auth()->id();
            $dirty = $ticket->getDirty();
            $assigneeChanged = false;
            
            foreach ($dirty as $column => $newValue) {
                // Check if assignee changed
                if ($column === 'assignee_id') {
                    $assigneeChanged = true;
                }

                // Skip internal timestamps
                if ($column === 'updated_at') continue;
                
                $oldValue = $ticket->getOriginal($column);
                
                // Resolve names for IDs
                if ($column === 'company_id') {
                    $oldValue = \App\Models\Company::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\Company::find($newValue)?->name ?? $newValue;
                } elseif (in_array($column, ['assignee_id', 'reporter_id'])) {
                    $oldValue = \App\Models\User::find($oldValue)?->name ?? $oldValue;
                    $newValue = \App\Models\User::find($newValue)?->name ?? $newValue;
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
                
                // Create history record
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $userId,
                    'column_changed' => $column,
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                    'changed_at' => now(),
                ]);
            }
            
            $ticket->save();

            // Send email if assignee changed and new assignee exists
            if ($assigneeChanged && $ticket->assignee_id) {
                $ticket->load('assignee');
                if ($ticket->assignee && $ticket->assignee->email) {
                    // Check if assignee role allows notification
                    if ($ticket->assignee->roles()->where('notify_on_ticket_assign', true)->exists()) {
                        Mail::to($ticket->assignee->email)->send(new TicketAssigned($ticket, $ticket->assignee->name));
                    }
                }
            }
        }

        return redirect()->back()->with('success', 'Ticket updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ticket $ticket)
    {
        // Delete associated attachments from storage
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
        // Check if child ticket already exists
        if ($ticket->children()->exists()) {
            return redirect()->back()->withErrors(['error' => 'A child ticket already exists for this ticket.']);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'pickup_start' => 'nullable|string',
            'pickup_end' => 'nullable|string',
            'backlogs_start' => 'nullable|string',
            'backlogs_end' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $childTicket = DB::transaction(function () use ($validated, $ticket, $request) {
            $company = $ticket->company;
            $companyCode = $company->code;

            // Find the max ticket number for this company
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
                'category_id' => $ticket->category_id,
                'parent_id' => $ticket->id,
            ]);

            // Create schedule linked to this child ticket
            Schedule::create([
                'ticket_id' => $childTicket->id,
                'user_id' => $validated['user_id'],
                'store_id' => $validated['store_id'],
                'status' => $validated['status'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'pickup_start' => $validated['pickup_start'],
                'pickup_end' => $validated['pickup_end'],
                'backlogs_start' => $validated['backlogs_start'],
                'backlogs_end' => $validated['backlogs_end'],
                'remarks' => $validated['remarks'],
            ]);

            return $childTicket;
        });

        return redirect()->back()->with([
            'success' => 'Child ticket and schedule created successfully.',
        ]);
    }

    /**
     * Store a new comment for the ticket.
     */
    public function storeComment(Request $request, Ticket $ticket)
    {
        $request->validate([
            'comment_text' => 'required|string|max:65535',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => $request->comment_text,
            'user_id' => auth()->id(),
        ]);

        // SLA: Record first response if not already recorded and commenter is NOT the reporter
        $metric = $ticket->slaMetric;
        if ($metric && !$metric->first_response_at && auth()->id() !== $ticket->reporter_id) {
            $now = now();
            $metric->update([
                'first_response_at' => $now,
                'is_response_breached' => $metric->response_target_at && $now->gt($metric->response_target_at),
            ]);
        }

        // Load relationships for email logic
        $ticket->load(['reporter', 'assignee', 'comments.user']);
        $commenterId = auth()->id();

        // 1. Identify recipients: Assignee + Reporter + All Previous Commenters
        $recipients = collect();

        if ($ticket->assignee) {
            $recipients->push($ticket->assignee);
        }
        if ($ticket->reporter) {
            $recipients->push($ticket->reporter);
        }
        foreach ($ticket->comments as $prevComment) {
            if ($prevComment->user) {
                $recipients->push($prevComment->user);
            }
        }

        // 2. Filter recipients: Unique emails, active users, exclude current commenter
        $recipients = $recipients->unique('id')
            ->filter(function ($user) use ($commenterId) {
                return $user->id != $commenterId && $user->email;
            });

        // 3. Send emails
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
                ]);
            }
        }

        return redirect()->back()->with('success', 'Comment added successfully.');
    }

    /**
     * Store attachments for the ticket.
     */
    public function storeAttachment(Request $request, Ticket $ticket)
    {
        $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

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

        return redirect()->back()->with('success', 'Attachments uploaded successfully.');
    }

    public function getCategories()
    {
        return response()->json(\App\Models\Category::where('is_active', true)->orderBy('name')->get());
    }

    public function getSubCategories(Request $request)
    {
        $categoryId = $request->query('category_id');
        
        if (!$categoryId) {
            return response()->json([]);
        }

        // Get subcategory IDs that are linked to this category in the Items table
        $subCategoryIds = \App\Models\Item::where('category_id', $categoryId)
            ->whereNotNull('sub_category_id')
            ->distinct()
            ->pluck('sub_category_id');
            
        // If no links found in Item table, maybe return ALL subcategories as a fallback? 
        // Or if the user strictly wants the Item table to be the source of truth:
        
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
        
        if (!$categoryId || !$subCategoryId) {
            return response()->json([]);
        }
        
        $items = \App\Models\Item::where('category_id', $categoryId)
            ->where('sub_category_id', $subCategoryId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($items);
    }

    /**
     * Download an attachment.
     */
    public function downloadAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_storage_path)) {
            // Check legacy path if file not found in new path
            // Legacy path was 'public/ticket-attachments/filename' relative to local disk (storage/app/public/ticket-attachments)
            // But we stored it in 'storage/app/private/public/ticket-attachments' by mistake.
            // Let's assume we fixed it and only care about public disk now.
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download($attachment->file_storage_path, $attachment->file_name);
    }
}
