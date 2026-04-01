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
            'categories' => \App\Models\PosRequestDetail::distinct()->whereNotNull('category')->pluck('category'),
            'sub_categories' => \App\Models\PosRequestDetail::distinct()->whereNotNull('sub_category')->pluck('sub_category'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'requester_name' => 'required|string|max:255',
            'requester_email' => 'required|email|max:255',
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

        \Illuminate\Support\Facades\Log::info('Public POS Request Submission', [
            'requester_name' => $validated['requester_name'],
            'requester_email' => $validated['requester_email']
        ]);

        $this->posRequestService->createRequest($validated, null);

        // Redirect back with success message or to a thank you page
        return redirect()->back()->with('success', 'POS Request created successfully. You will receive an email update soon.');
    }
}
