<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    private const BROWSER_LOCATION_FRESHNESS_SECONDS = 60;
    private const MIN_BROWSER_ACCURACY_LIMIT_METERS = 100;

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
                'id'         => $todaySchedule->id,
                'status'     => $todaySchedule->status,
                'start_time' => ($activeStoreEntry ? $activeStoreEntry->start_time : $todaySchedule->start_time)->toIso8601String(),
                'end_time'   => ($activeStoreEntry ? $activeStoreEntry->end_time : $todaySchedule->end_time)->toIso8601String(),
                'store'      => ($activeStoreEntry && $activeStoreEntry->store)
                    ? [
                        'id'            => $activeStoreEntry->store->id,
                        'code'          => $activeStoreEntry->store->code,
                        'name'          => $activeStoreEntry->store->name,
                        'latitude'      => $activeStoreEntry->store->latitude,
                        'longitude'     => $activeStoreEntry->store->longitude,
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

        if (!$user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) && !$user->is_manager) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('sub_unit')) {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('org_path', 'like', '%'.$request->sub_unit.'%');
            });
        }

        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStore', fn($sq) => $sq->where('store_id', $request->store_id));
        }

        $dateFrom = $request->get('date_from', now()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        $query->whereDate('log_time', '>=', $dateFrom)
              ->whereDate('log_time', '<=', $dateTo);

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->whereHas('user', fn($uq) => $uq->where('name', 'like', "%{$request->search}%"))
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
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'location_accuracy' => 'nullable|numeric|min:0',
            'location_captured_at' => 'nullable|date',
            'location_received_at' => 'nullable|date',
            'location_client' => 'nullable|in:native,web',
            'location_provider' => 'nullable|in:capacitor,browser',
            'photo' => 'required|string', // Base64 encoded image
        ]);

        $locationClient = $request->input('location_client', 'native');

        $throttleKey = 'attendance-log:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.'
            ], 429);
        }

        $now = now('Asia/Manila');
        [$schedule, $activeStoreEntry] = $this->resolveScheduleForAttendance($user->id, $now);

        if (!$schedule) {
            return response()->json([
                'message' => 'No active On-site, Off-site, or WFH schedule found for your current time. Please contact your supervisor.'
            ], 422);
        }

        // GEOFENCING VALIDATION
        if ($schedule->status !== 'WFH') {
            $userLat = $request->latitude;
            $userLng = $request->longitude;
            $store = $activeStoreEntry?->store;

            if (!$activeStoreEntry || !$store) {
                return response()->json([
                    'message' => 'The active schedule has no store assigned. Please contact your supervisor.'
                ], 422);
            }

            if ($store->latitude === null || $store->longitude === null) {
                return response()->json([
                    'message' => "The active schedule store ({$store->name}) has no GPS coordinates configured. Please contact HR."
                ], 422);
            }

            $radius = $store->radius_meters ?: 100;
            $distance = $this->calculateDistance($userLat, $userLng, $store->latitude, $store->longitude);

            if ($distance > $radius) {
                return response()->json([
                    'message' => "You are outside the active schedule store vicinity for {$store->name}. (" . round($distance) . "m away, allowed {$radius}m)"
                ], 422);
            }
        }

        $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $lastLog = $lastLogQuery->latest('log_time')->first();

        $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $segmentLogs = $segmentLogsQuery->pluck('type');
        if ($segmentLogs->contains('time_in') && $segmentLogs->contains('time_out')) {
            return response()->json([
                'message' => 'You have already completed Time In and Time Out for this schedule.'
            ], 422);
        }

        $type = (!$lastLog || $lastLog->type === 'time_out') ? 'time_in' : 'time_out';

        if ($lastLog && $lastLog->created_at->addMinutes(5)->isFuture()) {
            return response()->json([
                'message' => 'A log was already recorded recently. Please wait a few minutes.'
            ], 422);
        }

        // Handle Base64 Photo
        $photoData = $request->photo;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $typeMatch)) {
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
            $extension = strtolower($typeMatch[1]);

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                return response()->json(['message' => 'Invalid image type.'], 422);
            }

            $photoData = base64_decode($photoData);
            if ($photoData === false) {
                return response()->json(['message' => 'Base64 decode failed.'], 422);
            }
        } else {
            return response()->json(['message' => 'Invalid photo format.'], 422);
        }

        $fileName = 'attendance/' . $user->id . '/' . now()->timestamp . '_' . Str::random(10) . '.' . $extension;
        Storage::disk('public')->put($fileName, $photoData);

        $log = AttendanceLog::create([
            'user_id' => $user->id,
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

        RateLimiter::clear($throttleKey);

        return response()->json([
            'success' => true,
            'message' => 'Successfully ' . ($type === 'time_in' ? 'Timed In' : 'Timed Out'),
            'log' => $log
        ]);
    }

    private function buildWorkHoursSummary(Request $request, string $dateFrom, string $dateTo): array
    {
        $authUser = auth()->user();
        $isPrivileged = $authUser->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) || $authUser->is_manager;

        $scheduleQuery = Schedule::whereIn('status', ['On-site', 'Off-site', 'WFH'])
            ->where('start_time', '<=', $dateTo . ' 23:59:59')
            ->where('end_time',   '>=', $dateFrom . ' 00:00:00')
            ->with('user:id,name');

        if (!$isPrivileged) {
            $scheduleQuery->where('user_id', $authUser->id);
        }

        if ($request->filled('sub_unit')) {
            $scheduleQuery->whereHas('user', fn($q) => $q->where('org_path', 'like', '%'.$request->sub_unit.'%'));
        }

        $schedules = $scheduleQuery->get();

        $scheduledByUser = [];
        foreach ($schedules as $s) {
            if (!$s->user) continue;
            $uid = $s->user_id;

            if (!isset($scheduledByUser[$uid])) {
                $scheduledByUser[$uid] = [
                    'user_id'           => $uid,
                    'name'              => $s->user->name,
                    'scheduled_minutes' => 0,
                    'scheduled_days'    => [],
                ];
            }

            $dailyStart = $s->start_time->format('H:i');
            $dailyEnd   = $s->end_time->format('H:i');
            [$sh, $sm]  = explode(':', $dailyStart);
            [$eh, $em]  = explode(':', $dailyEnd);
            $dailyMinutes = max(0, ((int)$eh * 60 + (int)$em) - ((int)$sh * 60 + (int)$sm) - 60);

            $blockStart = max($s->start_time->toDateString(), $dateFrom);
            $blockEnd   = min($s->end_time->toDateString(),   $dateTo);

            $cursor = Carbon::parse($blockStart);
            $limit  = Carbon::parse($blockEnd);

            while ($cursor->lte($limit)) {
                $date = $cursor->toDateString();
                if (!isset($scheduledByUser[$uid]['scheduled_days'][$date])) {
                    $scheduledByUser[$uid]['scheduled_days'][$date] = [
                        'scheduled_start' => $dailyStart,
                        'scheduled_end'   => $dailyEnd,
                    ];
                    $scheduledByUser[$uid]['scheduled_minutes'] += $dailyMinutes;
                }
                $cursor->addDay();
            }
        }

        $logQuery = AttendanceLog::whereBetween('log_time', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->orderBy('log_time')
            ->select('user_id', 'schedule_id', 'schedule_store_id', 'type', 'log_time');

        if (!$isPrivileged) {
            $logQuery->where('user_id', $authUser->id);
        }

        if ($request->filled('sub_unit')) {
            $logQuery->whereHas('user', fn($q) => $q->where('org_path', 'like', '%'.$request->sub_unit.'%'));
        }

        if ($request->filled('store_id')) {
            $logQuery->whereHas('scheduleStore', fn($q) => $q->where('store_id', $request->store_id));
        }

        $actualByUser = [];
        foreach ($logQuery->get() as $log) {
            $uid    = $log->user_id;
            $date   = $log->log_time->toDateString();
            $segKey = $log->schedule_store_id ? 'ss_'.$log->schedule_store_id : 's_'.$log->schedule_id;

            if (!isset($actualByUser[$uid])) {
                $actualByUser[$uid] = ['segments' => [], 'days_present' => []];
            }

            if (!isset($actualByUser[$uid]['segments'][$segKey])) {
                $actualByUser[$uid]['segments'][$segKey] = ['date' => $date, 'time_in' => null, 'time_out' => null];
            }

            $seg = &$actualByUser[$uid]['segments'][$segKey];
            if ($log->type === 'time_in' && $seg['time_in'] === null) {
                $seg['time_in'] = $log->log_time->format('H:i');
            } elseif ($log->type === 'time_out') {
                $seg['time_out'] = $log->log_time->format('H:i');
            }

            if ($log->type === 'time_in') {
                $actualByUser[$uid]['days_present'][$date] = true;
            }
        }

        $allUserIds = array_unique(array_merge(array_keys($scheduledByUser), array_keys($actualByUser)));

        $summary = [];
        foreach ($allUserIds as $uid) {
            $sched  = $scheduledByUser[$uid] ?? null;
            $actual = $actualByUser[$uid]    ?? null;

            $logsByDate = [];
            if ($actual) {
                foreach ($actual['segments'] as $seg) {
                    $d = $seg['date'];
                    if (!isset($logsByDate[$d])) {
                        $logsByDate[$d] = ['time_in' => null, 'time_out' => null];
                    }
                    if ($seg['time_in'] && !$logsByDate[$d]['time_in']) {
                        $logsByDate[$d]['time_in'] = $seg['time_in'];
                    }
                    if ($seg['time_out']) {
                        $logsByDate[$d]['time_out'] = $seg['time_out'];
                    }
                }
            }

            $actualMinutes = 0;
            foreach ($logsByDate as $pair) {
                if ($pair['time_in'] && $pair['time_out']) {
                    [$inH, $inM]   = explode(':', $pair['time_in']);
                    [$outH, $outM] = explode(':', $pair['time_out']);
                    $actualMinutes += max(0, ((int)$outH * 60 + (int)$outM) - ((int)$inH * 60 + (int)$inM) - 60);
                }
            }

            $scheduledDates = $sched['scheduled_days'] ?? [];
            $scheduledDays  = count($scheduledDates);
            $daysPresent    = $actual ? count($actual['days_present']) : 0;

            $allDates = array_unique(array_merge(array_keys($scheduledDates), array_keys($logsByDate)));
            sort($allDates);
            $detailDates = [];
            foreach ($allDates as $date) {
                $isPresent  = isset($logsByDate[$date]) && $logsByDate[$date]['time_in'] !== null;
                $detailDates[] = [
                    'date'             => $date,
                    'scheduled_start'  => $scheduledDates[$date]['scheduled_start'] ?? null,
                    'scheduled_end'    => $scheduledDates[$date]['scheduled_end']   ?? null,
                    'actual_time_in'   => $logsByDate[$date]['time_in']  ?? null,
                    'actual_time_out'  => $logsByDate[$date]['time_out'] ?? null,
                    'is_present'       => $isPresent,
                ];
            }

            $name = $sched['name'] ?? null;
            if (!$name && $actual) {
                $userModel = User::find($uid);
                $name = $userModel?->name ?? 'Unknown';
            }

            $summary[] = [
                'user_id'           => $uid,
                'name'              => $name,
                'scheduled_minutes' => $sched['scheduled_minutes'] ?? 0,
                'actual_minutes'    => $actualMinutes,
                'scheduled_days'    => $scheduledDays,
                'days_present'      => $daysPresent,
                'detail_dates'      => $detailDates,
            ];
        }

        usort($summary, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

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

        $lastOpenLog = AttendanceLog::with(['schedule.scheduleStores.store', 'scheduleStore.store'])
            ->where('user_id', $userId)
            ->whereDate('log_time', $now->toDateString())
            ->where('type', 'time_in')
            ->latest('log_time')
            ->first();

        if (!$lastOpenLog || !$lastOpenLog->schedule) {
            return [null, null];
        }

        $schedule = $lastOpenLog->schedule;

        if (!in_array($schedule->status, ['On-site', 'Off-site', 'WFH'], true)) {
            return [null, null];
        }

        $segmentLogs = $this->buildSegmentLogsQuery($userId, $schedule, $lastOpenLog->scheduleStore, $now)->pluck('type');
        if ($segmentLogs->contains('time_in') && !$segmentLogs->contains('time_out')) {
            return [$schedule->loadMissing(['scheduleStores.store']), $lastOpenLog->scheduleStore];
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
        $query = AttendanceLog::where('user_id', $userId)
            ->where('schedule_id', $schedule->id)
            ->where('log_time', '>=', $now->copy()->startOfDay())
            ->where('log_time', '<=', $now->copy()->endOfDay());

        if (!$activeStoreEntry) {
            return $query;
        }

        $graceMinutes = (int) ($activeStoreEntry->grace_period_minutes ?? 30);
        $windowStart = $activeStoreEntry->start_time->copy()->subMinutes($graceMinutes);
        $windowEnd = $activeStoreEntry->end_time->copy();

        return $query->where(function ($logQuery) use ($activeStoreEntry, $windowStart, $windowEnd) {
            $logQuery->where('schedule_store_id', $activeStoreEntry->id)
                ->orWhere(function ($fallbackQuery) use ($windowStart, $windowEnd) {
                    $fallbackQuery->whereBetween('log_time', [$windowStart, $windowEnd]);
                });
        });
    }
}
