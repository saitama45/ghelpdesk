<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AttendanceController extends Controller implements HasMiddleware
{
    private const BROWSER_LOCATION_FRESHNESS_SECONDS = 60;

    private const MIN_BROWSER_ACCURACY_LIMIT_METERS = 100;

    public static function middleware(): array
    {
        return [
            new Middleware('can:attendance.view', only: ['index']),
            new Middleware('can:attendance.logs', only: ['logs']),
            new Middleware('can:attendance.create', only: ['log']),
        ];
    }

    public function index(Request $request)
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

        // Scope lastLog to the specific segment if available, otherwise the whole schedule.
        // Also restrict to today's date so yesterday's logs for a multi-day schedule are ignored.
        $lastLog = null;
        if ($todaySchedule) {
            $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $todaySchedule, $activeStoreEntry, $now);
            $lastLog = $lastLogQuery->latest('log_time')->first();
        }

        // Check if the current segment already has both time_in and time_out for TODAY.
        $isSegmentComplete = false;
        if ($todaySchedule) {
            $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $todaySchedule, $activeStoreEntry, $now);
            $segmentTypes = $segmentLogsQuery->pluck('type');
            $isSegmentComplete = $segmentTypes->contains('time_in') && $segmentTypes->contains('time_out');
        }

        return Inertia::render('Attendance/Index', [
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

        // Base query - load relationships for both segment-based and legacy logs
        $query = AttendanceLog::with(['user', 'scheduleStore.store', 'schedule.store'])->latest('log_time');

        // Privacy Logic: Show only own logs if not Admin/Dev/Manager
        if (! $user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) && ! $user->is_manager) {
            $query->where('user_id', $user->id);
        }

        // Filter by Sub-Unit
        if ($request->filled('sub_unit')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('org_path', 'like', '%'.$request->sub_unit.'%');
            });
        }

        // Filter by Store
        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStore', fn ($sq) => $sq->where('store_id', $request->store_id));
        }

        // Filter by Date Range (Default to today if not set)
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

        return Inertia::render('Attendance/Logs', [
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

        if (! $user) {
            return back()->with('error', 'Unauthorized.');
        }

        $request->validate([
            'client_request_id' => 'nullable|uuid',
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
                    return $this->attendanceBackError(
                        'Attendance state changed before saving. Please refresh DTR and try again.',
                        $user->id,
                        $expectedType,
                        $existingLog->type,
                        $existingLog->schedule_id
                    );
                }

                return $this->attendanceLogRedirect($existingLog);
            }
        }

        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'location_accuracy' => 'nullable|numeric|min:0',
            'location_captured_at' => 'nullable|date',
            'location_received_at' => 'nullable|date',
            'location_client' => 'nullable|in:native,web',
            'location_provider' => 'nullable|in:capacitor,browser',
            'photo' => 'required|string', // Base64 encoded image
        ]);

        $locationClient = $request->input('location_client', 'web');

        $throttleKey = 'attendance-log:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->with('error', 'Too many attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.');
        }

        // Find the active schedule for this user right now.
        $now = now('Asia/Manila');
        [$schedule, $activeStoreEntry] = $this->resolveScheduleForAttendance($user->id, $now);

        if (! $schedule) {
            return $this->attendanceBackError(
                'No active On-site, Off-site, or WFH schedule found for your current time. Please contact your supervisor.',
                $user->id,
                $expectedType,
                null,
                null
            );
        }

        // Determine type before geofence validation so Time Out can be handled
        // as a location-audited event rather than another geofence pass.
        $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $lastLog = $lastLogQuery->latest('log_time')->first();

        $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $segmentLogs = $segmentLogsQuery->pluck('type');
        if ($segmentLogs->contains('time_in') && $segmentLogs->contains('time_out')) {
            return $this->attendanceBackError(
                'You have already completed Time In and Time Out for this schedule. No further logging is allowed for this time frame.',
                $user->id,
                $expectedType,
                null,
                $schedule->id
            );
        }

        $type = (! $lastLog || $lastLog->type === 'time_out') ? 'time_in' : 'time_out';

        if ($expectedType && $expectedType !== $type) {
            return $this->attendanceBackError(
                'Attendance state changed before saving. Please refresh DTR and try again.',
                $user->id,
                $expectedType,
                $type,
                $schedule->id
            );
        }

        // Prevent duplicate logs within a short window (5 minutes)
        if ($lastLog && $lastLog->created_at->addMinutes(5)->isFuture()) {
            return $this->attendanceBackError(
                'A log was already recorded recently. Please wait a few minutes.',
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
            $locationCapturedAt = $request->filled('location_captured_at')
                ? Carbon::parse($request->input('location_captured_at'))
                : null;
            $locationReceivedAt = $request->filled('location_received_at')
                ? Carbon::parse($request->input('location_received_at'))
                : null;

            if (! $activeStoreEntry || ! $store) {
                return $this->attendanceBackError('The active schedule has no store assigned. Please contact your supervisor.', $user->id, $expectedType, $type, $schedule->id);
            }

            if ($store->latitude === null || $store->longitude === null) {
                return $this->attendanceBackError("The active schedule store ({$store->name}) has no GPS coordinates configured. Please contact HR.", $user->id, $expectedType, $type, $schedule->id);
            }

            if ($locationClient === 'web') {
                $freshnessTimestamp = $locationReceivedAt ?: $locationCapturedAt;

                if (! $freshnessTimestamp) {
                    return $this->attendanceBackError('Browser location timestamp is missing. Refresh GPS and wait for a fresh fix before logging attendance.', $user->id, $expectedType, $type, $schedule->id);
                }

                if ($freshnessTimestamp->lt($now->copy()->subSeconds(self::BROWSER_LOCATION_FRESHNESS_SECONDS))) {
                    return $this->attendanceBackError('Browser location is stale. Refresh GPS and wait for a fresh fix from your current position before logging attendance.', $user->id, $expectedType, $type, $schedule->id);
                }
            }

            $radius = $store->radius_meters ?: 100;
            if ($locationClient === 'web') {
                $accuracyLimit = max($radius, self::MIN_BROWSER_ACCURACY_LIMIT_METERS);
                if (! $request->filled('location_accuracy')) {
                    return $this->attendanceBackError("Browser location accuracy is missing. Refresh GPS until accuracy is within {$accuracyLimit}m.", $user->id, $expectedType, $type, $schedule->id);
                }

                if ((float) $request->input('location_accuracy') > $accuracyLimit) {
                    return $this->attendanceBackError("Browser location accuracy is too broad. Refresh GPS until accuracy is within {$accuracyLimit}m.", $user->id, $expectedType, $type, $schedule->id);
                }
            }

            $distance = $this->calculateDistance($userLat, $userLng, $store->latitude, $store->longitude);

            if ($distance > $radius) {
                return $this->attendanceBackError("You are outside the active schedule store vicinity for {$store->name}. (".round($distance)."m away, allowed {$radius}m)", $user->id, $expectedType, $type, $schedule->id);
            }

        }

        // Handle Base64 Photo
        $photoData = $request->photo;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $typeMatch)) {
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
            $extension = strtolower($typeMatch[1]);

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                return back()->with('error', 'Invalid image type.');
            }

            $photoData = base64_decode($photoData);
            if ($photoData === false) {
                return back()->with('error', 'Base64 decode failed.');
            }
        } else {
            return back()->with('error', 'Invalid photo format.');
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
        } catch (QueryException $e) {
            if (! $clientRequestId || ! $this->isDuplicateKeyException($e)) {
                throw $e;
            }

            Storage::disk('public')->delete($fileName);

            $log = AttendanceLog::where('user_id', $user->id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if (! $log) {
                throw $e;
            }

            if ($expectedType && $log->type !== $expectedType) {
                return $this->attendanceBackError(
                    'Attendance state changed before saving. Please refresh DTR and try again.',
                    $user->id,
                    $expectedType,
                    $log->type,
                    $log->schedule_id
                );
            }
        }

        RateLimiter::clear($throttleKey);

        return $this->attendanceLogRedirect($log);
    }

    private function attendanceLogRedirect(AttendanceLog $log)
    {
        return redirect()
            ->route('attendance.logs')
            ->with('success', 'Successfully '.($log->type === 'time_in' ? 'Timed In' : 'Timed Out'));
    }

    private function attendanceBackError(string $message, int $userId, ?string $expectedType, ?string $resolvedType, ?int $scheduleId)
    {
        Log::warning('Attendance log rejected', [
            'user_id' => $userId,
            'expected_type' => $expectedType,
            'resolved_type' => $resolvedType,
            'schedule_id' => $scheduleId,
            'reason' => $message,
        ]);

        return back()
            ->withErrors(['attendance' => $message])
            ->with('error', $message);
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000' || in_array($driverCode, ['2601', '2627', '1062', '1555', '19'], true);
    }

    private function buildWorkHoursSummary(Request $request, string $dateFrom, string $dateTo): array
    {
        $authUser = auth()->user();
        $isPrivileged = $authUser->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) || $authUser->is_manager;

        // --- Scheduled hours ---
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

            // Daily shift hours (same every day within the block)
            $dailyStart = $s->start_time->format('H:i');
            $dailyEnd = $s->end_time->format('H:i');
            [$sh, $sm] = explode(':', $dailyStart);
            [$eh, $em] = explode(':', $dailyEnd);
            $dailyMinutes = max(0, ((int) $eh * 60 + (int) $em) - ((int) $sh * 60 + (int) $sm) - 60);

            // Intersect the schedule block with the filter date range
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

        // --- Actual hours ---
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
            // Key by date and segment to handle multi-day schedules and multiple segments per day
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

        // --- Merge ---
        $allUserIds = array_unique(array_merge(array_keys($scheduledByUser), array_keys($actualByUser)));

        $summary = [];
        foreach ($allUserIds as $uid) {
            $sched = $scheduledByUser[$uid] ?? null;
            $actual = $actualByUser[$uid] ?? null;

            // Build per-date log pairs keyed by date
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

            // Compute actual minutes from H:i strings, subtract 60 min per day for lunch break
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

            // Build detail rows: one entry per date that appears in schedules or logs
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

    /**
     * Calculate distance between two points using Haversine formula (returns meters)
     */
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
