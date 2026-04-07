<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\RequestType;
use App\Services\SapRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class PublicSapRequestController extends Controller
{
    protected SapRequestService $sapRequestService;

    public function __construct(SapRequestService $sapRequestService)
    {
        $this->sapRequestService = $sapRequestService;
    }

    public function create()
    {
        return Inertia::render('Public/SAPRequests/Create', [
            'companies'    => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'requester_name'  => 'required|string|max:255',
            'requester_email' => 'required|email|max:255',
            'company_id'      => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'form_data'       => 'required|array',
            'items'           => 'nullable|array',
        ]);

        Log::info('Public SAP Request Submission', [
            'requester_name'  => $validated['requester_name'],
            'requester_email' => $validated['requester_email'],
        ]);

        $this->sapRequestService->createRequest($validated, null);

        return redirect()->back()->with('success', 'SAP Request submitted successfully. You will receive an email update soon.');
    }
}
