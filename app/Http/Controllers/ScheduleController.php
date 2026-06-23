<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\ScheduleStore;
use App\Models\AttendanceLog;
use App\Models\DepartmentNode;
use App\Models\User;
use App\Models\Store;
use App\Models\Ticket;
use App\Mail\ScheduleChangeRequestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller implements HasMiddleware
{
    private const REQUEST_TYPE_SCHEDULE_CHANGE = 'schedule_change';
    private const REQUEST_TYPE_ACTUAL_TIME_ADJUSTMENT = 'actual_time_adjustment';

    public function __construct(
        private \App\Services\OrganizationReferenceService $organizationReferences
    ) {}

    private function buildActualTimesByDate($logs): array
    {
        return collect($logs)
            ->groupBy(fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString())
            ->map(function ($dailyLogs) {
                return [
                    'actual_time_in' => $dailyLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String(),
                    'actual_time_out' => $dailyLogs->filter(fn ($log) => $log->type === 'time_out')->last()?->log_time?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    private function resolveSegmentLogs($scheduleLogs, ScheduleStore $scheduleStore)
    {
        $graceMinutes = (int) ($scheduleStore->grace_period_minutes ?? 30);
        $windowStart = $scheduleStore->start_time->copy()->subMinutes($graceMinutes);
        $windowEnd = $scheduleStore->end_time->copy();

        return collect($scheduleLogs)->filter(function ($log) use ($scheduleStore, $windowStart, $windowEnd) {
            if ((int) $log->schedule_store_id === (int) $scheduleStore->id) {
                return true;
            }

            return $log->log_time
                && $log->log_time->betweenIncluded($windowStart, $windowEnd);
        })->values();
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:schedules.view', only: ['index', 'reportData', 'missingSchedules', 'completeSchedules']),
            new Middleware('can:schedules.create', only: ['store', 'import']),
            new Middleware('can:schedules.edit', only: ['update']),
            new Middleware('can:schedules.approve', only: ['approveChangeRequest', 'rejectChangeRequest']),
            new Middleware('can:schedules.delete', only: ['destroy', 'duplicates', 'destroyDuplicates']),
        ];
    }

    public function index(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        $query = Schedule::with(['user', 'creator', 'updater', 'scheduleStores.store', 'scheduleStores.ticket.item']);

        $query->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart);

        $filterDeptId  = $request->filled('department_id')      ? (int) $request->department_id      : null;
        $filterNodeId  = $request->filled('department_node_id') ? (int) $request->department_node_id : null;

        // Default to the authenticated user's department on first load
        if (!$filterDeptId && !$filterNodeId) {
            $filterDeptId = auth()->user()->department_id ? (int) auth()->user()->department_id : null;
        }

        if ($filterNodeId) {
            $descendantIds = \App\Models\DepartmentNode::getAllDescendantIds($filterNodeId);
            $nodeIds = array_merge([$filterNodeId], $descendantIds);
            $query->whereHas('user', fn($q) => $q->whereIn('department_node_id', $nodeIds));
        } elseif ($filterDeptId) {
            $query->whereHas('user', fn($q) => $q->where('department_id', $filterDeptId));
        }

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('user_id', auth()->id());
            } else {
                $query->where('user_id', $request->user_id);
            }
        }

        if ($request->filled('sub_unit')) {
            $query->whereHas('user', fn($q) => $q->where('org_path', 'like', '%'.$request->sub_unit.'%'));
        }

        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStores', fn ($sq) => $sq->where('store_id', $request->store_id));
        }

        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('priority')) {
            $priorities = is_array($request->priority) ? $request->priority : [$request->priority];
            $query->whereHas('scheduleStores.ticket', function($q) use ($priorities) {
                $q->where(function($sub) use ($priorities) {
                    $sub->whereIn('priority', $priorities)
                        ->orWhereHas('item', function($iq) use ($priorities) {
                            $iq->whereIn('priority', $priorities);
                        });
                });
            });
        }

        $rawSchedules = $query->get();

        // Batch-load attendance logs (avoids N+1)
        $scheduleIds = $rawSchedules->pluck('id')->filter()->values();
        $attendanceLogs = collect();

        foreach ($scheduleIds->chunk(1000) as $scheduleIdChunk) {
            $attendanceLogs = $attendanceLogs->concat(
                AttendanceLog::whereIn('schedule_id', $scheduleIdChunk->all())
                    ->notVoided()
                    ->orderBy('log_time')
                    ->get(['schedule_id', 'schedule_store_id', 'type', 'log_time'])
            );
        }
        
        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');
        $editableUserIds = $this->editableScheduleUserIds($request->user());
        $scheduleChangeRequests = $this->scheduleChangeRequestRows($request->user());
        $canDirectEditSchedules = $request->user()->can('schedules.edit');

        $directActualTimeUserIds = $this->directActualTimeUserIds($request->user());
        $requestableActualTimeUserIds = $this->requestableActualTimeUserIds($request->user());

        $schedules = $rawSchedules->map(function($schedule) use ($logsBySchedule, $editableUserIds, $canDirectEditSchedules, $directActualTimeUserIds, $requestableActualTimeUserIds) {
            $schedLogs     = $logsBySchedule->get($schedule->id, collect());
            $actualTimeIn  = $schedLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String();
            $actualTimeOut = $schedLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String();
            $actualTimesByDate = $this->buildActualTimesByDate($schedLogs);

            return [
                'id'              => $schedule->id,
                'user_id'         => $schedule->user_id,
                'status'          => $schedule->status,
                'start_time'      => $schedule->start_time->toIso8601String(),
                'end_time'        => $schedule->end_time->toIso8601String(),
                'pickup_start'    => $schedule->pickup_start ? substr($schedule->pickup_start, 0, 5) : null,
                'pickup_end'      => $schedule->pickup_end   ? substr($schedule->pickup_end,   0, 5) : null,
                'backlogs_start'  => $schedule->backlogs_start ? substr($schedule->backlogs_start, 0, 5) : null,
                'backlogs_end'    => $schedule->backlogs_end   ? substr($schedule->backlogs_end,   0, 5) : null,
                'remarks'         => $schedule->remarks,
                'created_by'      => $schedule->created_by,
                'created_by_name' => $schedule->creator?->name,
                'updated_by'      => $schedule->updated_by,
                'updated_by_name' => $schedule->updater?->name,
                'created_at'      => $schedule->created_at?->toIso8601String(),
                'updated_at'      => $schedule->updated_at?->toIso8601String(),
                'can_edit'        => $canDirectEditSchedules && in_array((int) $schedule->user_id, $editableUserIds, true),
                'can_request_change' => ! $canDirectEditSchedules && (int) $schedule->user_id === (int) auth()->id(),
                'can_edit_actual_time' => in_array((int) $schedule->user_id, $directActualTimeUserIds, true),
                'can_request_actual_time' => in_array((int) $schedule->user_id, $requestableActualTimeUserIds, true),
                'actual_time_in'  => $actualTimeIn,
                'actual_time_out' => $actualTimeOut,
                'actual_times_by_date' => $actualTimesByDate,
                'user'            => $schedule->user,
                'schedule_stores' => $schedule->scheduleStores->map(function ($ss) use ($schedLogs) {
                    $segLogs = $this->resolveSegmentLogs($schedLogs, $ss);
                    $segmentActualTimesByDate = $this->buildActualTimesByDate($segLogs);
                    return [
                        'id'                   => $ss->id,
                        'store_id'             => $ss->store_id,
                        'start_time'           => $ss->start_time->toIso8601String(),
                        'end_time'             => $ss->end_time->toIso8601String(),
                        'grace_period_minutes' => $ss->grace_period_minutes ?? 30,
                        'remarks'              => $ss->remarks,
                        'store'                => $ss->store ? ['id' => $ss->store->id, 'name' => $ss->store->name] : null,
                        'actual_time_in'       => $segLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String(),
                        'actual_time_out'      => $segLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String(),
                        'actual_times_by_date' => $segmentActualTimesByDate,
                        'ticket'               => $ss->ticket ? [
                            'id'           => $ss->ticket->id,
                            'ticket_key'   => $ss->ticket->ticket_key,
                            'title'        => $ss->ticket->title,
                            'priority'     => $ss->ticket->item ? $ss->ticket->item->priority : $ss->ticket->priority,
                            'concern_type' => $ss->ticket->item?->concern_type,
                            'status'       => $ss->ticket->status,
                        ] : null,
                    ];
                }),
                'ticket' => $schedule->scheduleStores->whereNotNull('ticket_id')->first()?->ticket ? [
                    'id'           => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->id,
                    'ticket_key'   => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->ticket_key,
                    'title'        => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->title,
                    'priority'     => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->item
                                        ? $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->item->priority
                                        : $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->priority,
                    'concern_type' => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->item?->concern_type,
                    'status'       => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->status,
                ] : null,
            ];
        });
        
        $users = User::active()->with(['managers:id', 'departmentReference:id,code,name'])->orderBy('name')->get(['id', 'name', 'is_vacant', 'department_id', 'department_node_id', 'is_manager', 'date_hired']);
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $departmentNodes = \App\Models\DepartmentNode::select('id', 'parent_id', 'department_id', 'name')->get();
        $departments = Department::orderBy('name')->get(['id', 'name', 'is_active']);
        $activeDepartments = $departments->where('is_active', true)->values();

        // Pivot report metadata (cheap — just year lists, no schedule data)
        $currentYear = (int)date('Y');
        $yearExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y', start_time)"
            : 'YEAR(start_time)';

        $dbYears = Schedule::selectRaw("{$yearExpression} as year")
            ->distinct()
            ->pluck('year')
            ->map(fn($y) => (int)$y)
            ->toArray();

        $availableYears = collect($dbYears)
            ->merge([$currentYear - 1, $currentYear, $currentYear + 1])
            ->map(fn($y) => (int)$y)
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();

        $selectedYearsInput = $request->input('report_years');
        $selectedYears = $selectedYearsInput
            ? collect((array)$selectedYearsInput)->map(fn($y) => (int)$y)->unique()->sort()->values()->toArray()
            : [2024, 2025, 2026];

        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A'];

        return Inertia::render('Schedules/Index', [
            'schedules'      => $schedules,
            'users'          => $users,
            'stores'         => $stores,
            'departmentNodes'=> $departmentNodes,
            'departments'    => $departments,
            'activeDepartments' => $activeDepartments,
            'hierarchicalDepartments' => $this->organizationReferences->tree(),
            'editableUserIds' => $editableUserIds,
            'scheduleChangeRequests' => $scheduleChangeRequests,
            'pivotYears'     => $selectedYears,
            'availableYears' => $availableYears,
            'pivotStatuses'  => $pivotStatuses,
            'filters'        => array_merge(
                $request->only(['user_id', 'report_years', 'sub_unit', 'store_id', 'status', 'priority']),
                [
                    'department_id'      => $filterDeptId  ?? '',
                    'department_node_id' => $filterNodeId  ?? '',
                    'start' => $rangeStart->toDateString(),
                    'end'   => $rangeEnd->toDateString(),
                ]
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id'                          => 'required|exists:users,id',
            'status'                           => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday,N/A',
            'stores'                           => 'required|array|min:1',
            'stores.*.store_id'                => 'required_unless:status,SL,VL,Restday,Holiday,N/A|nullable|exists:stores,id',
            'stores.*.ticket_id'               => 'nullable|exists:tickets,id',
            'stores.*.start_time'              => 'required|date',
            'stores.*.end_time'                => 'required|date',
            'stores.*.grace_period_minutes'    => 'nullable|integer|min:0|max:480',
            'stores.*.remarks'                 => 'nullable|string|max:1000',
            'pickup_start'                     => 'nullable|string',
            'pickup_end'                       => 'nullable|string',
            'backlogs_start'                   => 'nullable|string',
            'backlogs_end'                     => 'nullable|string',
        ], [
            'stores.*.store_id.required_unless' => 'Location is required for every schedule entry.',
        ]);

        abort_unless(
            in_array((int) $request->user_id, $this->creatableScheduleUserIds($request->user()), true),
            403,
            'You can only create schedules for users under your org chart level.'
        );

        $storeEntries = $request->input('stores');
        $expandedStoreEntries = $this->expandStoreEntries($storeEntries);
        $startTime = Carbon::parse(collect($storeEntries)->min('start_time'));
        $endTime   = Carbon::parse(collect($storeEntries)->max('end_time'));

        if ($this->hasScheduleOverlap((int) $request->user_id, $expandedStoreEntries, null, $request->status)) {
            return redirect()->back()->withErrors(['stores' => 'This user already has a schedule that overlaps with the selected time range.']);
        }

        $schedule = Schedule::create([
            'user_id'        => $request->user_id,
            'created_by'     => auth()->id(),
            'updated_by'     => auth()->id(),
            'status'         => $request->status,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'pickup_start'   => $request->pickup_start,
            'pickup_end'     => $request->pickup_end,
            'backlogs_start' => $request->backlogs_start,
            'backlogs_end'   => $request->backlogs_end,
        ]);

        foreach ($expandedStoreEntries as $entry) {
            $schedule->scheduleStores()->create([
                'store_id'             => $entry['store_id'] ?? null,
                'ticket_id'            => $entry['ticket_id'] ?? null,
                'start_time'           => $entry['start_time'],
                'end_time'             => $entry['end_time'],
                'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                'remarks'              => $entry['remarks'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Schedule created successfully');
    }

    public function update(Request $request, Schedule $schedule)
    {
        $payload = $this->validateScheduleUpdatePayload($request->all());
        $editableUserIds = $this->editableScheduleUserIds($request->user());

        abort_unless(
            in_array((int) $schedule->user_id, $editableUserIds, true)
                && in_array((int) $payload['user_id'], $editableUserIds, true),
            403,
            'You can only edit schedules for users under your org chart level.'
        );

        $this->applyScheduleUpdate($payload, $schedule, auth()->id());

        return redirect()->back()->with('success', 'Schedule updated successfully');
    }

    public function storeChangeRequest(Request $request, Schedule $schedule)
    {
        abort_unless((int) $schedule->user_id === (int) $request->user()->id, 403, 'You can only request changes for your own schedule.');
        abort_if($request->user()->can('schedules.edit'), 403, 'Users with direct edit access should update schedules directly.');

        $request->validate([
            'requester_remarks' => 'required|string|max:1000',
        ], [], ['requester_remarks' => 'request remarks']);

        $payload = $this->validateScheduleUpdatePayload($request->all());

        if ((int) $payload['user_id'] !== (int) $schedule->user_id) {
            return redirect()->back()->withErrors([
                'user_id' => 'Schedule change requests cannot reassign the schedule to another user.',
            ]);
        }

        $approverIds = $this->resolveScheduleChangeApproverIds($request->user());

        if (empty($approverIds)) {
            Log::warning('Schedule change request blocked: no eligible approver resolved.', [
                'requester_id' => $request->user()->id,
                'requester_name' => $request->user()->name,
                'schedule_id' => $schedule->id,
                'hint' => 'Ensure an active manager/admin in the requester\'s reporting line has the "schedules.approve" permission, then reset the permission cache.',
            ]);

            return redirect()->back()->withErrors([
                'approver' => 'No eligible schedule approver is available for this request. Please contact your administrator to assign a schedule approver.',
            ]);
        }

        $changeRequest = ScheduleChangeRequest::updateOrCreate(
            [
                'schedule_id' => $schedule->id,
                'requester_id' => $request->user()->id,
                'request_type' => self::REQUEST_TYPE_SCHEDULE_CHANGE,
                'status' => 'pending',
            ],
            [
                'request_type' => self::REQUEST_TYPE_SCHEDULE_CHANGE,
                'assigned_approver_ids' => $approverIds,
                'original_payload' => $this->schedulePayload($schedule->fresh(['scheduleStores'])),
                'requested_payload' => $payload,
                'requester_remarks' => $request->input('requester_remarks'),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'approver_remarks' => null,
            ]
        );

        $changeRequest->load(['schedule.user', 'schedule.scheduleStores.store', 'schedule.scheduleStores.ticket', 'requester']);
        $this->notifyScheduleChangeApprovers($changeRequest);

        return redirect()->back()->with('success', 'Schedule change request submitted for approval.');
    }

    public function updateActualTimes(Request $request, Schedule $schedule)
    {
        $payload = $this->validateActualTimePayload($request->all(), $schedule);

        abort_unless(
            in_array((int) $schedule->user_id, $this->directActualTimeUserIds($request->user()), true),
            403,
            'You are not allowed to directly edit these actual times.'
        );

        $this->applyActualTimeAdjustment($payload, $schedule, $request->user()->id);

        return redirect()->back()->with('success', 'Actual times updated successfully.');
    }

    public function storeActualTimeRequest(Request $request, Schedule $schedule)
    {
        $request->validate([
            'requester_remarks' => 'required|string|max:1000',
        ], [], ['requester_remarks' => 'request remarks']);

        $payload = $this->validateActualTimePayload($request->all(), $schedule);

        abort_unless(
            in_array((int) $schedule->user_id, $this->requestableActualTimeUserIds($request->user()), true),
            403,
            'You can only request actual time adjustments for your own schedule or users under your org chart level.'
        );

        $approverIds = $this->resolveScheduleChangeApproverIds($request->user());

        if (empty($approverIds)) {
            Log::warning('Actual time adjustment request blocked: no eligible approver resolved.', [
                'requester_id' => $request->user()->id,
                'requester_name' => $request->user()->name,
                'schedule_id' => $schedule->id,
                'hint' => 'Ensure an active manager/admin in the requester\'s reporting line has the "schedules.approve" permission, then reset the permission cache.',
            ]);

            return redirect()->back()->withErrors([
                'approver' => 'No eligible schedule approver is available for this request. Please contact your administrator to assign a schedule approver.',
            ]);
        }

        $changeRequest = ScheduleChangeRequest::updateOrCreate(
            [
                'schedule_id' => $schedule->id,
                'requester_id' => $request->user()->id,
                'request_type' => self::REQUEST_TYPE_ACTUAL_TIME_ADJUSTMENT,
                'status' => 'pending',
            ],
            [
                'request_type' => self::REQUEST_TYPE_ACTUAL_TIME_ADJUSTMENT,
                'assigned_approver_ids' => $approverIds,
                'original_payload' => $this->actualTimePayload($schedule, $payload),
                'requested_payload' => $payload,
                'requester_remarks' => $request->input('requester_remarks'),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'approver_remarks' => null,
            ]
        );

        $changeRequest->load(['schedule.user', 'schedule.scheduleStores.store', 'schedule.scheduleStores.ticket', 'requester']);
        $this->notifyScheduleChangeApprovers($changeRequest);

        return redirect()->back()->with('success', 'Actual time adjustment submitted for approval.');
    }

    public function approveChangeRequest(Request $request, ScheduleChangeRequest $scheduleChangeRequest)
    {
        $this->authorizeScheduleChangeApprover($scheduleChangeRequest);

        if ($scheduleChangeRequest->status !== 'pending') {
            return redirect()->back()->withErrors(['request' => 'This schedule change request is no longer pending.']);
        }

        $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($request, $scheduleChangeRequest) {
            if ($scheduleChangeRequest->request_type === self::REQUEST_TYPE_ACTUAL_TIME_ADJUSTMENT) {
                $this->applyActualTimeAdjustment(
                    $scheduleChangeRequest->requested_payload,
                    $scheduleChangeRequest->schedule,
                    auth()->id()
                );
            } else {
                $this->applyScheduleUpdate($scheduleChangeRequest->requested_payload, $scheduleChangeRequest->schedule, auth()->id());
            }

            $scheduleChangeRequest->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'approver_remarks' => $request->remarks,
            ]);
        });

        $scheduleChangeRequest->refresh()->load(['schedule.user', 'schedule.scheduleStores.store', 'schedule.scheduleStores.ticket', 'requester']);
        $this->notifyScheduleChangeRequester($scheduleChangeRequest, 'approved');

        return redirect()->back()->with('success', 'Request approved and applied.');
    }

    public function rejectChangeRequest(Request $request, ScheduleChangeRequest $scheduleChangeRequest)
    {
        $this->authorizeScheduleChangeApprover($scheduleChangeRequest);

        if ($scheduleChangeRequest->status !== 'pending') {
            return redirect()->back()->withErrors(['request' => 'This schedule change request is no longer pending.']);
        }

        $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        $scheduleChangeRequest->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'approver_remarks' => $request->remarks,
        ]);

        $scheduleChangeRequest->load(['schedule.user', 'schedule.scheduleStores.store', 'schedule.scheduleStores.ticket', 'requester']);
        $this->notifyScheduleChangeRequester($scheduleChangeRequest, 'rejected');

        return redirect()->back()->with('success', 'Schedule change request rejected.');
    }

    public function cancelChangeRequest(ScheduleChangeRequest $scheduleChangeRequest)
    {
        abort_unless((int) $scheduleChangeRequest->requester_id === (int) auth()->id(), 403);
        abort_unless($scheduleChangeRequest->status === 'pending', 403);

        $scheduleChangeRequest->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Schedule change request cancelled.');
    }

    private function validateActualTimePayload(array $payload, Schedule $schedule): array
    {
        $validated = validator($payload, [
            'schedule_store_id' => 'nullable|integer|exists:schedule_stores,id',
            'schedule_date' => 'required|date',
            'actual_time_in' => 'nullable|date',
            'actual_time_out' => 'nullable|date',
            'clear_time_in' => 'nullable|boolean',
            'clear_time_out' => 'nullable|boolean',
            'requester_remarks' => 'nullable|string|max:1000',
        ])->validate();

        $validated['schedule_store_id'] = isset($validated['schedule_store_id']) ? (int) $validated['schedule_store_id'] : null;
        $validated['schedule_date'] = Carbon::parse($validated['schedule_date'], 'Asia/Manila')->toDateString();
        $validated['clear_time_in'] = filter_var($validated['clear_time_in'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validated['clear_time_out'] = filter_var($validated['clear_time_out'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($validated['schedule_store_id']) {
            $belongsToSchedule = ScheduleStore::where('schedule_id', $schedule->id)
                ->where('id', $validated['schedule_store_id'])
                ->exists();

            if (!$belongsToSchedule) {
                throw ValidationException::withMessages([
                    'schedule_store_id' => 'The selected schedule entry does not belong to this schedule.',
                ]);
            }
        }

        $rangeSource = $validated['schedule_store_id']
            ? ScheduleStore::find($validated['schedule_store_id'])
            : $schedule;

        $rangeStart = $rangeSource->start_time->copy()->timezone('Asia/Manila')->toDateString();
        $rangeEnd = $rangeSource->end_time->copy()->timezone('Asia/Manila')->toDateString();

        if ($validated['schedule_date'] < $rangeStart || $validated['schedule_date'] > $rangeEnd) {
            throw ValidationException::withMessages([
                'schedule_date' => 'The selected schedule date is outside this schedule entry.',
            ]);
        }

        foreach (['actual_time_in', 'actual_time_out'] as $field) {
            if (empty($validated[$field])) {
                $validated[$field] = null;
                continue;
            }

            $dateTime = Carbon::parse($validated[$field], 'Asia/Manila');

            if ($dateTime->copy()->timezone('Asia/Manila')->toDateString() !== $validated['schedule_date']) {
                throw ValidationException::withMessages([
                    $field => 'Actual times must use the selected schedule date.',
                ]);
            }

            $validated[$field] = $dateTime->toIso8601String();
        }

        if ($validated['actual_time_in'] && $validated['actual_time_out']) {
            if (Carbon::parse($validated['actual_time_in'])->gt(Carbon::parse($validated['actual_time_out']))) {
                throw ValidationException::withMessages([
                    'actual_time_out' => 'Actual Time Out must be after Actual Time In.',
                ]);
            }
        }

        if (
            !$validated['actual_time_in']
            && !$validated['actual_time_out']
            && !$validated['clear_time_in']
            && !$validated['clear_time_out']
        ) {
            throw ValidationException::withMessages([
                'actual_time_in' => 'Enter or clear at least one actual time.',
            ]);
        }

        return $validated;
    }

    private function actualTimePayload(Schedule $schedule, array $payload): array
    {
        $logs = $this->actualTimeLogsForPayload($schedule, $payload)
            ->orderBy('log_time')
            ->get(['type', 'log_time']);

        return [
            'schedule_store_id' => $payload['schedule_store_id'] ?? null,
            'schedule_date' => $payload['schedule_date'],
            'actual_time_in' => $logs->firstWhere('type', 'time_in')?->log_time?->toIso8601String(),
            'actual_time_out' => $logs->where('type', 'time_out')->last()?->log_time?->toIso8601String(),
            'clear_time_in' => false,
            'clear_time_out' => false,
        ];
    }

    private function applyActualTimeAdjustment(array $payload, Schedule $schedule, int $updaterId): void
    {
        $payload = $this->validateActualTimePayload($payload, $schedule);

        DB::transaction(function () use ($payload, $schedule, $updaterId) {
            foreach ([
                'time_in' => ['value' => 'actual_time_in', 'clear' => 'clear_time_in'],
                'time_out' => ['value' => 'actual_time_out', 'clear' => 'clear_time_out'],
            ] as $type => $fields) {
                $hasNewValue = !empty($payload[$fields['value']]);
                $shouldClear = (bool) ($payload[$fields['clear']] ?? false);

                if (!$hasNewValue && !$shouldClear) {
                    continue;
                }

                $this->actualTimeLogsForPayload($schedule, $payload)
                    ->where('type', $type)
                    ->update([
                        'voided_at' => now(),
                        'voided_by' => $updaterId,
                        'void_reason' => 'Schedule actual time adjustment',
                    ]);

                if ($hasNewValue) {
                    AttendanceLog::create([
                        'user_id' => $schedule->user_id,
                        'schedule_id' => $schedule->id,
                        'schedule_store_id' => $payload['schedule_store_id'] ?? null,
                        'type' => $type,
                        'log_time' => Carbon::parse($payload[$fields['value']]),
                        'device_info' => 'Manual schedule actual-time adjustment',
                        'ip_address' => request()?->ip(),
                    ]);
                }
            }
        });
    }

    private function actualTimeLogsForPayload(Schedule $schedule, array $payload)
    {
        $dayStart = Carbon::parse($payload['schedule_date'], 'Asia/Manila')->startOfDay();
        $dayEnd = Carbon::parse($payload['schedule_date'], 'Asia/Manila')->endOfDay();

        $query = AttendanceLog::query()
            ->notVoided()
            ->where('user_id', $schedule->user_id)
            ->where('schedule_id', $schedule->id)
            ->whereBetween('log_time', [$dayStart, $dayEnd]);

        if (!empty($payload['schedule_store_id'])) {
            $scheduleStore = ScheduleStore::find($payload['schedule_store_id']);

            if ($scheduleStore) {
                $windowStart = $scheduleStore->start_time->copy()->subMinutes((int) ($scheduleStore->grace_period_minutes ?? 30));
                $windowEnd = $scheduleStore->end_time->copy();

                $query->where(function ($logQuery) use ($payload, $windowStart, $windowEnd) {
                    $logQuery->where('schedule_store_id', $payload['schedule_store_id'])
                        ->orWhereBetween('log_time', [$windowStart, $windowEnd]);
                });
            }
        }

        return $query;
    }

    private function validateScheduleUpdatePayload(array $payload): array
    {
        return validator($payload, [
            'user_id'                          => 'required|exists:users,id',
            'status'                           => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday,N/A',
            'stores'                           => 'required|array|min:1',
            'stores.*.id'                      => 'nullable|integer|exists:schedule_stores,id',
            'stores.*.store_id'                => 'required_unless:status,SL,VL,Restday,Holiday,N/A|nullable|exists:stores,id',
            'stores.*.ticket_id'               => 'nullable|exists:tickets,id',
            'stores.*.start_time'              => 'required|date',
            'stores.*.end_time'                => 'required|date',
            'stores.*.grace_period_minutes'    => 'nullable|integer|min:0|max:480',
            'stores.*.remarks'                 => 'nullable|string|max:1000',
            'pickup_start'                     => 'nullable|string',
            'pickup_end'                       => 'nullable|string',
            'backlogs_start'                   => 'nullable|string',
            'backlogs_end'                     => 'nullable|string',
            'scope_date'                       => 'nullable|date',
            'requester_remarks'                => 'nullable|string|max:1000',
        ], [
            'stores.*.store_id.required_unless' => 'Location is required for every schedule entry.',
        ])->validate();
    }

    private function applyScheduleUpdate(array $payload, Schedule $schedule, int $updaterId): void
    {
        $payload = $this->validateScheduleUpdatePayload($payload);
        $storeEntries = $payload['stores'];
        $expandedStoreEntries = $this->expandStoreEntries($storeEntries);
        $startTime = Carbon::parse(collect($storeEntries)->min('start_time'));
        $endTime = Carbon::parse(collect($storeEntries)->max('end_time'));
        $scopeDate = !empty($payload['scope_date']) ? Carbon::parse($payload['scope_date'])->toDateString() : null;

        if ($this->scheduleHasAttendanceLogs($schedule) && (int) $payload['user_id'] !== (int) $schedule->user_id) {
            throw ValidationException::withMessages([
                'user_id' => 'This schedule already has attendance logs and cannot be reassigned to another user.',
            ]);
        }

        if ($this->hasInvalidSubmittedScheduleStoreIds($schedule, $storeEntries)) {
            throw ValidationException::withMessages([
                'stores' => 'One or more selected schedule entries do not belong to this schedule.',
            ]);
        }

        if (
            $this->scheduleEntryWindowsChanged($schedule, $expandedStoreEntries, $scopeDate)
            && ($outsideAttendanceLog = $this->firstAttendanceLogOutsideEntries($schedule, $expandedStoreEntries, $scopeDate))
        ) {
            throw ValidationException::withMessages([
                'stores' => 'This schedule already has attendance logs. The selected date and time range must still include the existing '
                    . str_replace('_', ' ', $outsideAttendanceLog->type)
                    . ' log at '
                    . $outsideAttendanceLog->log_time?->copy()->timezone('Asia/Manila')->format('M d, Y h:i A')
                    . '.',
            ]);
        }

        if ($this->hasScheduleOverlap((int) $payload['user_id'], $expandedStoreEntries, $schedule->id, $payload['status'])) {
            throw ValidationException::withMessages([
                'stores' => 'This user already has a schedule that overlaps with the selected time range.',
            ]);
        }

        DB::transaction(function () use ($payload, $schedule, $startTime, $endTime, $expandedStoreEntries, $scopeDate, $updaterId) {
            if ($scopeDate && $this->scheduleHasEntriesOutsideScope($schedule, $scopeDate)) {
                $this->splitScopedSchedule($payload, $schedule, $expandedStoreEntries, $scopeDate, $updaterId);

                return;
            }

            $schedule->update([
                'user_id'        => $payload['user_id'],
                'updated_by'     => $updaterId,
                'status'         => $payload['status'],
                'start_time'     => $startTime,
                'end_time'       => $endTime,
                'pickup_start'   => $payload['pickup_start'] ?? null,
                'pickup_end'     => $payload['pickup_end'] ?? null,
                'backlogs_start' => $payload['backlogs_start'] ?? null,
                'backlogs_end'   => $payload['backlogs_end'] ?? null,
            ]);

            if ($scopeDate) {
                $this->syncScopedScheduleStores($schedule, $expandedStoreEntries, $scopeDate);
                $this->refreshScheduleBounds($schedule);
            } else {
                $schedule->scheduleStores()->delete();
                foreach ($expandedStoreEntries as $entry) {
                    $schedule->scheduleStores()->create($this->scheduleStorePayload($entry));
                }
            }
        });
    }

    private function schedulePayload(Schedule $schedule): array
    {
        return [
            'user_id' => (int) $schedule->user_id,
            'status' => $schedule->status,
            'pickup_start' => $schedule->pickup_start ? substr($schedule->pickup_start, 0, 5) : null,
            'pickup_end' => $schedule->pickup_end ? substr($schedule->pickup_end, 0, 5) : null,
            'backlogs_start' => $schedule->backlogs_start ? substr($schedule->backlogs_start, 0, 5) : null,
            'backlogs_end' => $schedule->backlogs_end ? substr($schedule->backlogs_end, 0, 5) : null,
            'stores' => $schedule->scheduleStores->map(fn (ScheduleStore $scheduleStore) => [
                'id' => $scheduleStore->id,
                'store_id' => $scheduleStore->store_id,
                'ticket_id' => $scheduleStore->ticket_id,
                'start_time' => $scheduleStore->start_time?->toIso8601String(),
                'end_time' => $scheduleStore->end_time?->toIso8601String(),
                'grace_period_minutes' => $scheduleStore->grace_period_minutes ?? 30,
                'remarks' => $scheduleStore->remarks,
            ])->values()->all(),
            'scope_date' => null,
        ];
    }

    public function destroy(Schedule $schedule)
    {
        $scheduleStoreIds = $schedule->scheduleStores()->pluck('id')->map(fn ($id) => (int) $id)->values();

        DB::transaction(function () use ($schedule, $scheduleStoreIds) {
            DB::table('attendance_logs')
                ->where(function ($query) use ($schedule, $scheduleStoreIds) {
                    $query->where('schedule_id', $schedule->id);

                    if ($scheduleStoreIds->isNotEmpty()) {
                        $query->orWhereIn('schedule_store_id', $scheduleStoreIds->all());
                    }
                })
                ->update([
                    'schedule_id' => null,
                    'schedule_store_id' => null,
                ]);

            DB::table('schedule_stores')->where('schedule_id', $schedule->id)->delete();
            DB::table('schedules')->where('id', $schedule->id)->delete();
        });

        return redirect()->back()->with('success', 'Schedule deleted successfully');
    }

    public function duplicates(Request $request)
    {
        $groups = $this->duplicateScheduleGroups($request);

        return response()->json([
            'groups' => $groups->values(),
            'group_count' => $groups->count(),
            'duplicate_count' => $groups->sum('duplicate_count'),
        ]);
    }

    public function destroyDuplicates(Request $request)
    {
        $groups = $this->duplicateScheduleGroups($request);
        $duplicateScheduleStoreIds = $groups
            ->flatMap(fn (array $group) => $group['duplicate_schedule_store_ids'])
            ->unique()
            ->values();
        $duplicateScheduleIds = $groups
            ->flatMap(fn (array $group) => $group['duplicate_schedule_ids_to_delete'] ?? [])
            ->unique()
            ->values();

        if ($duplicateScheduleStoreIds->isEmpty() && $duplicateScheduleIds->isEmpty()) {
            return response()->json([
                'deleted_schedule_stores' => 0,
                'deleted_schedules' => 0,
                'group_count' => 0,
            ]);
        }

        $affectedScheduleIds = $duplicateScheduleIds->map(fn ($id) => (int) $id);

        if ($duplicateScheduleStoreIds->isNotEmpty()) {
            $affectedScheduleIds = $affectedScheduleIds->merge(
                DB::table('schedule_stores')
                    ->whereIn('id', $duplicateScheduleStoreIds->all())
                    ->pluck('schedule_id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        $affectedScheduleIds = $affectedScheduleIds->unique()->values();

        $deletedScheduleStores = 0;
        $deletedSchedules = 0;

        DB::transaction(function () use (
            $duplicateScheduleStoreIds,
            $duplicateScheduleIds,
            $affectedScheduleIds,
            &$deletedScheduleStores,
            &$deletedSchedules
        ) {
            $scheduleStoreIdsForDeletedSchedules = $duplicateScheduleIds->isEmpty()
                ? collect()
                : DB::table('schedule_stores')
                    ->whereIn('schedule_id', $duplicateScheduleIds->all())
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

            $scheduleStoreIdsToDelete = $duplicateScheduleStoreIds
                ->merge($scheduleStoreIdsForDeletedSchedules)
                ->unique()
                ->values();

            if ($scheduleStoreIdsToDelete->isNotEmpty() || $duplicateScheduleIds->isNotEmpty()) {
                DB::table('attendance_logs')
                    ->where(function ($query) use ($scheduleStoreIdsToDelete, $duplicateScheduleIds) {
                        if ($scheduleStoreIdsToDelete->isNotEmpty()) {
                            $query->whereIn('schedule_store_id', $scheduleStoreIdsToDelete->all());
                        }

                        if ($duplicateScheduleIds->isNotEmpty()) {
                            $method = $scheduleStoreIdsToDelete->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                            $query->{$method}('schedule_id', $duplicateScheduleIds->all());
                        }
                    })
                    ->update([
                        'schedule_id' => null,
                        'schedule_store_id' => null,
                    ]);
            }

            if ($scheduleStoreIdsToDelete->isNotEmpty()) {
                $deletedScheduleStores = DB::table('schedule_stores')
                    ->whereIn('id', $scheduleStoreIdsToDelete->all())
                    ->delete();
            }

            $orphanScheduleIds = DB::table('schedules as s')
                ->whereIn('s.id', $affectedScheduleIds->all())
                ->whereNotIn('s.id', $duplicateScheduleIds->all())
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('schedule_stores as ss')
                        ->whereColumn('ss.schedule_id', 's.id');
                })
                ->pluck('s.id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $scheduleIdsToDelete = $duplicateScheduleIds
                ->merge($orphanScheduleIds)
                ->unique()
                ->values();

            if ($scheduleIdsToDelete->isNotEmpty()) {
                DB::table('attendance_logs')
                    ->whereIn('schedule_id', $scheduleIdsToDelete->all())
                    ->update([
                        'schedule_id' => null,
                        'schedule_store_id' => null,
                    ]);

                $deletedSchedules = DB::table('schedules')
                    ->whereIn('id', $scheduleIdsToDelete->all())
                    ->delete();
            }

            $remainingScheduleIds = $affectedScheduleIds
                ->diff($scheduleIdsToDelete)
                ->values();

            if ($remainingScheduleIds->isNotEmpty()) {
                Schedule::whereIn('id', $remainingScheduleIds->all())
                    ->get()
                    ->each(fn (Schedule $schedule) => $this->refreshScheduleBounds($schedule));
            }
        });

        return response()->json([
            'deleted_schedule_stores' => $deletedScheduleStores,
            'deleted_schedules' => $deletedSchedules,
            'group_count' => $groups->count(),
        ]);
    }

    private function duplicateScheduleGroups(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        $rows = DB::table('schedules as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('schedule_stores as ss', 'ss.schedule_id', '=', 's.id')
            ->leftJoin('stores as st', 'st.id', '=', 'ss.store_id')
            ->leftJoin('tickets as t', 't.id', '=', 'ss.ticket_id')
            ->where(function ($query) use ($rangeStart, $rangeEnd) {
                $query->where(function ($segmentQuery) use ($rangeStart, $rangeEnd) {
                    $segmentQuery->whereNotNull('ss.id')
                        ->where('ss.start_time', '<=', $rangeEnd)
                        ->where('ss.end_time', '>=', $rangeStart);
                })->orWhere(function ($scheduleQuery) use ($rangeStart, $rangeEnd) {
                    $scheduleQuery->whereNull('ss.id')
                        ->where('s.start_time', '<=', $rangeEnd)
                        ->where('s.end_time', '>=', $rangeStart);
                });
            })
            ->when($request->filled('user_id'), function ($query) use ($request) {
                if ($request->user_id === 'my') {
                    $query->where('s.user_id', auth()->id());
                    return;
                }

                $query->where('s.user_id', $request->user_id);
            })
            ->when($request->filled('sub_unit'), fn ($query) => $query->where('u.org_path', 'like', '%'.$request->sub_unit.'%'))
            ->when($request->filled('store_id'), fn ($query) => $query->where('ss.store_id', $request->store_id))
            ->select([
                'ss.id as schedule_store_id',
                's.id as schedule_id',
                'ss.store_id',
                'ss.ticket_id',
                'ss.start_time as schedule_store_start_time',
                'ss.end_time as schedule_store_end_time',
                'ss.grace_period_minutes',
                'ss.remarks as visit_remarks',
                's.user_id',
                's.status',
                's.start_time as schedule_start_time',
                's.end_time as schedule_end_time',
                's.pickup_start',
                's.pickup_end',
                's.backlogs_start',
                's.backlogs_end',
                's.remarks as schedule_remarks',
                's.created_at',
                'u.name as user_name',
                'u.org_path as sub_unit',
                'st.name as store_name',
                't.ticket_key',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $scheduleIds = $rows->pluck('schedule_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $scheduleStoreIds = $rows->pluck('schedule_store_id')->filter()->map(fn ($id) => (int) $id)->values();
        $scheduleLogCounts = AttendanceLog::whereIn('schedule_id', $scheduleIds->all())
            ->notVoided()
            ->select('schedule_id', DB::raw('COUNT(*) as log_count'))
            ->groupBy('schedule_id')
            ->pluck('log_count', 'schedule_id');
        $scheduleStoreLogCounts = collect();

        if ($scheduleStoreIds->isNotEmpty()) {
            $scheduleStoreLogCounts = AttendanceLog::whereIn('schedule_store_id', $scheduleStoreIds->all())
                ->notVoided()
                ->select('schedule_store_id', DB::raw('COUNT(*) as log_count'))
                ->groupBy('schedule_store_id')
                ->pluck('log_count', 'schedule_store_id');
        }

        $rows = $rows->map(function ($row) use ($scheduleLogCounts, $scheduleStoreLogCounts) {
            $scheduleId = (int) $row->schedule_id;
            $scheduleStoreId = $row->schedule_store_id ? (int) $row->schedule_store_id : null;
            $scheduleLogCount = (int) ($scheduleLogCounts[$scheduleId] ?? 0);
            $scheduleStoreLogCount = $scheduleStoreId
                ? (int) ($scheduleStoreLogCounts[$scheduleStoreId] ?? 0)
                : 0;

            $row->start_time = $row->schedule_store_start_time ?: $row->schedule_start_time;
            $row->end_time = $row->schedule_store_end_time ?: $row->schedule_end_time;
            $row->attendance_log_count = max($scheduleLogCount, $scheduleStoreLogCount);

            return $row;
        });

        return $rows
            ->groupBy(fn ($row) => $this->duplicateScheduleKey($row))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(function ($group, $key) {
                $sorted = $group->sort(function ($a, $b) {
                    return ($b->attendance_log_count <=> $a->attendance_log_count)
                        ?: ((int) $a->schedule_store_id <=> (int) $b->schedule_store_id);
                })->values();

                $keeper = $sorted->first();
                $duplicates = $sorted->slice(1)->values();

                return [
                    'key' => sha1($key),
                    'user_id' => (int) $keeper->user_id,
                    'user_name' => $keeper->user_name,
                    'sub_unit' => $keeper->sub_unit,
                    'store_id' => $keeper->store_id ? (int) $keeper->store_id : null,
                    'store_name' => $keeper->store_name,
                    'status' => $keeper->status,
                    'start_time' => $this->formatDuplicateDateTime($keeper->start_time),
                    'end_time' => $this->formatDuplicateDateTime($keeper->end_time),
                    'total_count' => $sorted->count(),
                    'duplicate_count' => $duplicates->count(),
                    'duplicate_schedule_store_ids' => $duplicates->pluck('schedule_store_id')->filter()->map(fn ($id) => (int) $id)->values(),
                    'duplicate_schedule_ids_to_delete' => $duplicates
                        ->filter(fn ($row) => empty($row->schedule_store_id))
                        ->pluck('schedule_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values(),
                    'duplicate_schedule_ids' => $duplicates->pluck('schedule_id')->map(fn ($id) => (int) $id)->unique()->values(),
                    'attendance_log_count' => $sorted->sum('attendance_log_count'),
                    'rows' => $sorted->map(fn ($row, $index) => [
                        'row_key' => $row->schedule_store_id ? 'store-' . $row->schedule_store_id : 'schedule-' . $row->schedule_id,
                        'schedule_id' => (int) $row->schedule_id,
                        'schedule_store_id' => $row->schedule_store_id ? (int) $row->schedule_store_id : null,
                        'store_name' => $row->store_name,
                        'ticket_key' => $row->ticket_key,
                        'attendance_log_count' => (int) $row->attendance_log_count,
                        'action' => $index === 0 ? 'keep' : 'delete',
                    ])->values(),
                ];
            })
            ->sortBy([
                ['user_name', 'asc'],
                ['start_time', 'asc'],
                ['store_name', 'asc'],
            ])
            ->values();
    }

    private function duplicateScheduleKey($row): string
    {
        return json_encode([
            (int) $row->user_id,
            $this->duplicateScalar($row->status),
            $this->duplicateDateTimeKey($row->start_time),
            $this->duplicateDateTimeKey($row->end_time),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function duplicateScalar($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function formatDuplicateDateTime($value): string
    {
        return Carbon::parse($value, 'Asia/Manila')->timezone('Asia/Manila')->toIso8601String();
    }

    private function duplicateDateTimeKey($value): string
    {
        return Carbon::parse($value, 'Asia/Manila')->timezone('Asia/Manila')->format('Y-m-d H:i:s');
    }

    private function hasScheduleOverlap(int $userId, array $newEntries, $excludeScheduleId = null, $newStatus = null): bool
    {
        if (empty($newEntries)) {
            return false;
        }

        $wholeDayStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];
        $isNewWholeDay = in_array($newStatus, $wholeDayStatuses);

        $candidateEntries = collect($newEntries)->map(fn ($entry) => [
            'start_time' => Carbon::parse($entry['start_time']),
            'end_time'   => Carbon::parse($entry['end_time']),
            'start_date' => Carbon::parse($entry['start_time'])->startOfDay(),
            'end_date'   => Carbon::parse($entry['end_time'])->startOfDay(),
        ]);

        $rangeStart = $candidateEntries->sortBy(fn ($entry) => $entry['start_time']->getTimestamp())->first()['start_time'];
        $rangeEnd = $candidateEntries->sortByDesc(fn ($entry) => $entry['end_time']->getTimestamp())->first()['end_time'];

        $existingSegments = ScheduleStore::query()
            ->with('schedule')
            ->where('start_time', '<=', $rangeEnd->copy()->endOfDay())
            ->where('end_time', '>=', $rangeStart->copy()->startOfDay())
            ->whereHas('schedule', function ($query) use ($userId, $excludeScheduleId) {
                $query->where('user_id', $userId);

                if ($excludeScheduleId) {
                    $query->where('id', '!=', $excludeScheduleId);
                }
            })
            ->get();

        foreach ($existingSegments as $segment) {
            $isExistingWholeDay = in_array($segment->schedule->status, $wholeDayStatuses);
            foreach ($candidateEntries as $entry) {
                $segmentStart = Carbon::parse($segment->start_time);
                $segmentEnd = Carbon::parse($segment->end_time);

                $sharesDay = $entry['start_date']->lte($segmentEnd->copy()->startOfDay()) 
                          && $entry['end_date']->gte($segmentStart->copy()->startOfDay());

                if ($sharesDay && ($isNewWholeDay || $isExistingWholeDay)) {
                    return true;
                }

                if ($entry['start_time']->lte($segmentEnd) && $entry['end_time']->gte($segmentStart)) {
                    return true;
                }
            }
        }

        $legacySchedules = Schedule::query()
            ->where('user_id', $userId)
            ->when($excludeScheduleId, fn ($query) => $query->where('id', '!=', $excludeScheduleId))
            ->whereDoesntHave('scheduleStores')
            ->where('start_time', '<=', $rangeEnd->copy()->endOfDay())
            ->where('end_time', '>=', $rangeStart->copy()->startOfDay())
            ->get(['id', 'start_time', 'end_time', 'status']);

        foreach ($legacySchedules as $legacySchedule) {
            $isExistingWholeDay = in_array($legacySchedule->status, $wholeDayStatuses);
            foreach ($candidateEntries as $entry) {
                $legacyStart = Carbon::parse($legacySchedule->start_time);
                $legacyEnd = Carbon::parse($legacySchedule->end_time);

                $sharesDay = $entry['start_date']->lte($legacyEnd->copy()->startOfDay()) 
                          && $entry['end_date']->gte($legacyStart->copy()->startOfDay());

                if ($sharesDay && ($isNewWholeDay || $isExistingWholeDay)) {
                    return true;
                }

                if ($entry['start_time']->lte($legacyEnd) && $entry['end_time']->gte($legacyStart)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function scheduleHasAttendanceLogs(Schedule $schedule): bool
    {
        return AttendanceLog::where('schedule_id', $schedule->id)->notVoided()->exists();
    }

    private function hasInvalidSubmittedScheduleStoreIds(Schedule $schedule, array $entries): bool
    {
        $ids = collect($entries)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return false;
        }

        return ScheduleStore::where('schedule_id', $schedule->id)
            ->whereIn('id', $ids)
            ->count() !== $ids->count();
    }

    private function firstAttendanceLogOutsideEntries(Schedule $schedule, array $entries, ?string $scopeDate = null): ?AttendanceLog
    {
        $logs = AttendanceLog::where('schedule_id', $schedule->id)
            ->notVoided()
            ->orderBy('log_time')
            ->get(['id', 'type', 'log_time'])
            ->when($scopeDate, fn ($logs) => $logs->filter(
                fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString() === $scopeDate
            )->values());

        if ($logs->isEmpty()) {
            return null;
        }

        return $logs->first(fn ($log) => !$this->entriesContainLogTime($entries, $log->log_time));
    }

    private function scheduleEntryWindowsChanged(Schedule $schedule, array $entries, ?string $scopeDate = null): bool
    {
        $existingWindows = $this->existingScheduleEntryWindows($schedule, $scopeDate);
        $submittedWindows = collect($entries)
            ->map(fn (array $entry) => $this->normalizeScheduleEntryWindow(
                $entry['start_time'],
                $entry['end_time'],
                $entry['grace_period_minutes'] ?? 30
            ))
            ->sort()
            ->values();

        return $existingWindows->toJson() !== $submittedWindows->toJson();
    }

    private function existingScheduleEntryWindows(Schedule $schedule, ?string $scopeDate = null)
    {
        $rows = $schedule->scheduleStores()->exists()
            ? ($scopeDate ? $this->scopedScheduleStoreRows($schedule, $scopeDate) : $schedule->scheduleStores()->get())
            : collect([
                (object) [
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'grace_period_minutes' => 30,
                ],
            ]);

        return $rows
            ->map(fn ($row) => $this->normalizeScheduleEntryWindow(
                $row->start_time,
                $row->end_time,
                $row->grace_period_minutes ?? 30
            ))
            ->sort()
            ->values();
    }

    private function normalizeScheduleEntryWindow($startTime, $endTime, $graceMinutes): string
    {
        return implode('|', [
            Carbon::parse($startTime, 'Asia/Manila')->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            Carbon::parse($endTime, 'Asia/Manila')->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            (int) $graceMinutes,
        ]);
    }

    private function entriesContainLogTime(array $entries, ?Carbon $logTime): bool
    {
        if (!$logTime) {
            return true;
        }

        foreach ($entries as $entry) {
            $graceMinutes = (int) ($entry['grace_period_minutes'] ?? 30);
            $windowStart = Carbon::parse($entry['start_time'])->subMinutes($graceMinutes);
            $windowEnd = Carbon::parse($entry['end_time']);

            if ($logTime->betweenIncluded($windowStart, $windowEnd)) {
                return true;
            }
        }

        return false;
    }

    private function syncScopedScheduleStores(Schedule $schedule, array $entries, string $scopeDate): void
    {
        $existingRows = $this->scopedScheduleStoreRows($schedule, $scopeDate);

        $this->syncScheduleStoreRows($schedule, $existingRows, $entries);
    }

    private function splitScopedSchedule(array $payload, Schedule $schedule, array $entries, string $scopeDate, int $updaterId): void
    {
        $newSchedule = Schedule::create([
            'user_id'        => $payload['user_id'],
            'created_by'     => $schedule->created_by ?: auth()->id(),
            'updated_by'     => $updaterId,
            'status'         => $payload['status'],
            'start_time'     => collect($entries)->min('start_time'),
            'end_time'       => collect($entries)->max('end_time'),
            'pickup_start'   => $payload['pickup_start'] ?? null,
            'pickup_end'     => $payload['pickup_end'] ?? null,
            'backlogs_start' => $payload['backlogs_start'] ?? null,
            'backlogs_end'   => $payload['backlogs_end'] ?? null,
        ]);

        $existingRows = $this->scopedScheduleStoreRows($schedule, $scopeDate);

        $this->syncScheduleStoreRows($newSchedule, $existingRows, $entries);
        $this->refreshScheduleBounds($schedule);
    }

    private function scopedScheduleStoreRows(Schedule $schedule, string $scopeDate)
    {
        $scopeStart = Carbon::parse($scopeDate)->startOfDay();
        $scopeEnd = Carbon::parse($scopeDate)->endOfDay();

        return $schedule->scheduleStores()
            ->where('start_time', '<=', $scopeEnd)
            ->where('end_time', '>=', $scopeStart)
            ->get();
    }

    private function scheduleHasEntriesOutsideScope(Schedule $schedule, string $scopeDate): bool
    {
        $scopeStart = Carbon::parse($scopeDate)->startOfDay();
        $scopeEnd = Carbon::parse($scopeDate)->endOfDay();

        if ($schedule->scheduleStores()->exists()) {
            return $schedule->scheduleStores()
                ->where(function ($query) use ($scopeStart, $scopeEnd) {
                    $query->where('start_time', '>', $scopeEnd)
                        ->orWhere('end_time', '<', $scopeStart);
                })
                ->exists();
        }

        return !$schedule->start_time->copy()->startOfDay()->eq($scopeStart)
            || !$schedule->end_time->copy()->startOfDay()->eq($scopeStart);
    }

    private function syncScheduleStoreRows(Schedule $schedule, $existingRows, array $entries): void
    {
        $existingById = $existingRows->keyBy('id');
        $usedIds = collect();

        foreach (array_values($entries) as $index => $entry) {
            $payload = $this->scheduleStorePayload($entry);
            $entryId = isset($entry['id']) ? (int) $entry['id'] : null;
            $matchedRow = $entryId ? $existingById->get($entryId) : null;

            if ($entryId && !$matchedRow) {
                throw ValidationException::withMessages([
                    'stores' => 'One or more selected schedule entries do not belong to the selected schedule date.',
                ]);
            }

            if (!$matchedRow) {
                $matchedRow = $existingRows
                    ->values()
                    ->first(fn ($row, $rowIndex) => $rowIndex === $index && !$usedIds->contains((int) $row->id));
            }

            if ($matchedRow) {
                $matchedRow->update([
                    ...$payload,
                    'schedule_id' => $schedule->id,
                ]);
                $usedIds->push((int) $matchedRow->id);
                continue;
            }

            $createdRow = $schedule->scheduleStores()->create($payload);
            $usedIds->push((int) $createdRow->id);
        }

        $staleRows = $existingRows->reject(fn ($row) => $usedIds->contains((int) $row->id));

        if ($staleRows->isEmpty()) {
            return;
        }

        $staleIds = $staleRows->pluck('id')->map(fn ($id) => (int) $id)->values();
        $hasLogs = AttendanceLog::whereIn('schedule_store_id', $staleIds)->notVoided()->exists();

        if ($hasLogs) {
            throw ValidationException::withMessages([
                'stores' => 'This schedule already has attendance logs. Logged schedule entries cannot be removed.',
            ]);
        }

        ScheduleStore::whereIn('id', $staleIds)->delete();
    }

    private function refreshScheduleBounds(Schedule $schedule): void
    {
        $scheduleStores = $schedule->scheduleStores()->get(['start_time', 'end_time']);

        if ($scheduleStores->isEmpty()) {
            return;
        }

        $schedule->update([
            'start_time' => $scheduleStores->min('start_time'),
            'end_time' => $scheduleStores->max('end_time'),
        ]);
    }

    private function scheduleStorePayload(array $entry): array
    {
        return [
            'store_id'             => $entry['store_id'] ?? null,
            'ticket_id'            => $entry['ticket_id'] ?? null,
            'start_time'           => $entry['start_time'],
            'end_time'             => $entry['end_time'],
            'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
            'remarks'              => $entry['remarks'] ?? null,
        ];
    }

    /**
     * Expand each store entry into one record per calendar day.
     *
     * A single entry with start=2026-04-15 08:00 / end=2026-04-20 17:00
     * becomes six rows, each covering one day at the same start/end times.
     */
    private function expandStoreEntries(array $storeEntries): array
    {
        $expanded = [];

        foreach ($storeEntries as $entry) {
            $start     = Carbon::parse($entry['start_time']);
            $end       = Carbon::parse($entry['end_time']);
            $startDate = $start->copy()->startOfDay();
            $endDate   = $end->copy()->startOfDay();

            // Single-day and overnight shifts are one schedule segment.
            if ($startDate->eq($endDate) || ($start->lt($end) && $start->diffInSeconds($end) <= 86400)) {
                $expanded[] = [
                    'id'                   => $entry['id'] ?? null,
                    'store_id'             => $entry['store_id'] ?? null,
                    'ticket_id'            => $entry['ticket_id'] ?? null,
                    'start_time'           => $start,
                    'end_time'             => $end,
                    'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                    'remarks'              => $entry['remarks'] ?? null,
                ];
                continue;
            }

            // Multi-day — one row per day using the same time-of-day
            $startTimeStr = $start->format('H:i:s');
            $endTimeStr   = $end->format('H:i:s');
            $current      = $startDate->copy();

            while ($current->lte($endDate)) {
                $expanded[] = [
                    'store_id'             => $entry['store_id'] ?? null,
                    'ticket_id'            => $entry['ticket_id'] ?? null,
                    'start_time'           => $current->copy()->setTimeFromTimeString($startTimeStr),
                    'end_time'             => $current->copy()->setTimeFromTimeString($endTimeStr),
                    'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                    'remarks'              => $entry['remarks'] ?? null,
                ];
                $current->addDay();
            }
        }

        return $expanded;
    }

    private function applyDeptFilter($query, Request $request, bool $onUser = false): void
    {
        if ($request->filled('department_node_id')) {
            $nodeId = (int) $request->department_node_id;
            $ids = array_merge([$nodeId], \App\Models\DepartmentNode::getAllDescendantIds($nodeId));
            $onUser
                ? $query->whereIn('department_node_id', $ids)
                : $query->whereHas('user', fn($q) => $q->whereIn('department_node_id', $ids));
        } elseif ($request->filled('department_id')) {
            $onUser
                ? $query->where('department_id', $request->department_id)
                : $query->whereHas('user', fn($q) => $q->where('department_id', $request->department_id));
        }
    }

    public function reportData(Request $request)
    {
        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A'];

        $selectedYearsInput = $request->input('report_years');
        $selectedYears = $selectedYearsInput
            ? collect((array)$selectedYearsInput)->map(fn($y) => (int)$y)->unique()->sort()->values()->toArray()
            : [2024, 2025, 2026];

        $pivotUsersQuery = User::active()
            ->where('is_vacant', false)
            ->whereNotNull('org_path')
            ->orderBy('org_path')
            ->orderBy('name');
        $this->applyDeptFilter($pivotUsersQuery, $request, onUser: true);
        if ($request->filled('sub_unit')) {
            $pivotUsersQuery->where('org_path', 'like', '%'.$request->sub_unit.'%');
        }
        $pivotUsers   = $pivotUsersQuery->get(['id', 'name', 'org_path']);
        $pivotUserIds = $pivotUsers->pluck('id')->toArray();

        if (empty($pivotUserIds)) {
            return response()->json([]);
        }

        // Single query: count days per user / year / status using DATEDIFF.
        // SQL Server syntax: DATEDIFF(day, start, end) + 1
        //   single-day schedule (07:00–17:00 same day) → DATEDIFF = 0 → 1 day
        //   two-day schedule                           → DATEDIFF = 1 → 2 days
        // CAST(... AS DATE) strips the time component (SQL Server equivalent of MySQL DATE()).
        $rawQuery = DB::table('schedules')
            ->select([
                'user_id',
                DB::raw('YEAR(start_time) as year'),
                'status',
                DB::raw('SUM(DATEDIFF(day, CAST(start_time AS DATE), CAST(end_time AS DATE)) + 1) as day_count'),
            ])
            ->whereIn(DB::raw('YEAR(start_time)'), $selectedYears)
            ->whereIn('user_id', $pivotUserIds)
            ->whereIn('status', $pivotStatuses);

        if ($request->filled('store_id')) {
            $storeId = $request->store_id;
            $rawQuery->whereExists(function ($sub) use ($storeId) {
                $sub->from('schedule_stores')
                    ->whereColumn('schedule_stores.schedule_id', 'schedules.id')
                    ->where('schedule_stores.store_id', $storeId);
            });
        }

        if ($request->filled('status')) {
            $rawQuery->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $priority = $request->priority;
            $rawQuery->whereExists(function ($sub) use ($priority) {
                $sub->from('schedule_stores')
                    ->join('tickets', 'schedule_stores.ticket_id', '=', 'tickets.id')
                    ->leftJoin('items', 'tickets.item_id', '=', 'items.id')
                    ->whereColumn('schedule_stores.schedule_id', 'schedules.id')
                    ->where(function($q) use ($priority) {
                        $q->where('tickets.priority', $priority)
                          ->orWhere('items.priority', $priority);
                    });
            });
        }

        $grouped = $rawQuery
            ->groupBy('user_id', DB::raw('YEAR(start_time)'), 'status')
            ->get()
            ->groupBy('user_id');

        $pivotData = [];
        foreach ($pivotUsers as $u) {
            $byYear = $grouped->get($u->id, collect())->groupBy('year');
            $rowData = ['unit' => $u->org_path, 'name' => $u->name, 'years' => []];

            foreach ($selectedYears as $y) {
                $yearRows   = $byYear->get((string)$y, collect());
                $yearCounts = [];
                foreach ($pivotStatuses as $s) {
                    $yearCounts[$s] = (int)($yearRows->firstWhere('status', $s)?->day_count ?? 0);
                }
                $rowData['years'][$y] = $yearCounts;
            }
            $pivotData[] = $rowData;
        }

        return response()->json($pivotData);
    }

    public function template(Request $request)
    {
        if ($request->filled('start') || $request->filled('end')) {
            $validated = $request->validate([
                'start' => ['required', 'date'],
                'end' => ['required', 'date', 'after_or_equal:start'],
            ]);

            $startDate = Carbon::parse($validated['start'], 'Asia/Manila')->startOfDay();
            $endDate = Carbon::parse($validated['end'], 'Asia/Manila')->startOfDay();
        } else {
            $year = (int) $request->input('year', now()->year);
            $year = max(2020, min(2100, $year));

            $startDate = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Manila')->startOfDay();
            $endDate = Carbon::create($year, 12, 31, 0, 0, 0, 'Asia/Manila')->startOfDay();
        }

        $rangeDays = (int) $startDate->diffInDays($endDate) + 1;
        if ($rangeDays > 366) {
            throw ValidationException::withMessages([
                'end' => 'Date range cannot exceed 366 days.',
            ]);
        }

        // Build date list for the requested template range.
        $dates = [];
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $users    = User::active()->orderBy('name')->get(['id', 'name']);
        $stores   = Store::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);
        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A'];

        $spreadsheet = new Spreadsheet();


        // -- Hidden Lists sheet ------------------------------------------
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        
        // Status List
        $listsSheet->setCellValue('A1', 'Status');
        foreach ($statuses as $i => $s) {
            $listsSheet->setCellValue('A' . ($i + 2), $s);
        }
        $listsSheet->setCellValue('A' . (count($statuses) + 2), 'NA');

        // Location List
        $listsSheet->setCellValue('B1', 'Locations');
        $firstLocationValue = '';
        foreach ($stores as $i => $s) {
            $val = $s->code . ' - ' . $s->name;
            if ($i === 0) $firstLocationValue = $val;
            $listsSheet->setCellValue('B' . ($i + 2), $val);
        }

        // -- Import Template sheet ---------------------------------------
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        // Layout: A=user_id, B=user_name, then per date: [YYYY-MM-DD | YYYY-MM-DD_location | YYYY-MM-DD_remarks] triples
        $sheet->setCellValue('A1', 'user_id');
        $sheet->setCellValue('B1', 'user_name');

        foreach ($dates as $i => $date) {
            // Each date occupies 3 columns: status, location, then remarks
            $statusColIdx   = ($i * 3) + 3;                // col C, F, I, ...
            $locationColIdx = ($i * 3) + 4;                // col D, G, J, ...
            $remarksColIdx  = ($i * 3) + 5;                // col E, H, K, ...
            
            $statusCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIdx);
            $locationCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($locationColIdx);
            $remarksCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($remarksColIdx);
            
            $sheet->setCellValue("{$statusCol}1",   $date);
            $sheet->setCellValue("{$locationCol}1", "{$date}_location");
            $sheet->setCellValue("{$remarksCol}1",  "{$date}_remarks");
        }

        // Add Sample Row at index 2 (row 2)
        $sheet->setCellValue('A2', '0');
        $sheet->setCellValue('B2', 'SAMPLE ROW (DELETE OR OVERWRITE)');
        foreach ($dates as $i => $date) {
            if ($i >= count($statuses)) break;
            
            $status = $statuses[$i];
            $statusColIdx   = ($i * 3) + 3;
            $locationColIdx = ($i * 3) + 4;
            $remarksColIdx  = ($i * 3) + 5;
            
            $sCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIdx);
            $lCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($locationColIdx);
            $rCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($remarksColIdx);
            
            $sheet->setCellValue("{$sCol}2", $status);
            $sheet->setCellValue("{$rCol}2", "Sample {$status} entry");
            
            if (in_array($status, ['On-site', 'Off-site']) && $firstLocationValue) {
                $sheet->setCellValue("{$lCol}2", $firstLocationValue);
            }
        }
        // Grey out the sample row
        $sheet->getStyle('A2:B2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');
        $totalSampleCols = 2 + (min(count($dates), count($statuses)) * 3);
        $lastSampleColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalSampleCols);
        $sheet->getStyle("C2:{$lastSampleColLetter}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4D6');

        // Fill user rows
        $lastUserRow = count($users) + 2;
        foreach ($users as $rowIdx => $user) {
            $row = $rowIdx + 3;
            $sheet->setCellValue("A{$row}", $user->id);
            $sheet->setCellValue("B{$row}", $user->name);
        }

        // Style user_name column (col B) — grey, reference-only
        $sheet->getStyle("B2:B{$lastUserRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');
        $sheet->getStyle("B2:B{$lastUserRow}")->getFont()
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF9CA3AF'));

        // Header styling across all columns
        $totalCols     = 2 + (count($dates) * 3);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Dropdowns
        $statusFormula   = 'Lists!$A$2:$A$' . (count($statuses) + 2);
        $locationFormula = 'Lists!$B$2:$B$' . max(count($stores) + 1, 2);
        $dropdownLastRow = max($lastUserRow, 2);

        foreach ($dates as $i => $date) {
            // Status Dropdown
            $statusColIdx = ($i * 3) + 3;
            $sCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIdx);
            $sSqref = "{$sCol}2:{$sCol}{$dropdownLastRow}";
            $sv = $sheet->getCell("{$sCol}2")->getDataValidation();
            $sv->setType(DataValidation::TYPE_LIST)
              ->setErrorStyle(DataValidation::STYLE_INFORMATION)
              ->setAllowBlank(true)
              ->setShowDropDown(true)
              ->setFormula1($statusFormula)
              ->setSqref($sSqref);

            // Location Dropdown
            $locationColIdx = ($i * 3) + 4;
            $lCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($locationColIdx);
            $lSqref = "{$lCol}2:{$lCol}{$dropdownLastRow}";
            $lv = $sheet->getCell("{$lCol}2")->getDataValidation();
            $lv->setType(DataValidation::TYPE_LIST)
              ->setErrorStyle(DataValidation::STYLE_INFORMATION)
              ->setAllowBlank(true)
              ->setShowDropDown(true)
              ->setFormula1($locationFormula)
              ->setSqref($lSqref);
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        foreach ($dates as $i => $date) {
            $sCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(($i * 3) + 3);
            $lCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(($i * 3) + 4);
            $rCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(($i * 3) + 5);
            $sheet->getColumnDimension($sCol)->setWidth(12);
            $sheet->getColumnDimension($lCol)->setWidth(25);
            $sheet->getColumnDimension($rCol)->setWidth(18);
        }

        // Freeze panes at C2 — user_id + user_name stay pinned while scrolling
        $sheet->freezePane('C2');

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $rangeLabel = $startDate->toDateString() === $endDate->toDateString()
            ? $startDate->toDateString()
            : $startDate->toDateString() . '_to_' . $endDate->toDateString();
        $filename = "schedules-import-{$rangeLabel}.xlsx";
        $httpHeaders = [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ];

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $httpHeaders);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv|max:5120']);

        $filePath = $request->file('file')->getRealPath();
        $reader = IOFactory::createReaderForFile($filePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (empty($rows)) {
            return response()->json(['imported' => 0, 'errors' => ['File is empty.']]);
        }

        // Row 0 = header: user_id | user_name | YYYY-MM-DD | YYYY-MM-DD | ...
        $header = array_map(fn($v) => trim((string) $v), array_shift($rows));

        // Build lookup: user_id (int) → exists
        $validUserIds = User::pluck('id')->flip()->toArray(); // [id => 0]
        
        // Build location lookup (Code or "Code - Name")
        $storeLookup = [];
        foreach (Store::where('is_active', true)->get(['id', 'code', 'name']) as $s) {
            $storeLookup[strtoupper($s->code)] = $s->id;
            $storeLookup[strtoupper($s->code . ' - ' . $s->name)] = $s->id;
        }

        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A'];

        // Build date-column map from header:
        //   dateStr => [ 'statusIdx' => int, 'storeIdx' => int|null, 'remarksIdx' => int|null ]
        // Header format: col 0 = user_id, col 1 = user_name,
        //   then triples: YYYY-MM-DD  |  YYYY-MM-DD_location | YYYY-MM-DD_remarks  |  ...
        $dateCols = [];
        foreach ($header as $idx => $h) {
            if ($idx < 2) continue;
            if (preg_match('/^(\d{4}-\d{2}-\d{2})_remarks$/', $h, $m)) {
                // Remarks column — attach to the already-registered date entry
                if (isset($dateCols[$m[1]])) {
                    $dateCols[$m[1]]['remarksIdx'] = $idx;
                }
            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})_(?:location|store)$/', $h, $m)) {
                // Location column. The legacy _store suffix is still accepted.
                if (isset($dateCols[$m[1]])) {
                    $dateCols[$m[1]]['storeIdx'] = $idx;
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)) {
                // Status column for this date
                $dateCols[$h] = ['statusIdx' => $idx, 'storeIdx' => null, 'remarksIdx' => null];
            }
        }

        if (empty($dateCols)) {
            return response()->json(['imported' => 0, 'errors' => ['No valid date columns found in the header row.']]);
        }

        $imported = 0;
        $errors   = [];
        $rowNum   = 1;
        $candidates = [];
        $candidateUserIds = [];
        $candidateDates = [];
        $seenImportDates = [];

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // Resolve user_id from column 0
            $rawId  = isset($line[0]) ? trim((string) $line[0]) : '';
            $userId = (int) $rawId;

            if (!$userId || !isset($validUserIds[$userId])) {
                $errors[] = "Row {$rowNum}: user_id '{$rawId}' not found, row skipped.";
                continue;
            }

            // Process each date triple
            foreach ($dateCols as $dateStr => $cols) {
                $rawValue   = isset($line[$cols['statusIdx']]) ? trim((string) $line[$cols['statusIdx']]) : '';
                $rawStore   = ($cols['storeIdx'] !== null && isset($line[$cols['storeIdx']]))
                    ? trim((string) $line[$cols['storeIdx']])
                    : '';
                $rawRemarks = ($cols['remarksIdx'] !== null && isset($line[$cols['remarksIdx']]))
                    ? trim((string) $line[$cols['remarksIdx']])
                    : '';

                // Empty or NA → no schedule for this date
                if ($rawValue === '' || strtoupper($rawValue) === 'NA') {
                    continue;
                }

                if (!in_array($rawValue, $statuses, true)) {
                    $errors[] = "Row {$rowNum}, {$dateStr}: invalid status '{$rawValue}', skipped.";
                    continue;
                }

                // Resolve location if provided
                $resolvedStoreId = null;
                if ($rawStore !== '') {
                    $storeKey = strtoupper($rawStore);
                    if (isset($storeLookup[$storeKey])) {
                        $resolvedStoreId = $storeLookup[$storeKey];
                    } else {
                        $errors[] = "Row {$rowNum}, {$dateStr}: Location '{$rawStore}' not recognized. Proceeding without location link.";
                    }
                }

                $importKey = $userId . '|' . $dateStr;
                if (isset($seenImportDates[$importKey])) {
                    $errors[] = "Row {$rowNum}, {$dateStr}: duplicate import entry for user ID {$userId}, skipped.";
                    continue;
                }

                $seenImportDates[$importKey] = true;
                $candidates[] = [
                    'user_id' => $userId,
                    'date' => $dateStr,
                    'status' => $rawValue,
                    'store_id' => $resolvedStoreId,
                    'remarks' => $rawRemarks ?: null,
                    'row_num' => $rowNum,
                ];
                $candidateUserIds[$userId] = true;
                $candidateDates[$dateStr] = true;
            }
        }

        if (empty($candidates)) {
            return response()->json(['imported' => 0, 'errors' => $errors]);
        }

        $candidateDateKeys = array_keys($candidateDates);
        sort($candidateDateKeys);

        $rangeStart = Carbon::createFromFormat('Y-m-d', $candidateDateKeys[0])->startOfDay();
        $rangeEnd = Carbon::createFromFormat('Y-m-d', end($candidateDateKeys))->endOfDay();

        $existingDateMap = [];
        $existingSchedules = Schedule::query()
            ->select(['user_id', 'start_time', 'end_time'])
            ->whereIn('user_id', array_keys($candidateUserIds))
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart)
            ->get();

        foreach ($existingSchedules as $existingSchedule) {
            $day = $existingSchedule->start_time->copy()->startOfDay();
            $lastDay = $existingSchedule->end_time->copy()->startOfDay();

            while ($day->lte($lastDay)) {
                $existingDateMap[$existingSchedule->user_id . '|' . $day->toDateString()] = true;
                $day->addDay();
            }
        }

        DB::transaction(function () use ($candidates, &$errors, &$imported, &$existingDateMap) {
            $timestamp = now();
            $maxSqlServerParameters = 2000;
            $chunkRowsForInsert = function (array $rows) use ($maxSqlServerParameters): array {
                if (empty($rows)) {
                    return [];
                }

                $columnCount = max(1, count($rows[0]));
                $chunkSize = max(1, (int) floor($maxSqlServerParameters / $columnCount));

                return array_chunk($rows, $chunkSize);
            };

            // Phase 1 — filter out duplicates and build the rows to insert
            $toInsert       = [];  // rows for schedules table
            $onSiteDateKeys = [];  // user_id|date keys that need a schedule_store

            foreach ($candidates as $candidate) {
                $importKey = $candidate['user_id'] . '|' . $candidate['date'];
                if (isset($existingDateMap[$importKey])) {
                    $errors[] = "Row {$candidate['row_num']}, {$candidate['date']}: user ID {$candidate['user_id']} already has a schedule for this date, skipped.";
                    continue;
                }

                $startTime = Carbon::createFromFormat('Y-m-d', $candidate['date'])->setTime(7, 0, 0);
                $endTime   = Carbon::createFromFormat('Y-m-d', $candidate['date'])->setTime(17, 0, 0);

                $toInsert[] = [
                    'user_id'    => $candidate['user_id'],
                    'status'     => $candidate['status'],
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time'   => $endTime->toDateTimeString(),
                    'remarks'    => $candidate['remarks'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (in_array($candidate['status'], ['On-site', 'Off-site'], true)) {
                    $onSiteDateKeys[$candidate['user_id'] . '|' . $candidate['date']] = [
                        'start_time' => $startTime->toDateTimeString(),
                        'end_time'   => $endTime->toDateTimeString(),
                        'store_id'   => $candidate['store_id'],
                        'remarks'    => $candidate['remarks'],
                    ];
                }

                $existingDateMap[$importKey] = true;
            }

            if (empty($toInsert)) {
                return;
            }

            // Phase 2 — bulk insert schedules in chunks of 500
            foreach ($chunkRowsForInsert($toInsert) as $chunk) {
                DB::table('schedules')->insert($chunk);
            }
            $imported = count($toInsert);

            // Phase 3 — bulk insert schedule_stores for On-site / Off-site rows
            if (!empty($onSiteDateKeys)) {
                $insertedUserIds = array_unique(array_map(
                    fn($k) => (int) explode('|', $k)[0],
                    array_keys($onSiteDateKeys)
                ));

                $insertedDates = array_unique(array_map(
                    fn($k) => explode('|', $k)[1],
                    array_keys($onSiteDateKeys)
                ));
                sort($insertedDates);

                // Re-query just the IDs we need (safe inside the transaction)
                $insertedSchedules = DB::table('schedules')
                    ->select(['id', 'user_id', 'start_time'])
                    ->whereIn('user_id', $insertedUserIds)
                    ->where('start_time', '>=', $insertedDates[0] . ' 07:00:00')
                    ->where('start_time', '<=', end($insertedDates) . ' 07:00:00')
                    ->get()
                    ->keyBy(fn($s) => $s->user_id . '|' . substr($s->start_time, 0, 10));

                $storeRows = [];
                foreach ($onSiteDateKeys as $key => $times) {
                    $sched = $insertedSchedules->get($key);
                    if (!$sched) continue;

                    $storeRows[] = [
                        'schedule_id'          => $sched->id,
                        'store_id'             => $times['store_id'],
                        'start_time'           => $times['start_time'],
                        'end_time'             => $times['end_time'],
                        'grace_period_minutes' => 30,
                        'remarks'              => $times['remarks'],
                        'created_at'           => $timestamp,
                        'updated_at'           => $timestamp,
                    ];
                }

                foreach ($chunkRowsForInsert($storeRows) as $chunk) {
                    DB::table('schedule_stores')->insert($chunk);
                }
            }
        });

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    public function completeSchedules(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        $allDates = [];
        $tempDate = $rangeStart->copy();
        while ($tempDate <= $rangeEnd) {
            $allDates[] = $tempDate->toDateString();
            $tempDate->addDay();
        }

        $query = User::active()->where('is_vacant', false);
        $this->applyDeptFilter($query, $request, onUser: true);

        if ($request->filled('sub_unit')) {
            $query->where('org_path', 'like', '%'.$request->sub_unit.'%');
        }

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('id', auth()->id());
            } else {
                $query->where('id', $request->user_id);
            }
        }

        $users = $query->orderByRaw("CASE WHEN org_path IS NULL OR org_path = '' THEN 1 ELSE 0 END")
            ->orderBy('org_path')
            ->orderBy('name')
            ->get(['id', 'name', 'org_path', 'email']);
        $userIds = $users->pluck('id');

        $scheduleQuery = Schedule::with(['scheduleStores:id,schedule_id,store_id,start_time,end_time'])
            ->whereIn('user_id', $userIds)
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart);

        if ($request->filled('store_id')) {
            $scheduleQuery->whereHas('scheduleStores', fn ($sq) => $sq->where('store_id', $request->store_id));
        }

        $schedules = $scheduleQuery->get(['id', 'user_id', 'status', 'start_time', 'end_time']);

        $scheduleIds = $schedules->pluck('id')->filter()->values();
        $attendanceLogs = collect();

        foreach ($scheduleIds->chunk(1000) as $scheduleIdChunk) {
            $attendanceLogs = $attendanceLogs->concat(
                AttendanceLog::whereIn('schedule_id', $scheduleIdChunk->all())
                    ->notVoided()
                    ->orderBy('log_time')
                    ->get(['schedule_id', 'type', 'log_time'])
            );
        }

        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');
        $userScheduledDates = [];
        $userScheduledStatuses = [];
        $userCompleteLocationEntries = [];
        $userCompleteActualTimeInEntries = [];
        $userCompleteActualTimeOutEntries = [];
        $actualTimeOptionalStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];
        $locationOptionalStatuses = ['Restday', 'Holiday', 'N/A'];
        $storeId = (string) $request->input('store_id', '');

        foreach ($schedules as $schedule) {
            $scheduleLogsByDate = $logsBySchedule
                ->get($schedule->id, collect())
                ->groupBy(fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString());

            $coverageEntries = $request->filled('store_id')
                ? $schedule->scheduleStores->filter(fn ($scheduleStore) => (string) $scheduleStore->store_id === $storeId)
                : collect([$schedule]);

            foreach ($coverageEntries as $entry) {
                $entryStart = ($entry->start_time ?? $schedule->start_time)->copy()->timezone('Asia/Manila');
                $entryEnd = ($entry->end_time ?? $schedule->end_time)->copy()->timezone('Asia/Manila');

                $curr = $entryStart->copy();
                while ($curr->toDateString() <= $entryEnd->toDateString()) {
                    $dateStr = $curr->toDateString();
                    if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                        $userScheduledDates[$schedule->user_id][$dateStr] = true;
                        $userScheduledStatuses[$schedule->user_id][$dateStr][$schedule->status] = true;
                    }
                    $curr->addDay();
                }
            }

            $dailyLogsByDate = $scheduleLogsByDate;
            $curr = $schedule->start_time->copy()->timezone('Asia/Manila');
            $scheduleEnd = $schedule->end_time->copy()->timezone('Asia/Manila');

            while ($curr->toDateString() <= $scheduleEnd->toDateString()) {
                $dateStr = $curr->toDateString();

                if ($dateStr < $rangeStart->toDateString() || $dateStr > $rangeEnd->toDateString()) {
                    $curr->addDay();
                    continue;
                }

                if (!isset($userScheduledDates[$schedule->user_id][$dateStr])) {
                    $curr->addDay();
                    continue;
                }

                if (!in_array($schedule->status, $actualTimeOptionalStatuses, true)) {
                    $dailyLogs = $dailyLogsByDate->get($dateStr, collect());

                    if ($dailyLogs->contains(fn ($log) => $log->type === 'time_in')) {
                        $userCompleteActualTimeInEntries[$schedule->user_id][$dateStr][$schedule->status] = true;
                    }

                    if ($dailyLogs->contains(fn ($log) => $log->type === 'time_out')) {
                        $userCompleteActualTimeOutEntries[$schedule->user_id][$dateStr][$schedule->status] = true;
                    }
                }

                $curr->addDay();
            }

            if (in_array($schedule->status, $locationOptionalStatuses, true)) {
                continue;
            }

            if ($schedule->scheduleStores->isEmpty()) {
                continue;
            }

            foreach ($schedule->scheduleStores as $scheduleStore) {
                if ($request->filled('store_id') && (string) $scheduleStore->store_id !== $storeId) {
                    continue;
                }

                if (! $scheduleStore->store_id && ! in_array($schedule->status, ['SL', 'VL'], true)) {
                    continue;
                }

                if (! $scheduleStore->store_id && in_array($schedule->status, ['SL', 'VL'], true)) {
                    continue;
                }

                $segmentStart = ($scheduleStore->start_time ?? $schedule->start_time)->copy()->timezone('Asia/Manila');
                $segmentEnd = ($scheduleStore->end_time ?? $schedule->end_time)->copy()->timezone('Asia/Manila');

                $curr = $segmentStart->copy();
                while ($curr->toDateString() <= $segmentEnd->toDateString()) {
                    $dateStr = $curr->toDateString();
                    if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                        $userCompleteLocationEntries[$schedule->user_id][$dateStr][$schedule->status] = true;
                    }
                    $curr->addDay();
                }
            }
        }

        $results = $users->map(function ($user) use (
            $allDates,
            $userScheduledDates,
            $userScheduledStatuses,
            $userCompleteLocationEntries,
            $userCompleteActualTimeInEntries,
            $userCompleteActualTimeOutEntries
        ) {
            $completeDays = [];
            $completeLocations = [];
            $completeActualTimeIns = [];
            $completeActualTimeOuts = [];
            $optionalLocationStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];
            $optionalActualTimeStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];

            foreach ($allDates as $date) {
                if (!isset($userScheduledDates[$user->id][$date])) {
                    return null;
                }

                $completeDays[] = Carbon::parse($date)->format('M j');

                $scheduledStatuses = array_keys($userScheduledStatuses[$user->id][$date] ?? []);
                sort($scheduledStatuses);

                foreach ($scheduledStatuses as $status) {
                    $chip = sprintf('%s (%s)', Carbon::parse($date)->format('M j'), $status);

                    if (!in_array($status, $optionalLocationStatuses, true)) {
                        if (isset($userCompleteLocationEntries[$user->id][$date][$status])) {
                            $completeLocations[] = $chip;
                        }
                    }

                    if (!in_array($status, $optionalActualTimeStatuses, true)) {
                        if (isset($userCompleteActualTimeInEntries[$user->id][$date][$status])) {
                            $completeActualTimeIns[] = $chip;
                        }
                        if (isset($userCompleteActualTimeOutEntries[$user->id][$date][$status])) {
                            $completeActualTimeOuts[] = $chip;
                        }
                    }
                }
            }

            $user->complete_days = $completeDays;
            $user->complete_days_count = count($completeDays);
            $user->complete_locations = $completeLocations;
            $user->complete_location_count = count($completeLocations);
            $user->complete_actual_time_ins = $completeActualTimeIns;
            $user->complete_actual_time_in_count = count($completeActualTimeIns);
            $user->complete_actual_time_outs = $completeActualTimeOuts;
            $user->complete_actual_time_out_count = count($completeActualTimeOuts);
            $user->complete_total_count = count($completeDays)
                + count($completeLocations)
                + count($completeActualTimeIns)
                + count($completeActualTimeOuts);

            return $user;
        })->filter()->values();

        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $total = $results->count();

        $paginatedResults = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginatedResults);
    }

    public function missingSchedules(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        // Generate all dates in range
        $allDates = [];
        $tempDate = $rangeStart->copy();
        while ($tempDate <= $rangeEnd) {
            $allDates[] = $tempDate->toDateString();
            $tempDate->addDay();
        }

        $query = User::active()->where('is_vacant', false);
        $this->applyDeptFilter($query, $request, onUser: true);

        if ($request->filled('sub_unit')) {
            $query->where('org_path', 'like', '%'.$request->sub_unit.'%');
        }

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('id', auth()->id());
            } else {
                $query->where('id', $request->user_id);
            }
        }

        $users = $query->orderByRaw("CASE WHEN org_path IS NULL OR org_path = '' THEN 1 ELSE 0 END")
            ->orderBy('org_path')
            ->orderBy('name')
            ->get(['id', 'name', 'org_path', 'email']);
        $userIds = $users->pluck('id');

        // Fetch all schedules for these users in range
        $schedules = Schedule::with(['scheduleStores:id,schedule_id,store_id,start_time,end_time'])
            ->whereIn('user_id', $userIds)
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart)
            ->get(['id', 'user_id', 'status', 'start_time', 'end_time']);

        $scheduleIds = $schedules->pluck('id')->filter()->values();
        $attendanceLogs = collect();

        foreach ($scheduleIds->chunk(1000) as $scheduleIdChunk) {
            $attendanceLogs = $attendanceLogs->concat(
                AttendanceLog::whereIn('schedule_id', $scheduleIdChunk->all())
                    ->notVoided()
                    ->orderBy('log_time')
                    ->get(['schedule_id', 'type', 'log_time'])
            );
        }

        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');
        $userScheduledDates = [];
        $userMissingLocationEntries = [];
        $userMissingActualTimeInEntries = [];
        $userMissingActualTimeOutEntries = [];
        $locationOptionalStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];
        $actualTimeOptionalStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];
        foreach ($schedules as $s) {
            $sStart = $s->start_time->copy()->timezone('Asia/Manila');
            $sEnd = $s->end_time->copy()->timezone('Asia/Manila');
            $scheduleLogsByDate = $logsBySchedule
                ->get($s->id, collect())
                ->groupBy(fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString());
            
            $curr = $sStart->copy();
            while ($curr->toDateString() <= $sEnd->toDateString()) {
                $dateStr = $curr->toDateString();
                if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                    $userScheduledDates[$s->user_id][$dateStr] = true;

                    if (!in_array($s->status, $actualTimeOptionalStatuses, true)) {
                        $dailyLogs = $scheduleLogsByDate->get($dateStr, collect());

                        if (!$dailyLogs->contains(fn ($log) => $log->type === 'time_in')) {
                            $userMissingActualTimeInEntries[$s->user_id][$dateStr][$s->status] = true;
                        }

                        if (!$dailyLogs->contains(fn ($log) => $log->type === 'time_out')) {
                            $userMissingActualTimeOutEntries[$s->user_id][$dateStr][$s->status] = true;
                        }
                    }
                }
                $curr->addDay();
            }

            if (in_array($s->status, $locationOptionalStatuses, true)) {
                continue;
            }

            if ($s->scheduleStores->isEmpty()) {
                $curr = $sStart->copy();
                while ($curr->toDateString() <= $sEnd->toDateString()) {
                    $dateStr = $curr->toDateString();
                    if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                        $userMissingLocationEntries[$s->user_id][$dateStr][$s->status] = true;
                    }
                    $curr->addDay();
                }

                continue;
            }

            foreach ($s->scheduleStores as $scheduleStore) {
                if ($scheduleStore->store_id) {
                    continue;
                }

                $segmentStart = ($scheduleStore->start_time ?? $s->start_time)->copy()->timezone('Asia/Manila');
                $segmentEnd = ($scheduleStore->end_time ?? $s->end_time)->copy()->timezone('Asia/Manila');

                $curr = $segmentStart->copy();
                while ($curr->toDateString() <= $segmentEnd->toDateString()) {
                    $dateStr = $curr->toDateString();
                    if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                        $userMissingLocationEntries[$s->user_id][$dateStr][$s->status] = true;
                    }
                    $curr->addDay();
                }
            }
        }

        $results = $users->map(function ($user) use ($allDates, $userScheduledDates, $userMissingLocationEntries, $userMissingActualTimeInEntries, $userMissingActualTimeOutEntries) {
            $missing = [];
            $missingLocations = [];
            $missingActualTimeIns = [];
            $missingActualTimeOuts = [];
            foreach ($allDates as $date) {
                if (!isset($userScheduledDates[$user->id][$date])) {
                    $missing[] = Carbon::parse($date)->format('M j');
                }

                if (isset($userMissingLocationEntries[$user->id][$date])) {
                    $statuses = array_keys($userMissingLocationEntries[$user->id][$date]);
                    sort($statuses);

                    foreach ($statuses as $status) {
                        $missingLocations[] = sprintf(
                            '%s (%s)',
                            Carbon::parse($date)->format('M j'),
                            $status
                        );
                    }
                }

                if (isset($userMissingActualTimeInEntries[$user->id][$date])) {
                    $statuses = array_keys($userMissingActualTimeInEntries[$user->id][$date]);
                    sort($statuses);

                    foreach ($statuses as $status) {
                        $missingActualTimeIns[] = sprintf(
                            '%s (%s)',
                            Carbon::parse($date)->format('M j'),
                            $status
                        );
                    }
                }

                if (isset($userMissingActualTimeOutEntries[$user->id][$date])) {
                    $statuses = array_keys($userMissingActualTimeOutEntries[$user->id][$date]);
                    sort($statuses);

                    foreach ($statuses as $status) {
                        $missingActualTimeOuts[] = sprintf(
                            '%s (%s)',
                            Carbon::parse($date)->format('M j'),
                            $status
                        );
                    }
                }
            }
            
            if (empty($missing) && empty($missingLocations) && empty($missingActualTimeIns) && empty($missingActualTimeOuts)) return null;

            $user->missing_days = $missing;
            $user->missing_days_count = count($missing);
            $user->missing_locations = $missingLocations;
            $user->missing_location_count = count($missingLocations);
            $user->missing_actual_time_ins = $missingActualTimeIns;
            $user->missing_actual_time_in_count = count($missingActualTimeIns);
            $user->missing_actual_time_outs = $missingActualTimeOuts;
            $user->missing_actual_time_out_count = count($missingActualTimeOuts);
            $user->missing_total_count = count($missing)
                + count($missingLocations)
                + count($missingActualTimeIns)
                + count($missingActualTimeOuts);
            return $user;
        })->filter()->values();

        // Manual Pagination
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $total = $results->count();

        $paginatedResults = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginatedResults);
    }

    private function getTransitiveSubordinateIds(int $userId): array
    {
        $pairs = \DB::table('manager_user')->get(['manager_id', 'user_id']);

        $subMap = [];
        foreach ($pairs as $pair) {
            $subMap[$pair->manager_id][] = $pair->user_id;
        }

        $visited = [];
        $queue = [$userId];
        while (!empty($queue)) {
            $current = array_shift($queue);
            foreach ($subMap[$current] ?? [] as $subId) {
                if (!in_array($subId, $visited)) {
                    $visited[] = $subId;
                    $queue[] = $subId;
                }
            }
        }
        return $visited;
    }

    private function resolveScheduleChangeApproverIds(User $requester): array
    {
        $approverIds = collect();

        $directManagerIds = $requester->managers()
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->where('is_manager', true)
            ->pluck('users.id');

        $approverIds = $approverIds->merge($this->filterScheduleApproverIds($directManagerIds));

        if ($approverIds->isEmpty() && $requester->department_node_id) {
            $node = DepartmentNode::find($requester->department_node_id);

            while ($node && $approverIds->isEmpty()) {
                $node = $node->parent_id ? DepartmentNode::find($node->parent_id) : null;

                if (!$node) {
                    break;
                }

                $leaderIds = User::active()
                    ->where('is_vacant', false)
                    ->where('is_manager', true)
                    ->where('department_node_id', $node->id)
                    ->where('id', '!=', $requester->id)
                    ->pluck('id');

                $approverIds = $approverIds->merge($this->filterScheduleApproverIds($leaderIds));
            }
        }

        if ($approverIds->isEmpty()) {
            $adminIds = User::role(['Admin', 'Solutions Admin'])
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->where('id', '!=', $requester->id)
                ->pluck('id');

            $approverIds = $approverIds->merge($this->filterScheduleApproverIds($adminIds));
        }

        return $approverIds
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function filterScheduleApproverIds($ids)
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $ids->all())
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->get()
            ->filter(fn (User $user) => $user->can('schedules.approve'))
            ->pluck('id')
            ->values();
    }

    private function authorizeScheduleChangeApprover(ScheduleChangeRequest $scheduleChangeRequest): void
    {
        $assignedApproverIds = collect($scheduleChangeRequest->assigned_approver_ids ?? [])
            ->map(fn ($id) => (int) $id);

        abort_unless($assignedApproverIds->contains((int) auth()->id()), 403, 'You are not assigned to approve this schedule change request.');
    }

    private function notifyScheduleChangeApprovers(ScheduleChangeRequest $changeRequest): void
    {
        $approvers = User::whereIn('id', $changeRequest->assigned_approver_ids ?? [])
            ->whereNotNull('email')
            ->get();

        if ($approvers->isEmpty()) {
            Log::warning('Schedule change request has no notifiable approvers.', [
                'change_request_id' => $changeRequest->id,
                'assigned_approver_ids' => $changeRequest->assigned_approver_ids,
            ]);
            return;
        }

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver->email)->send(new ScheduleChangeRequestNotification($changeRequest, 'submitted', true));
            } catch (\Throwable $e) {
                // A mail/SMTP failure must not roll back or break the request submission.
                Log::error('Failed to email schedule change approver.', [
                    'change_request_id' => $changeRequest->id,
                    'approver_id' => $approver->id,
                    'approver_email' => $approver->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyScheduleChangeRequester(ScheduleChangeRequest $changeRequest, string $action): void
    {
        if (!$changeRequest->requester?->email) {
            return;
        }

        try {
            Mail::to($changeRequest->requester->email)->send(new ScheduleChangeRequestNotification($changeRequest, $action));
        } catch (\Throwable $e) {
            Log::error('Failed to email schedule change requester.', [
                'change_request_id' => $changeRequest->id,
                'requester_id' => $changeRequest->requester_id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function scheduleChangeRequestRows(User $user)
    {
        $query = ScheduleChangeRequest::with([
            'schedule.user:id,name',
            'requester:id,name,email',
            'approver:id,name',
            'rejecter:id,name',
        ])->latest();

        if ($user->can('schedules.approve')) {
            $query->where(function ($subQuery) use ($user) {
                $subQuery->where('requester_id', $user->id)
                    ->orWhere(function ($approvalQuery) use ($user) {
                        $approvalQuery->where('status', 'pending')
                            ->where(function ($jsonQuery) use ($user) {
                                $jsonQuery->whereJsonContains('assigned_approver_ids', (int) $user->id)
                                    ->orWhereJsonContains('assigned_approver_ids', (string) $user->id);
                            });
                        });
            });
        } else {
            $query->where('requester_id', $user->id);
        }

        $changeRequests = $query->limit(100)->get();
        $payloadStores = $changeRequests
            ->flatMap(fn (ScheduleChangeRequest $changeRequest) => array_merge(
                $changeRequest->original_payload['stores'] ?? [],
                $changeRequest->requested_payload['stores'] ?? [],
            ));

        $storeLabels = Store::query()
            ->whereIn('id', $payloadStores->pluck('store_id')->filter()->unique()->values())
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Store $store) => [
                $store->id => trim(collect([$store->code, $store->name])->filter()->implode(' - ')),
            ]);

        $ticketLabels = Ticket::query()
            ->whereIn('id', $payloadStores->pluck('ticket_id')->filter()->unique()->values())
            ->pluck('ticket_key', 'id');

        return $changeRequests->map(function (ScheduleChangeRequest $changeRequest) use ($storeLabels, $ticketLabels) {
            return [
                'id' => $changeRequest->id,
                'schedule_id' => $changeRequest->schedule_id,
                'requester_id' => $changeRequest->requester_id,
                'request_type' => $changeRequest->request_type ?: self::REQUEST_TYPE_SCHEDULE_CHANGE,
                'requester_name' => $changeRequest->requester?->name,
                'schedule_user_name' => $changeRequest->schedule?->user?->name,
                'assigned_approver_ids' => $changeRequest->assigned_approver_ids ?? [],
                'status' => $changeRequest->status,
                'original_payload' => $this->scheduleRequestPayloadWithLabels($changeRequest->original_payload, $storeLabels, $ticketLabels),
                'requested_payload' => $this->scheduleRequestPayloadWithLabels($changeRequest->requested_payload, $storeLabels, $ticketLabels),
                'requester_remarks' => $changeRequest->requester_remarks,
                'approver_remarks' => $changeRequest->approver_remarks,
                'approved_by_name' => $changeRequest->approver?->name,
                'approved_at' => $changeRequest->approved_at?->toIso8601String(),
                'rejected_by_name' => $changeRequest->rejecter?->name,
                'rejected_at' => $changeRequest->rejected_at?->toIso8601String(),
                'created_at' => $changeRequest->created_at?->toIso8601String(),
                'updated_at' => $changeRequest->updated_at?->toIso8601String(),
                'can_approve' => $changeRequest->status === 'pending'
                    && collect($changeRequest->assigned_approver_ids ?? [])->map(fn ($id) => (int) $id)->contains((int) auth()->id())
                    && auth()->user()?->can('schedules.approve'),
                'can_cancel' => $changeRequest->status === 'pending'
                    && (int) $changeRequest->requester_id === (int) auth()->id(),
            ];
        })->values();
    }

    private function scheduleRequestPayloadWithLabels(?array $payload, $storeLabels, $ticketLabels): ?array
    {
        if (!is_array($payload)) {
            return $payload;
        }

        $payload['stores'] = collect($payload['stores'] ?? [])->map(function (array $entry) use ($storeLabels, $ticketLabels) {
            $entry['store_label'] = $storeLabels->get($entry['store_id'] ?? null);
            $entry['ticket_label'] = $ticketLabels->get($entry['ticket_id'] ?? null);

            return $entry;
        })->values()->all();

        return $payload;
    }

    private function creatableScheduleUserIds(User $user): array
    {
        if (!$user->can('schedules.create')) {
            return [];
        }

        if ($user->hasAnyRole(['Admin', 'Solutions Admin'])) {
            return User::query()
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $userIds = collect([(int) $user->id]);

        if ($user->is_manager) {
            $userIds = $userIds->merge($this->getTransitiveSubordinateIds((int) $user->id));
        }

        return User::query()
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->whereIn('id', $userIds->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function editableScheduleUserIds(User $user): array
    {
        if (!$user->can('schedules.edit')) {
            return [];
        }

        if ($user->hasAnyRole(['Admin', 'Solutions Admin'])) {
            return User::query()
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $userIds = collect([(int) $user->id]);

        if ($user->is_manager) {
            $userIds = $userIds->merge($this->getTransitiveSubordinateIds((int) $user->id));
        }

        return User::query()
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->whereIn('id', $userIds->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function directActualTimeUserIds(User $user): array
    {
        if ($user->can('schedules.edit')) {
            return $this->editableScheduleUserIds($user);
        }

        return $user->is_manager ? [(int) $user->id] : [];
    }

    private function requestableActualTimeUserIds(User $user): array
    {
        if ($user->can('schedules.edit')) {
            return [];
        }

        $userIds = collect([(int) $user->id]);

        if ($user->is_manager) {
            $userIds = $userIds->merge($this->getTransitiveSubordinateIds((int) $user->id));
        }

        return $userIds
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
