<?php

namespace App\Http\Controllers;

use App\Models\PosRequest;
use App\Models\PosRequestDetail;
use App\Models\PosRequestApproval;
use App\Models\RequestType;
use App\Models\Company;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
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
            new Middleware('can:pos_requests.approve', only: ['approve', 'reject']),
        ];
    }

    public function index(Request $request)
    {
        $query = PosRequest::query()->with(['company', 'requestType', 'user', 'ticket', 'details']);
        
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('requestType', function($qr) use ($request) {
                    $qr->where('name', 'like', "%{$request->search}%");
                })->orWhereHas('company', function($qr) use ($request) {
                    $qr->where('name', 'like', "%{$request->search}%");
                });
            });
        }

        // Determine if the current user is an approver in any POS RequestType
        $userId = auth()->id();
        $isApprover = RequestType::where('is_active', true)
            ->whereJsonContains('request_for', 'POS')
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
                $query->whereNotIn('pos_requests.status', ['Approved', 'Rejected', 'Cancelled'])
                      ->whereNull('pos_requests.ticket_id')
                      ->where('pos_requests.current_approval_level', '>', 0)
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
                                  WHERE matrix.level = pos_requests.current_approval_level
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
                                  WHERE matrix.level = pos_requests.current_approval_level
                                    AND ids.[value] = ?
                                    -- Check if this option value is selected in PosRequest form_data
                                    AND EXISTS (
                                        SELECT 1 FROM OPENJSON(pos_requests.form_data, '$.' + JSON_VALUE(fields.[value], '$.key')) as selected
                                        WHERE CAST(selected.[value] AS NVARCHAR(MAX)) = CAST(JSON_VALUE(options.[value], '$.value') AS NVARCHAR(MAX))
                                    )
                              )", [$userId]);
                          });
                      })
                      ->whereDoesntHave('approvals', function ($sub) use ($userId) {
                          $sub->where('user_id', $userId)
                              ->whereColumn('level', 'pos_requests.current_approval_level');
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

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        
        $posRequests = $query->orderBy('pos_requests.created_at', 'desc')
                            ->paginate($request->get('per_page', 10))
                            ->withQueryString();
        
        return Inertia::render('PosRequests/Index', [
            'posRequests' => $posRequests,
            'companies' => Company::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => array_merge($request->only(['search', 'status', 'company_id', 'per_page']), ['status' => $status]),
            'isApprover' => $isApprover,
        ]);
    }

    public function create()
    {
        return Inertia::render('PosRequests/Create', [
            'companies' => Company::where('is_active', true)->get(['id', 'name']),
            'requestTypes' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'POS')
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
            'stores' => Store::with('clusters:id,name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'brand']),
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
            'copyTransferPayload' => session('copy_transfer_payload'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'request_type_id' => 'required|exists:request_types,id',
            'launch_date' => 'required|date',
            'stores_covered' => 'required|array|min:1',
            'form_data' => 'nullable|array',
            'details' => 'nullable|array',
        ]);

        $this->posRequestService->createRequest($request->all(), auth()->id());

        return redirect()->route('pos-requests.index')->with('success', 'POS Request created successfully');
    }

    public function show(PosRequest $posRequest)
    {
        $posRequest->load(['company', 'requestType', 'user', 'details', 'approvals.user']);
        
        return Inertia::render('PosRequests/Show', [
            'posRequest' => $posRequest,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
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
                ->get(['id', 'name', 'approval_levels', 'form_schema']),
            'stores' => Store::with('clusters:id,name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'brand']),
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
            'form_data' => 'nullable|array',
            'details' => 'nullable|array',
        ]);

        $this->posRequestService->updateRequest($posRequest, $request->all());

        return redirect()->route('pos-requests.index')->with('success', 'POS Request updated successfully');
    }

    public function approve(Request $request, PosRequest $posRequest)
    {
        $request->validate([
            'remarks' => 'nullable|string|max:1000',
            'approver_data' => 'nullable|array',
        ]);

        $requestType = $posRequest->requestType;
        $currentLevel = (int) $posRequest->current_approval_level;
        $authUserId = (int) auth()->id();

        if ($currentLevel <= 0 || !$this->canUserApproveLevel($requestType, $currentLevel, $authUserId)) {
            return redirect()->back()->with('error', 'You are not assigned as an approver for this approval level.');
        }

        $alreadyApprovedCurrentLevel = $posRequest->approvals()
            ->where('level', $currentLevel)
            ->where('user_id', $authUserId)
            ->exists();

        if ($alreadyApprovedCurrentLevel) {
            return redirect()->back()->with('error', 'You already approved this level.');
        }

        DB::transaction(function () use ($request, $posRequest) {
            $requestType = $posRequest->requestType;

            PosRequestApproval::create([
                'pos_request_id' => $posRequest->id,
                'user_id' => auth()->id(),
                'level' => $posRequest->current_approval_level,
                'status' => 'approved',
                'remarks' => $request->remarks,
            ]);

            if ($request->has('approver_data') && is_array($request->approver_data)) {
                $posRequest->update([
                    'approver_data' => array_merge($posRequest->approver_data ?? [], $request->approver_data)
                ]);
            }

            if ($posRequest->current_approval_level >= $requestType->approval_levels) {
                $posRequest->update([
                    'status' => 'Approved',
                    'current_approval_level' => 0,
                ]);
                $this->posRequestService->processApprovedRequest($posRequest->fresh());
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
        $this->posRequestService->notifyCurrentApprovers($posRequest);

        return redirect()->back()->with('success', 'Request approved successfully');
    }

    public function reject(Request $request, PosRequest $posRequest)
    {
        $request->validate([
            'remarks' => 'required|string|max:1000',
        ]);

        $requestType = $posRequest->requestType;
        $currentLevel = (int) $posRequest->current_approval_level;
        $authUserId = (int) auth()->id();

        if ($currentLevel <= 0 || !$this->canUserApproveLevel($requestType, $currentLevel, $authUserId)) {
            return redirect()->back()->with('error', 'You are not assigned as an approver for this approval level.');
        }

        DB::transaction(function () use ($request, $posRequest) {
            PosRequestApproval::create([
                'pos_request_id' => $posRequest->id,
                'user_id' => auth()->id(),
                'level' => $posRequest->current_approval_level,
                'status' => 'rejected',
                'remarks' => $request->remarks,
            ]);

            $posRequest->update([
                'status' => 'Rejected',
                'current_approval_level' => 0,
            ]);
        });

        return redirect()->back()->with('success', 'Request rejected successfully');
    }

    private function canUserApproveLevel(RequestType $requestType, int $level, int $userId): bool
    {
        $assignedUsers = collect($requestType->approver_matrix ?? [])
            ->firstWhere('level', $level)['user_ids'] ?? [];

        $assignedUsers = collect($assignedUsers)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($assignedUsers->isEmpty()) {
            return true;
        }

        return $assignedUsers->contains($userId);
    }
}
