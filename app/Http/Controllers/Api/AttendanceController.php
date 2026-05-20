<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    private const BROWSER_LOCATION_FRESHNESS_SECONDS = 60;

    private const MIN_BROWSER_ACCURACY_LIMIT_METERS = 100;

    public function offlineBootstrap(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'days' => 'required|integer|min:1',
        ]);

        $rangeStart = Carbon::createFromFormat('Y-m-d', $validated['from'], 'Asia/Manila')->startOfDay();
        $rangeEnd = $rangeStart->copy()->addDays((int) $validated['days']);

        $schedules = Schedule::where('user_id', $user->id)
            ->whereIn('status', ['On-site', 'Off-site', 'WFH'])
            ->where('start_time', '>=', $rangeStart)
            ->where('start_time', '<', $rangeEnd)
            ->with(['scheduleStores.store'])
            ->orderBy('start_time')
            ->get();

        $bootstrapSchedules = $schedules->flatMap(function (Schedule $schedule) {
            if ($schedule->scheduleStores->isEmpty()) {
                return [[
                    'id' => (string) $schedule->id,
                    'schedule_store_id' => null,
                    'user_id' => (string) $schedule->user_id,
                    'status' => $schedule->status,
                    'start_time' => $schedule->start_time->copy()->utc()->toIso8601String(),
                    'end_time' => $schedule->end_time->copy()->utc()->toIso8601String(),
                    'store' => null,
                ]];
            }

            return $schedule->scheduleStores->map(function (ScheduleStore $scheduleStore) use ($schedule) {
                return [
                    'id' => (string) $schedule->id,
                    'schedule_store_id' => (string) $scheduleStore->id,
                    'user_id' => (string) $schedule->user_id,
                    'status' => $schedule->status,
                    'start_time' => $scheduleStore->start_time->copy()->utc()->toIso8601String(),
                    'end_time' => $scheduleStore->end_time->copy()->utc()->toIso8601String(),
                    'store' => $scheduleStore->store ? [
                        'id' => (string) $scheduleStore->store->id,
                        'code' => $scheduleStore->store->code,
                        'name' => $scheduleStore->store->name,
                        'latitude' => $scheduleStore->store->latitude,
                        'longitude' => $scheduleStore->store->longitude,
                        'radius_meters' => $scheduleStore->store->radius_meters ?: 100,
                    ] : null,
                ];
            });
        })->values();

        return response()->json([
            'data' => [
                'schedules' => $bootstrapSchedules,
            ],
        ]);
    }

    public function status(Request $request)
    {
        $user = auth()->user();

        $assignedStores = $user->stores()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('is_active', true)
            ->get();

        $totalAssignedCount = $user->stores()->count();

        // If no stores are assigned (or none have GPS), and user is Admin/Dev, show all active stores with GPS
        if ($assignedStores->isEmpty() && $user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin'])) {
            $assignedStores = Store::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('is_active', true)
                ->get();
        }

        $now = now('Asia/Manila');
        [$todaySchedule, $activeStoreEntry] = $this->resolveScheduleForAttendance($user->id, $now);

        $lastLog = null;
        if ($todaySchedule) {
            $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $todaySchedule, $activeStoreEntry, $now);
            $lastLog = $lastLogQuery->latest('log_time')->first();
        }

        $isSegmentComplete = false;
        if ($todaySchedule) {
            $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $todaySchedule, $activeStoreEntry, $now);
            $segmentTypes = $segmentLogsQuery->pluck('type');
            $isSegmentComplete = $segmentTypes->contains('time_in') && $segmentTypes->contains('time_out');
        }

        return response()->json([
            'lastLog' => $lastLog,
            'isSegmentComplete' => $isSegmentComplete,
            'assignedStores' => $assignedStores,
            'totalAssignedCount' => $totalAssignedCount,
            'todaySchedule' => $todaySchedule ? [
                'id' => $todaySchedule->id,
                'status' => $todaySchedule->status,
                'start_time' => ($activeStoreEntry ? $activeStoreEntry->start_time : $todaySchedule->start_time)->toIso8601String(),
                'end_time' => ($activeStoreEntry ? $activeStoreEntry->end_time : $todaySchedule->end_time)->toIso8601String(),
                'store' => ($activeStoreEntry && $activeStoreEntry->store)
                    ? [
                        'id' => $activeStoreEntry->store->id,
                        'code' => $activeStoreEntry->store->code,
                        'name' => $activeStoreEntry->store->name,
                        'latitude' => $activeStoreEntry->store->latitude,
                        'longitude' => $activeStoreEntry->store->longitude,
                        'radius_meters' => $activeStoreEntry->store->radius_meters ?: 100,
                    ]
                    : null,
            ] : null,
        ]);
    }

    public function logs(Request $request)
    {
        $user = auth()->user();

        $query = AttendanceLog::with(['user', 'scheduleStore.store', 'schedule.store'])->latest('log_time');

        if (! $user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) && ! $user->is_manager) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('sub_unit')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('org_path', 'like', '%'.$request->sub_unit.'%');
            });
        }

        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStore', fn ($sq) => $sq->where('store_id', $request->store_id));
        }

        $dateFrom = $request->get('date_from', now()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        $query->whereDate('log_time', '>=', $dateFrom)
            ->whereDate('log_time', '<=', $dateTo);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$request->search}%"))
                    ->orWhere('device_info', 'like', "%{$request->search}%")
                    ->orWhere('type', 'like', "%{$request->search}%");
            });
        }

        $logs = $query->paginate($request->get('perPage', 10))->withQueryString();
        $users = User::active()->orderBy('name')->get(['id', 'name', 'org_path']);
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $workHoursSummary = $this->buildWorkHoursSummary($request, $dateFrom, $dateTo);

        return response()->json([
            'logs' => $logs,
            'users' => $users,
            'stores' => $stores,
            'workHoursSummary' => $workHoursSummary,
            'filters' => [
                'sub_unit' => $request->sub_unit,
                'store_id' => $request->store_id,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $request->search,
            ],
        ]);
    }

    public function log(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'client_request_id' => ['nullable', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'],
            'expected_type' => 'nullable|in:time_in,time_out',
        ]);

        $clientRequestId = $request->input('client_request_id');
        $expectedType = $request->input('expected_type');
        if ($clientRequestId) {
            $existingLog = AttendanceLog::where('user_id', $user->id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existingLog) {
                if ($expectedType && $existingLog->type !== $expectedType) {
                    return $this->attendanceJsonError(
                        'Attendance state changed before saving. Please refresh DTR and try again.',
                        409,
                        $user->id,
                        $expectedType,
                        $existingLog->type,
                        $existingLog->schedule_id
                    );
                }

                return $this->attendanceLogSuccessResponse($existingLog);
            }
        }

        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'location_accuracy' => 'nullable|numeric|min:0',
            'location_captured_at' => 'nullable|date',
            'location_received_at' => 'nullable|date',
            'location_client' => 'nullable|in:native,web',
            'location_provider' => 'nullable|in:capacitor,browser,android,ios',
            'photo' => 'required|string', // Base64 encoded image
        ]);

        $locationClient = $request->input('location_client', 'native');

        $throttleKey = 'attendance-log:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ], 429);
        }

        $now = now('Asia/Manila');
        [$schedule, $activeStoreEntry] = $this->resolveScheduleForAttendance($user->id, $now);

        if (! $schedule) {
            return $this->attendanceJsonError(
                'No active On-site, Off-site, or WFH schedule found for your current time. Please contact your supervisor.',
                422,
                $user->id,
                $expectedType,
                null,
                null
            );
        }

        $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $lastLog = $lastLogQuery->latest('log_time')->first();

        $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $segmentLogs = $segmentLogsQuery->pluck('type');
        if ($segmentLogs->contains('time_in') && $segmentLogs->contains('time_out')) {
            return $this->attendanceJsonError(
                'You have already completed Time In and Time Out for this schedule.',
                422,
                $user->id,
                $expectedType,
                null,
                $schedule->id
            );
        }

        $type = (! $lastLog || $lastLog->type === 'time_out') ? 'time_in' : 'time_out';

        if ($expectedType && $expectedType !== $type) {
            return $this->attendanceJsonError(
                'Attendance state changed before saving. Please refresh DTR and try again.',
                409,
                $user->id,
                $expectedType,
                $type,
                $schedule->id
            );
        }

        if ($lastLog && $lastLog->created_at->addMinutes(5)->isFuture()) {
            return $this->attendanceJsonError(
                'A log was already recorded recently. Please wait a few minutes.',
                422,
                $user->id,
                $expectedType,
                $type,
                $schedule->id
            );
        }

        // GEOFENCING VALIDATION (Time In only; Time Out stores location for audit)
        if ($type === 'time_in' && $schedule->status !== 'WFH') {
            $userLat = $request->latitude;
            $userLng = $request->longitude;
            $store = $activeStoreEntry?->store;

            if (! $activeStoreEntry || ! $store) {
                return $this->attendanceJsonError('The active schedule has no store assigned. Please contact your supervisor.', 422, $user->id, $expectedType, $type, $schedule->id);
            }

            if ($store->latitude === null || $store->longitude === null) {
                return $this->attendanceJsonError("The active schedule store ({$store->name}) has no GPS coordinates configured. Please contact HR.", 422, $user->id, $expectedType, $type, $schedule->id);
            }

            $radius = $store->radius_meters ?: 100;
            $distance = $this->calculateDistance($userLat, $userLng, $store->latitude, $store->longitude);

            if ($distance > $radius) {
                return $this->attendanceJsonError("You are outside the active schedule store vicinity for {$store->name}. (".round($distance)."m away, allowed {$radius}m)", 422, $user->id, $expectedType, $type, $schedule->id);
            }
        }

        // Handle Base64 Photo
        $photoData = $request->photo;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $typeMatch)) {
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
            $extension = strtolower($typeMatch[1]);

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                return response()->json(['message' => 'Invalid image type.'], 422);
            }

            $photoData = base64_decode($photoData);
            if ($photoData === false) {
                return response()->json(['message' => 'Base64 decode failed.'], 422);
            }
        } else {
            return response()->json(['message' => 'Invalid photo format.'], 422);
        }

        $fileName = 'attendance/'.$user->id.'/'.now()->timestamp.'_'.Str::random(10).'.'.$extension;
        Storage::disk('public')->put($fileName, $photoData);

        try {
            $log = AttendanceLog::create([
                'user_id' => $user->id,
                'client_request_id' => $clientRequestId,
                'schedule_id' => $schedule->id,
                'schedule_store_id' => $activeStoreEntry?->id,
                'type' => $type,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'location_accuracy' => $request->input('location_accuracy'),
                'location_captured_at' => $request->input('location_captured_at'),
                'location_received_at' => $request->input('location_received_at'),
                'location_client' => $locationClient,
                'location_provider' => $request->input('location_provider'),
                'photo_path' => $fileName,
                'log_time' => now('Asia/Manila'),
                'device_info' => $request->input('device_info', $request->header('User-Agent')),
                'ip_address' => $request->input('public_ip', $request->ip()),
            ]);
        } catch (QueryException $exception) {
            if (! $clientRequestId || ! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            $existingLog = AttendanceLog::where('user_id', $user->id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if (! $existingLog) {
                throw $exception;
            }

            Storage::disk('public')->delete($fileName);
            if ($expectedType && $existingLog->type !== $expectedType) {
                return $this->attendanceJsonError(
                    'Attendance state changed before saving. Please refresh DTR and try again.',
                    409,
                    $user->id,
                    $expectedType,
                    $existingLog->type,
                    $existingLog->schedule_id
                );
            }

            return $this->attendanceLogSuccessResponse($existingLog);
        }

        RateLimiter::clear($throttleKey);

        return $this->attendanceLogSuccessResponse($log);
    }

    private function attendanceLogSuccessResponse(AttendanceLog $log)
    {
        return response()->json([
            'success' => true,
            'message' => 'Successfully '.($log->type === 'time_in' ? 'Timed In' : 'Timed Out'),
            'log' => $log,
        ]);
    }

    private function attendanceJsonError(string $message, int $status, int $userId, ?string $expectedType, ?string $resolvedType, ?int $scheduleId)
    {
        Log::warning('Attendance log rejected', [
            'user_id' => $userId,
            'expected_type' => $expectedType,
            'resolved_type' => $resolvedType,
            'schedule_id' => $scheduleId,
            'reason' => $message,
        ]);

        return response()->json(['message' => $message], $status);
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            || ($exception->errorInfo[0] ?? null) === '23000';
    }

    private function buildWorkHoursSummary(Request $request, string $dateFrom, string $dateTo): array
    {
        $authUser = auth()->user();
        $isPrivileged = $authUser->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) || $authUser->is_manager;

        $scheduleQuery = Schedule::whereIn('status', ['On-site', 'Off-site', 'WFH'])
            ->where('start_time', '<=', $dateTo.' 23:59:59')
            ->where('end_time', '>=', $dateFrom.' 00:00:00')
            ->with('user:id,name');

        if (! $isPrivileged) {
            $scheduleQuery->where('user_id', $authUser->id);
        }

        if ($request->filled('sub_unit')) {
            $scheduleQuery->whereHas('user', fn ($q) => $q->where('org_path', 'like', '%'.$request->sub_unit.'%'));
        }

        if ($request->filled('search')) {
            $scheduleQuery->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'));
        }

        $schedules = $scheduleQuery->get();

        $scheduledByUser = [];
        foreach ($schedules as $s) {
            if (! $s->user) {
                continue;
            }
            $uid = $s->user_id;

            if (! isset($scheduledByUser[$uid])) {
                $scheduledByUser[$uid] = [
                    'user_id' => $uid,
                    'name' => $s->user->name,
                    'scheduled_minutes' => 0,
                    'scheduled_days' => [],
                ];
            }

            $dailyStart = $s->start_time->format('H:i');
            $dailyEnd = $s->end_time->format('H:i');
            [$sh, $sm] = explode(':', $dailyStart);
            [$eh, $em] = explode(':', $dailyEnd);
            $dailyMinutes = max(0, ((int) $eh * 60 + (int) $em) - ((int) $sh * 60 + (int) $sm) - 60);

            $blockStart = max($s->start_time->toDateString(), $dateFrom);
            $blockEnd = min($s->end_time->toDateString(), $dateTo);

            $cursor = Carbon::parse($blockStart);
            $limit = Carbon::parse($blockEnd);

            while ($cursor->lte($limit)) {
                $date = $cursor->toDateString();
                if (! isset($scheduledByUser[$uid]['scheduled_days'][$date])) {
                    $scheduledByUser[$uid]['scheduled_days'][$date] = [
                        'scheduled_start' => $dailyStart,
                        'scheduled_end' => $dailyEnd,
                    ];
                    $scheduledByUser[$uid]['scheduled_minutes'] += $dailyMinutes;
                }
                $cursor->addDay();
            }
        }

        $logQuery = AttendanceLog::whereBetween('log_time', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->orderBy('log_time')
            ->select('user_id', 'schedule_id', 'schedule_store_id', 'type', 'log_time');

        if (! $isPrivileged) {
            $logQuery->where('user_id', $authUser->id);
        }

        if ($request->filled('sub_unit')) {
            $logQuery->whereHas('user', fn ($q) => $q->where('org_path', 'like', '%'.$request->sub_unit.'%'));
        }

        if ($request->filled('search')) {
            $logQuery->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'));
        }

        if ($request->filled('store_id')) {
            $logQuery->whereHas('scheduleStore', fn ($q) => $q->where('store_id', $request->store_id));
        }

        $actualByUser = [];
        foreach ($logQuery->get() as $log) {
            $uid = $log->user_id;
            $date = $log->log_time->toDateString();
            $segKey = ($log->schedule_store_id ? 'ss_'.$log->schedule_store_id : 's_'.$log->schedule_id).'_'.$date;

            if (! isset($actualByUser[$uid])) {
                $actualByUser[$uid] = ['segments' => [], 'days_present' => []];
            }

            if (! isset($actualByUser[$uid]['segments'][$segKey])) {
                $actualByUser[$uid]['segments'][$segKey] = ['date' => $date, 'time_in' => null, 'time_out' => null];
            }

            if ($log->type === 'time_in' && $actualByUser[$uid]['segments'][$segKey]['time_in'] === null) {
                $actualByUser[$uid]['segments'][$segKey]['time_in'] = $log->log_time->format('H:i');
            } elseif ($log->type === 'time_out') {
                $actualByUser[$uid]['segments'][$segKey]['time_out'] = $log->log_time->format('H:i');
            }

            if ($log->type === 'time_in') {
                $actualByUser[$uid]['days_present'][$date] = true;
            }
        }

        $allUserIds = array_unique(array_merge(array_keys($scheduledByUser), array_keys($actualByUser)));

        $summary = [];
        foreach ($allUserIds as $uid) {
            $sched = $scheduledByUser[$uid] ?? null;
            $actual = $actualByUser[$uid] ?? null;

            $logsByDate = [];
            if ($actual) {
                foreach ($actual['segments'] as $seg) {
                    $d = $seg['date'];
                    if (! isset($logsByDate[$d])) {
                        $logsByDate[$d] = ['time_in' => null, 'time_out' => null];
                    }
                    if ($seg['time_in'] && ! $logsByDate[$d]['time_in']) {
                        $logsByDate[$d]['time_in'] = $seg['time_in'];
                    }
                    if ($seg['time_out']) {
                        $logsByDate[$d]['time_out'] = $seg['time_out'];
                    }
                }
            }

            $actualMinutes = 0;
            $actualMinutesByDate = [];
            foreach ($logsByDate as $date => $pair) {
                if ($pair['time_in'] && $pair['time_out']) {
                    [$inH, $inM] = explode(':', $pair['time_in']);
                    [$outH, $outM] = explode(':', $pair['time_out']);
                    $workedMinutes = max(0, ((int) $outH * 60 + (int) $outM) - ((int) $inH * 60 + (int) $inM) - 60);
                    $actualMinutesByDate[$date] = $workedMinutes;
                    $actualMinutes += $workedMinutes;
                }
            }

            $scheduledDates = $sched['scheduled_days'] ?? [];
            $scheduledDays = count($scheduledDates);
            $daysPresent = $actual ? count($actual['days_present']) : 0;

            $allDates = array_unique(array_merge(array_keys($scheduledDates), array_keys($logsByDate)));
            sort($allDates);
            $detailDates = [];
            foreach ($allDates as $date) {
                $isPresent = isset($logsByDate[$date]) && $logsByDate[$date]['time_in'] !== null;
                $detailDates[] = [
                    'date' => $date,
                    'scheduled_start' => $scheduledDates[$date]['scheduled_start'] ?? null,
                    'scheduled_end' => $scheduledDates[$date]['scheduled_end'] ?? null,
                    'actual_time_in' => $logsByDate[$date]['time_in'] ?? null,
                    'actual_time_out' => $logsByDate[$date]['time_out'] ?? null,
                    'actual_minutes' => $actualMinutesByDate[$date] ?? null,
                    'is_present' => $isPresent,
                ];
            }

            $name = $sched['name'] ?? null;
            if (! $name && $actual) {
                $userModel = User::find($uid);
                $name = $userModel?->name ?? 'Unknown';
            }

            $summary[] = [
                'user_id' => $uid,
                'name' => $name,
                'scheduled_minutes' => $sched['scheduled_minutes'] ?? 0,
                'actual_minutes' => $actualMinutes,
                'scheduled_days' => $scheduledDays,
                'days_present' => $daysPresent,
                'detail_dates' => $detailDates,
            ];
        }

        usort($summary, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return $summary;
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Meters
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function resolveActiveScheduleStore(Schedule $schedule, $now): ?ScheduleStore
    {
        $activeStoreEntry = $schedule->scheduleStores->first(function ($ss) use ($now) {
            return $this->isWithinScheduleStoreWindow($ss, $now);
        });

        return $activeStoreEntry ?: null;
    }

    private function resolveScheduleForAttendance(int $userId, $now): array
    {
        $schedule = Schedule::where('user_id', $userId)
            ->whereIn('status', ['On-site', 'Off-site', 'WFH'])
            ->where('start_time', '<=', $now->copy()->addMinutes(480))
            ->where('end_time', '>=', $now)
            ->with(['scheduleStores.store'])
            ->get()
            ->first(function ($s) use ($now) {
                return $s->scheduleStores->some(
                    fn ($ss) => $this->isWithinScheduleStoreWindow($ss, $now)
                ) || $s->scheduleStores->isEmpty();
            });

        if ($schedule) {
            return [$schedule, $this->resolveActiveScheduleStore($schedule, $now)];
        }

        // Allow Time Out for an already-open attendance session even when
        // the strict active window has passed. Looks back 24h so a Time In
        // from the previous day (e.g., a near-midnight schedule) is still
        // closable, then enforces a 6h ceiling past the schedule end_time.
        $lateGraceMinutes = 6 * 60;

        $lastOpenLog = AttendanceLog::with(['schedule.scheduleStores.store', 'scheduleStore.store'])
            ->where('user_id', $userId)
            ->where('log_time', '>=', $now->copy()->subHours(24))
            ->where('type', 'time_in')
            ->latest('log_time')
            ->first();

        if (! $lastOpenLog || ! $lastOpenLog->schedule) {
            return [null, null];
        }

        $schedule = $lastOpenLog->schedule;

        if (! in_array($schedule->status, ['On-site', 'Off-site', 'WFH'], true)) {
            return [null, null];
        }

        $activeStoreEntry = $lastOpenLog->scheduleStore;
        $effectiveEnd = $activeStoreEntry ? $activeStoreEntry->end_time : $schedule->end_time;
        if ($now->gt($effectiveEnd->copy()->addMinutes($lateGraceMinutes))) {
            return [null, null];
        }

        $segmentLogs = $this->buildSegmentLogsQuery($userId, $schedule, $activeStoreEntry, $now)->pluck('type');
        if ($segmentLogs->contains('time_in') && ! $segmentLogs->contains('time_out')) {
            return [$schedule->loadMissing(['scheduleStores.store']), $activeStoreEntry];
        }

        return [null, null];
    }

    private function isWithinScheduleStoreWindow(ScheduleStore $scheduleStore, $now): bool
    {
        $graceMinutes = (int) ($scheduleStore->grace_period_minutes ?? 30);
        $windowStart = $scheduleStore->start_time->copy()->subMinutes($graceMinutes);

        return $windowStart->lte($now) && $scheduleStore->end_time->gte($now);
    }

    private function buildSegmentLogsQuery(int $userId, Schedule $schedule, ?ScheduleStore $activeStoreEntry, $now)
    {
        $lateGraceMinutes = 6 * 60;
        $lookbackStart = $schedule->start_time->copy()->subMinutes(60);
        $lookbackEnd = $schedule->end_time->copy()->addMinutes($lateGraceMinutes);

        $query = AttendanceLog::where('user_id', $userId)
            ->where('schedule_id', $schedule->id)
            ->where('log_time', '>=', $lookbackStart)
            ->where('log_time', '<=', $lookbackEnd);

        if (! $activeStoreEntry) {
            return $query;
        }

        $graceMinutes = (int) ($activeStoreEntry->grace_period_minutes ?? 30);
        $windowStart = $activeStoreEntry->start_time->copy()->subMinutes($graceMinutes);
        $windowEnd = $activeStoreEntry->end_time->copy()->addMinutes($lateGraceMinutes);

        return $query->where(function ($logQuery) use ($activeStoreEntry, $windowStart, $windowEnd) {
            $logQuery->where('schedule_store_id', $activeStoreEntry->id)
                ->orWhere(function ($fallbackQuery) use ($windowStart, $windowEnd) {
                    $fallbackQuery->whereBetween('log_time', [$windowStart, $windowEnd]);
                });
        });
    }
}
