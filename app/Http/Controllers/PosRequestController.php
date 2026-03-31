<?php

namespace App\Http\Controllers;

use App\Models\PosRequest;
use App\Models\PosRequestDetail;
use App\Models\PosRequestApproval;
use App\Models\RequestType;
use App\Models\Company;
use App\Models\Store;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PosRequestController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:pos_requests.view', only: ['index', 'show']),
            new Middleware('can:pos_requests.create', only: ['create', 'store']),
            new Middleware('can:pos_requests.edit', only: ['edit', 'update']),
            new Middleware('can:pos_requests.delete', only: ['destroy']),
            new Middleware('can:pos_requests.approve', only: ['approve']),
        ];
    }

    public function index(Request $request)
    {
        $query = PosRequest::with(['company', 'requestType', 'user']);
        
        if ($request->filled('search')) {
            $query->whereHas('requestType', function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%");
            })->orWhereHas('company', function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%");
            });
        }
        
        $posRequests = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        
        return Inertia::render('PosRequests/Index', [
            'posRequests' => $posRequests,
        ]);
    }

    public function create()
    {
        return Inertia::render('PosRequests/Create', [
            'companies' => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'POS')
                ->get(['id', 'name', 'approval_levels']),
            'stores' => Store::where('is_active', true)->get(['id', 'code', 'name']),
            'priceTypes' => [
                'In-Store', 
                'Delivery (GF, FP, Pickaroo)', 
                'Tablevibe', 
                'Airport', 
                'Casino & Highway (CBTL)', 
                'Transfer'
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'launch_date' => 'required|date',
            'effectivity_date' => 'required|date|after_or_equal:launch_date',
            'stores_covered' => 'required|array|min:1',
            'details' => 'required|array|min:1',
            'details.*.product_name' => 'required|string|max:255',
            'details.*.pos_name' => 'required|string|max:255',
            'details.*.price_type' => 'required|string',
            'details.*.price_amount' => 'nullable|numeric',
            'details.*.remarks_mechanics' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request) {
            $requestType = RequestType::findOrFail($request->request_type_id);
            
            $posRequest = PosRequest::create([
                'company_id' => $request->company_id,
                'request_type_id' => $request->request_type_id,
                'user_id' => auth()->id(),
                'launch_date' => $request->launch_date,
                'effectivity_date' => $request->effectivity_date,
                'stores_covered' => $request->stores_covered,
                'status' => $requestType->approval_levels == 0 ? 'Approved' : 'Open',
                'current_approval_level' => $requestType->approval_levels == 0 ? 0 : 1,
            ]);

            foreach ($request->details as $detail) {
                $posRequest->details()->create($detail);
            }

            if ($posRequest->status === 'Approved') {
                $this->processApprovedRequest($posRequest);
            }

            return redirect()->route('pos-requests.index')->with('success', 'POS Request created successfully');
        });
    }

    public function show(PosRequest $posRequest)
    {
        $posRequest->load(['company', 'requestType', 'user', 'details', 'approvals.user']);
        
        return Inertia::render('PosRequests/Show', [
            'posRequest' => $posRequest,
        ]);
    }

    public function edit(PosRequest $posRequest)
    {
        if ($posRequest->status !== 'Open') {
            return redirect()->route('pos-requests.index')->with('error', 'Only open requests can be edited');
        }

        $posRequest->load('details');

        return Inertia::render('PosRequests/Create', [
            'posRequest' => $posRequest,
            'companies' => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'POS')
                ->get(['id', 'name', 'approval_levels']),
            'stores' => Store::where('is_active', true)->get(['id', 'code', 'name']),
            'priceTypes' => [
                'In-Store', 
                'Delivery (GF, FP, Pickaroo)', 
                'Tablevibe', 
                'Airport', 
                'Casino & Highway (CBTL)', 
                'Transfer'
            ]
        ]);
    }

    public function update(Request $request, PosRequest $posRequest)
    {
        if ($posRequest->status !== 'Open') {
            return redirect()->route('pos-requests.index')->with('error', 'Only open requests can be updated');
        }

        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'launch_date' => 'required|date',
            'effectivity_date' => 'required|date|after_or_equal:launch_date',
            'stores_covered' => 'required|array|min:1',
            'details' => 'required|array|min:1',
            'details.*.product_name' => 'required|string|max:255',
            'details.*.pos_name' => 'required|string|max:255',
            'details.*.price_type' => 'required|string',
            'details.*.price_amount' => 'nullable|numeric',
            'details.*.remarks_mechanics' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $posRequest) {
            $posRequest->update([
                'company_id' => $request->company_id,
                'request_type_id' => $request->request_type_id,
                'launch_date' => $request->launch_date,
                'effectivity_date' => $request->effectivity_date,
                'stores_covered' => $request->stores_covered,
            ]);

            // Sync Details: simplest way is delete and recreate for this type of record
            $posRequest->details()->delete();
            foreach ($request->details as $detail) {
                $posRequest->details()->create($detail);
            }

            return redirect()->route('pos-requests.index')->with('success', 'POS Request updated successfully');
        });
    }

    public function approve(Request $request, PosRequest $posRequest)
    {
        $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($request, $posRequest) {
            $requestType = $posRequest->requestType;
            
            // Log approval
            PosRequestApproval::create([
                'pos_request_id' => $posRequest->id,
                'user_id' => auth()->id(),
                'level' => $posRequest->current_approval_level,
                'remarks' => $request->remarks,
            ]);

            if ($posRequest->current_approval_level >= $requestType->approval_levels) {
                $posRequest->update([
                    'status' => 'Approved',
                    'current_approval_level' => 0,
                ]);
                $this->processApprovedRequest($posRequest);
            } else {
                $posRequest->update([
                    'status' => 'Approved Level ' . $posRequest->current_approval_level,
                ]);
                $posRequest->increment('current_approval_level');
            }
        });

        // Ensure the model and its relationships are fresh for Inertia
        $posRequest->refresh();
        $posRequest->load(['approvals.user', 'requestType', 'company', 'user', 'details']);

        return redirect()->back()->with('success', 'Request approved successfully');
    }

    protected function processApprovedRequest(PosRequest $posRequest)
    {
        // 1. Generate Ticket Key (Format: COMPANYCODE-NUMBER)
        $company = $posRequest->company;
        $companyCode = $company->code;

        $maxNumber = Ticket::withTrashed()
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
        $ticketKey = "{$companyCode}-{$nextNumber}";

        // 2. Build Detailed Description from Line Items
        $storeCodes = in_array('all', $posRequest->stores_covered) 
            ? 'All Stores' 
            : implode(', ', $posRequest->stores_covered);

        $subject = "POS Request - {$posRequest->requestType->name} to {$storeCodes}";
        
        $detailsContent = "\n\n--- LINE ITEM DETAILS ---\n";
        foreach ($posRequest->details as $index => $detail) {
            $num = $index + 1;
            $detailsContent .= "{$num}. Product: {$detail->product_name} | POS Alias: {$detail->pos_name}\n";
            $detailsContent .= "   Price: {$detail->price_type} (₱" . number_format($detail->price_amount, 2) . ")\n";
            $detailsContent .= "   Code: " . ($detail->item_code ?? 'N/A') . " | Cat: " . ($detail->category ?? 'N/A') . " | Printer: " . ($detail->printer ?? 'N/A') . "\n";
            if ($detail->remarks_mechanics) {
                $detailsContent .= "   Remarks: {$detail->remarks_mechanics}\n";
            }
            $detailsContent .= "   SC: {$detail->sc} | Tax: {$detail->local_tax} | Meal: {$detail->mgr_meal}\n\n";
        }

        $fullDescription = "POS Request ID: {$posRequest->id}\n" .
                          "Launch Date: {$posRequest->launch_date->format('Y-m-d')}\n" .
                          "Effectivity Date: {$posRequest->effectivity_date->format('Y-m-d')}\n" .
                          "Stores: {$storeCodes}" .
                          $detailsContent;

        // 3. Create Ticket with Key and Full Details
        $ticket = Ticket::create([
            'ticket_key' => $ticketKey,
            'title' => $subject,
            'description' => $fullDescription,
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'reporter_id' => $posRequest->user_id,
            'company_id' => $posRequest->company_id,
            'type' => 'feature',
            'created_at' => now('Asia/Manila'),
        ]);

        $posRequest->update(['ticket_id' => $ticket->id]);

        // 4. Notify CC Emails
        $ccEmails = $posRequest->requestType->cc_emails;
        if ($ccEmails) {
            $emails = array_map('trim', explode("\n", $ccEmails));
            $emails = array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            
            if (!empty($emails)) {
                // Logic to send email notifications would go here
            }
        }
    }
}
