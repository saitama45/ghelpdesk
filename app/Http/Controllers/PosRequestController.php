<?php

namespace App\Http\Controllers;

use App\Models\PosRequest;
use App\Models\PosRequestDetail;
use App\Models\PosRequestApproval;
use App\Models\RequestType;
use App\Models\Company;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\PosRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PosRequestController extends Controller implements HasMiddleware
{
    protected PosRequestService $posRequestService;

    public function __construct(PosRequestService $posRequestService)
    {
        $this->posRequestService = $posRequestService;
    }

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
        $query = PosRequest::with(['company', 'requestType', 'user', 'ticket']);
        
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
            ],
            'categories' => PosRequestDetail::distinct()->whereNotNull('category')->pluck('category'),
            'sub_categories' => PosRequestDetail::distinct()->whereNotNull('sub_category')->pluck('sub_category'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'launch_date' => 'required|date',
            'stores_covered' => 'required|array|min:1',
            'details' => 'required|array|min:1',
            'details.*.product_name' => 'required|string|max:255',
            'details.*.pos_name' => 'required|string|max:255',
            'details.*.price_type' => 'required|string',
            'details.*.price_amount' => 'nullable|numeric',
            'details.*.category' => 'nullable|string|max:255',
            'details.*.sub_category' => 'nullable|string|max:255',
            'details.*.item_code' => 'nullable|string|max:255',
            'details.*.sc' => 'nullable|string|max:255',
            'details.*.local_tax' => 'nullable|string|max:255',
            'details.*.mgr_meal' => 'nullable|boolean',
            'details.*.printer' => 'nullable|string|max:255',
            'details.*.remarks_mechanics' => 'nullable|string',
            'details.*.validity_date' => 'nullable|date',
        ]);

        $this->posRequestService->createRequest($request->all(), auth()->id());

        return redirect()->route('pos-requests.index')->with('success', 'POS Request created successfully');
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
            ],
            'categories' => PosRequestDetail::distinct()->whereNotNull('category')->pluck('category'),
            'sub_categories' => PosRequestDetail::distinct()->whereNotNull('sub_category')->pluck('sub_category'),
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
            'stores_covered' => 'required|array|min:1',
            'details' => 'required|array|min:1',
            'details.*.product_name' => 'required|string|max:255',
            'details.*.pos_name' => 'required|string|max:255',
            'details.*.price_type' => 'required|string',
            'details.*.price_amount' => 'nullable|numeric',
            'details.*.category' => 'nullable|string|max:255',
            'details.*.sub_category' => 'nullable|string|max:255',
            'details.*.item_code' => 'nullable|string|max:255',
            'details.*.sc' => 'nullable|string|max:255',
            'details.*.local_tax' => 'nullable|string|max:255',
            'details.*.mgr_meal' => 'nullable|boolean',
            'details.*.printer' => 'nullable|string|max:255',
            'details.*.remarks_mechanics' => 'nullable|string',
            'details.*.validity_date' => 'nullable|date',
        ]);

        $this->posRequestService->updateRequest($posRequest, $request->all());

        return redirect()->route('pos-requests.index')->with('success', 'POS Request updated successfully');
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
                $this->posRequestService->processApprovedRequest($posRequest);
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
}
