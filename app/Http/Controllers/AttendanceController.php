<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AttendanceController extends Controller implements HasMiddleware
{
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
        
        // Base query - load relationships for both segment-based and legacy logs
        $query = AttendanceLog::with(['user', 'scheduleStore.store', 'schedule.store'])->latest('log_time');

        // Privacy Logic: Show only own logs if not Admin/Dev/Manager
        if (!$user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin']) && !$user->is_manager) {
            $query->where('user_id', $user->id);
        }

        // Filter by Sub-Unit
        if ($request->filled('sub_unit')) {
            $query->whereHas('user', function($q) use ($request) {
                $q->where('sub_unit', $request->sub_unit);
            });
        }

        // Filter by Store
        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStore', fn($sq) => $sq->where('store_id', $request->store_id));
        }

        // Filter by Date Range (Default to today if not set)
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
        $users = User::active()->orderBy('name')->get(['id', 'name', 'sub_unit']);
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Attendance/Logs', [
            'logs' => $logs,
            'users' => $users,
            'stores' => $stores,
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

        if (!$user) {
            return back()->with('error', 'Unauthorized.');
        }

        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'required|string', // Base64 encoded image
        ]);

        $throttleKey = 'attendance-log:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->with('error', 'Too many attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.');
        }

        // Find the active schedule for this user right now.
        $now = now('Asia/Manila');
        [$schedule, $activeStoreEntry] = $this->resolveScheduleForAttendance($user->id, $now);

        if (!$schedule) {
            return back()->with('error', 'No active On-site, Off-site, or WFH schedule found for your current time. Please contact your supervisor.');
        }

        // GEOFENCING VALIDATION (Skip if WFH)
        if ($schedule->status !== 'WFH') {
            $userLat = $request->latitude;
            $userLng = $request->longitude;
            $store = $activeStoreEntry?->store;

            if (!$activeStoreEntry || !$store) {
                return back()->with('error', 'The active schedule has no store assigned. Please contact your supervisor.');
            }

            if ($store->latitude === null || $store->longitude === null) {
                return back()->with('error', "The active schedule store ({$store->name}) has no GPS coordinates configured. Please contact HR.");
            }

            $radius = $store->radius_meters ?: 100;
            $distance = $this->calculateDistance($userLat, $userLng, $store->latitude, $store->longitude);

            if ($distance > $radius) {
                return back()->with('error', "You are outside the active schedule store vicinity for {$store->name}. (" . round($distance) . "m away, allowed {$radius}m)");
            }

        }

        // Determine type per-segment if possible, otherwise per-schedule
        $lastLogQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $lastLog = $lastLogQuery->latest('log_time')->first();

        // Block if both time_in and time_out already exist for this schedule segment
        $segmentLogsQuery = $this->buildSegmentLogsQuery($user->id, $schedule, $activeStoreEntry, $now);
        $segmentLogs = $segmentLogsQuery->pluck('type');
        if ($segmentLogs->contains('time_in') && $segmentLogs->contains('time_out')) {
            return back()->with('error', 'You have already completed Time In and Time Out for this schedule. No further logging is allowed for this time frame.');
        }

        $type = (!$lastLog || $lastLog->type === 'time_out') ? 'time_in' : 'time_out';

        // Prevent duplicate logs within a short window (5 minutes)
        if ($lastLog && $lastLog->created_at->addMinutes(5)->isFuture()) {
            return back()->with('warning', 'A log was already recorded recently. Please wait a few minutes.');
        }

        // Handle Base64 Photo
        $photoData = $request->photo;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $typeMatch)) {
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
            $extension = strtolower($typeMatch[1]);

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                return back()->with('error', 'Invalid image type.');
            }

            $photoData = base64_decode($photoData);
            if ($photoData === false) {
                return back()->with('error', 'Base64 decode failed.');
            }
        } else {
            return back()->with('error', 'Invalid photo format.');
        }

        $fileName = 'attendance/' . $user->id . '/' . now()->timestamp . '_' . Str::random(10) . '.' . $extension;
        Storage::disk('public')->put($fileName, $photoData);

        AttendanceLog::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $activeStoreEntry?->id,
            'type' => $type,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'photo_path' => $fileName,
            'log_time' => now('Asia/Manila'),
            'device_info' => $request->input('device_info', $request->header('User-Agent')),
            'ip_address' => $request->input('public_ip', $request->ip()),
        ]);

        RateLimiter::clear($throttleKey);

        return redirect()->route('attendance.logs')->with('success', 'Successfully ' . ($type === 'time_in' ? 'Timed In' : 'Timed Out'));
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

        // Allow Time Out for an already-open same-day attendance session,
        // even if the strict active window is no longer matched.
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
