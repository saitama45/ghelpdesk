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
            new Middleware('can:sap_requests.view', only: ['remind']),
        ];
    }

    public function index(Request $request)
    {
        $query = SapRequest::query()->with(['company', 'requestType', 'user', 'ticket', 'items']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('requestType', fn($r) => $r->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('company', fn($r) => $r->where('name', 'like', "%{$search}%"))
                  ->orWhere('requester_name', 'like', "%{$search}%");
            });
        }

        // Determine if the current user is an approver in any RequestType
        $userId = auth()->id();
        $isApprover = RequestType::where('is_active', true)
            ->whereJsonContains('request_for', 'SAP')
            ->where(function ($q) use ($userId) {
                // Base approver matrix
                $q->whereRaw("approver_matrix IS NOT NULL AND EXISTS (
                      SELECT 1 FROM OPENJSON(approver_matrix) 
                      WITH (user_ids nvarchar(max) '$.user_ids' AS JSON) as m
                      CROSS APPLY OPENJSON(m.user_ids) as ids WHERE ids.[value] = ?
                  )", [$userId])
                  // Custom approval matrix in form_schema options
                  ->orWhereRaw("form_schema IS NOT NULL AND EXISTS (
                      SELECT 1 
                      FROM OPENJSON(form_schema, '$.fields') as fields
                      CROSS APPLY OPENJSON(fields.[value], '$.options') as options
                      CROSS APPLY OPENJSON(options.[value], '$.approval_matrix') 
                      WITH (user_ids nvarchar(max) '$.user_ids' AS JSON) as matrix
                      CROSS APPLY OPENJSON(matrix.user_ids) as ids
                      WHERE ids.[value] = ?
                  )", [$userId]);
            })->exists();

        // Handle Status Filter
        $status = $request->get('status');
        
        // Default to for_my_approval if user is an approver and no status filter key is provided in URL
        if (!$request->has('status') && $isApprover) {
            $status = 'for_my_approval';
        }

        if ($status) {
            if ($status === 'for_my_approval') {
                $query->whereNotIn('sap_requests.status', ['Approved', 'Rejected', 'Cancelled'])
                      ->whereNull('sap_requests.ticket_id')
                      ->where('sap_requests.current_approval_level', '>', 0)
                      ->where(function ($q) use ($userId) {
                          // Check Base Matrix
                          $q->whereHas('requestType', function ($sub) use ($userId) {
                              $sub->whereRaw("EXISTS (
                                  SELECT 1 
                                  FROM OPENJSON(request_types.approver_matrix) 
                                  WITH (
                                      level int '$.level',
                                      user_ids nvarchar(max) '$.user_ids' AS JSON
                                  ) as matrix
                                  WHERE matrix.level = sap_requests.current_approval_level
                                  AND EXISTS (
                                      SELECT 1 FROM OPENJSON(matrix.user_ids) WHERE CAST([value] AS NVARCHAR(MAX)) = CAST(? AS NVARCHAR(MAX))
                                  )
                              )", [$userId]);
                          })
                          // OR Check Custom Matrix from form_schema + form_data
                          ->orWhereHas('requestType', function ($sub) use ($userId) {
                              $sub->whereRaw("EXISTS (
                                  SELECT 1 
                                  FROM OPENJSON(request_types.form_schema, '$.fields') as fields
                                  CROSS APPLY OPENJSON(fields.[value], '$.options') as options
                                  CROSS APPLY OPENJSON(options.[value], '$.approval_matrix') 
                                  WITH (
                                      level int '$.level',
                                      user_ids nvarchar(max) '$.user_ids' AS JSON
                                  ) as matrix
                                  CROSS APPLY OPENJSON(matrix.user_ids) as ids
                                  WHERE matrix.level = sap_requests.current_approval_level
                                    AND ids.[value] = ?
                                    -- Check if this option value is selected in SapRequest form_data
                                    AND EXISTS (
                                        SELECT 1 FROM OPENJSON(sap_requests.form_data, '$.' + JSON_VALUE(fields.[value], '$.key')) as selected
                                        WHERE CAST(selected.[value] AS NVARCHAR(MAX)) = CAST(JSON_VALUE(options.[value], '$.value') AS NVARCHAR(MAX))
                                    )
                              )", [$userId]);
                          });
                      })
                      ->whereDoesntHave('approvals', function ($sub) use ($userId) {
                          $sub->where('user_id', $userId)
                              ->whereColumn('level', 'sap_requests.current_approval_level');
                      });
            } else {
                $ticketStatus = strtolower(str_replace(' ', '_', $status));
                $query->where(function ($q) use ($status, $ticketStatus) {
                    $q->where('status', $status)
                      ->orWhereHas('ticket', function ($tq) use ($ticketStatus) {
                          $tq->where('status', $ticketStatus);
                      });
                });
            }
        }

        $sapRequests = $query->orderBy('sap_requests.created_at', 'desc')
                            ->paginate($request->get('per_page', 10))
                            ->withQueryString();

        return Inertia::render('SapRequests/Index', [
            'sapRequests' => $sapRequests,
            'filters' => array_merge($request->only(['search', 'status', 'per_page']), ['status' => $status]),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
            'isApprover' => $isApprover,
        ]);
    }

    public function create()
    {
        return Inertia::render('SapRequests/Create', [
            'companies'    => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
            'copyTransferPayload' => session('copy_transfer_payload'),
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
        $sapRequest->load(['company', 'requestType', 'user', 'items', 'approvals.user', 'ticket.slaMetric']);

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

        $sapRequest->refresh();
        $sapRequest->load(['company', 'requestType', 'items', 'user']);
        $this->sapRequestService->notifyCurrentApprovers($sapRequest);

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

    public function remind(SapRequest $sapRequest)
    {
        $status = $sapRequest->status ?? '';
        $currentLevel = (int) $sapRequest->current_approval_level;

        if ($currentLevel <= 0 || in_array($status, ['Approved', 'Rejected', 'Cancelled'])) {
            return redirect()->back()->with('error', 'No pending approval stage to send a reminder for.');
        }

        $sapRequest->load(['company', 'requestType', 'items', 'user']);

        try {
            $this->sapRequestService->notifyCurrentApprovers($sapRequest);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('SAP Request reminder failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to send reminder. Please try again.');
        }

        return redirect()->back()->with('success', "Reminder sent to Stage {$currentLevel} approvers.");
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
