<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $query = Ticket::with(['reporter:id,name', 'assignee:id,name', 'company:id,name']);

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

        // Apply status filters
        if ($request->filled('status') && $request->status !== 'all') {
            switch ($request->status) {
                case 'my_tickets':
                    if ($user->hasRole('User')) {
                        $query->where('reporter_id', $user->id);
                    } else {
                        $query->where('assignee_id', $user->id);
                    }
                    break;
                case 'unassigned':
                    $query->whereNull('assignee_id');
                    break;
                default:
                    $query->where('status', $request->status);
                    break;
            }
        }

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhere('ticket_key', 'like', "%{$request->search}%");
            });
        }
        
        $query->orderBy('created_at', 'desc');
        $tickets = $query->paginate($request->get('per_page', 10))->withQueryString();
        $staff = User::permission('tickets.edit')->select('id', 'name')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'staff' => $staff,
            'companies' => $companies,
            'filters' => $request->only(['status', 'search']),
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
        
        return DB::transaction(function () use ($data, $request) {
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

            return redirect()->back()->with('success', 'Ticket created successfully.');
        });
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
        $staff = User::permission('tickets.edit')->select('id', 'name')->get();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();
        
        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket->load([
                'comments' => function($query) {
                    $query->with(['user:id,name', 'attachments'])->orderBy('created_at', 'desc');
                }, 
                'attachments', 
                'reporter', 
                'assignee', 
                'company'
            ]),
            'staff' => $staff,
            'companies' => $companies,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $validated = $request->validated();
        foreach ($validated as $key => $value) {
            $ticket->$key = $value;
        }
        $ticket->save();

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
     * Store a new comment for the ticket.
     */
    public function storeComment(Request $request, Ticket $ticket)
    {
        $request->validate([
            'comment_text' => 'required|string|max:1000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => $request->comment_text,
            'user_id' => auth()->id(),
        ]);

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
