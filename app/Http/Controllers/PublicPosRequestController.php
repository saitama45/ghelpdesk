<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\RequestType;
use App\Models\Store;
use App\Services\PosRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicPosRequestController extends Controller
{
    protected PosRequestService $posRequestService;

    public function __construct(PosRequestService $posRequestService)
    {
        $this->posRequestService = $posRequestService;
    }

    public function create()
    {
        return Inertia::render('Public/PosRequests/Create', [
            'companies' => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'POS')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
            'stores' => Store::with('cluster:id,name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'brand', 'cluster_id']),
            'priceTypes' => [
                'In-Store', 
                'Delivery (GF, FP, Pickaroo)', 
                'Tablevibe', 
                'Airport', 
                'Casino & Highway (CBTL)', 
                'Transfer'
            ],
            'categories' => \App\Models\PosRequestDetail::distinct()->whereNotNull('category')->pluck('category'),
            'sub_categories' => \App\Models\PosRequestDetail::distinct()->whereNotNull('sub_category')->pluck('sub_category'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'requester_name' => 'required|string|max:255',
            'requester_email' => 'required|email|max:255',
            'company_id' => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'launch_date' => 'required|date',
            'stores_covered' => 'required|array|min:1',
            'form_data' => 'nullable|array',
            'details' => 'nullable|array',
        ]);

        \Illuminate\Support\Facades\Log::info('Public POS Request Submission', [
            'requester_name' => $request->input('requester_name'),
            'requester_email' => $request->input('requester_email')
        ]);

        // Use $request->all() so schema-driven item fields (which vary per request type)
        // are not stripped by $request->validated() which only returns validated keys.
        $this->posRequestService->createRequest($request->all(), null);

        // Redirect back with success message or to a thank you page
        return redirect()->back()->with('success', 'POS Request created successfully. You will receive an email update soon.');
    }
}
