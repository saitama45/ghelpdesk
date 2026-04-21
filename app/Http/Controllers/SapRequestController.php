<?php

namespace App\Http\Controllers;

use App\Models\SapRequest;
use App\Models\SapRequestApproval;
use App\Models\RequestType;
use App\Models\Company;
use App\Models\User;
use App\Services\SapRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SapRequestController extends Controller implements HasMiddleware
{
    protected SapRequestService $sapRequestService;

    public function __construct(SapRequestService $sapRequestService)
    {
        $this->sapRequestService = $sapRequestService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:sap_requests.view', only: ['index', 'show']),
            new Middleware('can:sap_requests.create', only: ['create', 'store']),
            new Middleware('can:sap_requests.edit', only: ['edit', 'update']),
            new Middleware('can:sap_requests.delete', only: ['destroy']),
            new Middleware('can:sap_requests.approve', only: ['approve', 'reject']),
        ];
    }

    public function index(Request $request)
    {
        $query = SapRequest::with(['company', 'requestType', 'user', 'ticket']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('requestType', fn($r) => $r->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$search}%"))
                  ->orWhere('requester_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->status;
            $ticketStatus = strtolower(str_replace(' ', '_', $status));
            $query->where(function ($q) use ($status, $ticketStatus) {
                $q->where('status', $status)
                  ->orWhereHas('ticket', function ($tq) use ($ticketStatus) {
                      $tq->where('status', $ticketStatus);
                  });
            });
        }

        $sapRequests = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('SapRequests/Index', [
            'sapRequests' => $sapRequests,
            'filters' => $request->only(['search', 'status', 'per_page']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
        ]);
    }

    public function create()
    {
        return Inertia::render('SapRequests/Create', [
            'companies'    => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'      => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'form_data'       => 'required|array',
            'items'           => 'nullable|array',
        ]);

        $this->sapRequestService->createRequest($validated, auth()->id());

        return redirect()->route('sap-requests.index')->with('success', 'SAP Request created successfully.');
    }

    public function show(SapRequest $sapRequest)
    {
        $sapRequest->load(['company', 'requestType', 'user', 'items', 'approvals.user', 'ticket']);

        return Inertia::render('SapRequests/Show', [
            'sapRequest' => $sapRequest,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function edit(SapRequest $sapRequest)
    {
        if ($sapRequest->status !== 'Open') {
            return redirect()->route('sap-requests.index')->with('error', 'Only open requests can be edited.');
        }

        $sapRequest->load('items');

        return Inertia::render('SapRequests/Create', [
            'sapRequest'   => $sapRequest,
            'companies'    => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
        ]);
    }

    public function update(Request $request, SapRequest $sapRequest)
    {
        if ($sapRequest->status !== 'Open') {
            return redirect()->route('sap-requests.index')->with('error', 'Only open requests can be updated.');
        }

        $validated = $request->validate([
            'company_id'      => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'form_data'       => 'required|array',
            'items'           => 'nullable|array',
        ]);

        $this->sapRequestService->updateRequest($sapRequest, $validated);

        return redirect()->route('sap-requests.index')->with('success', 'SAP Request updated successfully.');
    }

    public function destroy(SapRequest $sapRequest)
    {
        $sapRequest->delete();
        return redirect()->route('sap-requests.index')->with('success', 'SAP Request deleted.');
    }

    public function approve(Request $request, SapRequest $sapRequest)
    {
        $request->validate([
            'remarks' => 'nullable|string|max:1000',
            'approver_data' => 'nullable|array',
        ]);

        $requestType = $sapRequest->requestType;
        $currentLevel = (int) $sapRequest->current_approval_level;
        $authUserId = (int) auth()->id();

        if ($currentLevel <= 0 || !$this->canUserApproveLevel($requestType, $sapRequest->form_data ?? [], $currentLevel, $authUserId)) {
            return redirect()->back()->with('error', 'You are not assigned as an approver for this approval level.');
        }

        $alreadyApprovedCurrentLevel = $sapRequest->approvals()
            ->where('level', $currentLevel)
            ->where('user_id', $authUserId)
            ->exists();

        if ($alreadyApprovedCurrentLevel) {
            return redirect()->back()->with('error', 'You already approved this level.');
        }

        DB::transaction(function () use ($request, $sapRequest) {
            $requestType = $sapRequest->requestType;
            $effectiveApprovalLevels = $this->getEffectiveApprovalLevels($requestType, $sapRequest->form_data ?? []);

            SapRequestApproval::create([
                'sap_request_id' => $sapRequest->id,
                'user_id'        => auth()->id(),
                'level'          => $sapRequest->current_approval_level,
                'status'         => 'approved',
                'remarks'        => $request->remarks,
            ]);

            if ($request->has('approver_data') && is_array($request->approver_data)) {
                $sapRequest->update([
                    'form_data' => array_merge($sapRequest->form_data ?? [], $request->approver_data)
                ]);
            }

            if ($sapRequest->current_approval_level >= $effectiveApprovalLevels) {
                $sapRequest->update([
                    'status'                => 'Approved',
                    'current_approval_level'=> 0,
                ]);
                $this->sapRequestService->processApprovedRequest($sapRequest->fresh());
            } else {
                $sapRequest->update([
                    'status' => 'Approved Level ' . $sapRequest->current_approval_level,
                ]);
                $sapRequest->increment('current_approval_level');
            }
        });

        return redirect()->back()->with('success', 'Request approved successfully.');
    }

    public function reject(Request $request, SapRequest $sapRequest)
    {
        $request->validate([
            'remarks' => 'required|string|max:1000',
        ]);

        $requestType = $sapRequest->requestType;
        $currentLevel = (int) $sapRequest->current_approval_level;
        $authUserId = (int) auth()->id();

        if ($currentLevel <= 0 || !$this->canUserApproveLevel($requestType, $sapRequest->form_data ?? [], $currentLevel, $authUserId)) {
            return redirect()->back()->with('error', 'You are not assigned as an approver for this approval level.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $sapRequest) {
            \App\Models\SapRequestApproval::create([
                'sap_request_id' => $sapRequest->id,
                'user_id'        => auth()->id(),
                'level'          => $sapRequest->current_approval_level,
                'status'         => 'rejected',
                'remarks'        => $request->remarks,
            ]);

            $sapRequest->update([
                'status'                => 'Rejected',
                'current_approval_level'=> 0,
            ]);
        });

        return redirect()->back()->with('success', 'Request rejected successfully');
    }

    private function canUserApproveLevel(RequestType $requestType, array $formData, int $level, int $userId): bool
    {
        $assignedUsers = $this->sapRequestService->getApproverIdsForLevel($requestType, $formData, $level);

        if ($assignedUsers->isEmpty()) {
            return true;
        }

        return $assignedUsers->contains($userId);
    }

    private function getEffectiveApprovalLevels(RequestType $requestType, array $formData): int
    {
        return $this->sapRequestService->getEffectiveApprovalLevels($requestType, $formData);
    }
}
