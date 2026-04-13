<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Schedule;
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

        // Find the user's active On-site/Off-site schedule for the current time.
        // Grace period is per schedule_store; query with a generous max window then filter in PHP.
        $now = now('Asia/Manila');

        $todaySchedule = Schedule::where('user_id', $user->id)
            ->whereIn('status', ['On-site', 'Off-site'])
            ->where('start_time', '<=', $now->copy()->addMinutes(480))
            ->where('end_time', '>=', $now)
            ->with(['store', 'scheduleStores.store'])
            ->get()
            ->first(function ($s) use ($now) {
                return $s->scheduleStores->some(
                    fn ($ss) => $ss->start_time->lte($now->copy()->addMinutes((int)($ss->grace_period_minutes ?? 30)))
                              && $ss->end_time->gte($now)
                ) || $s->scheduleStores->isEmpty();
            });

        // Find the specific active store entry within the schedule
        $activeStoreEntry = null;
        if ($todaySchedule) {
            $activeStoreEntry = $todaySchedule->scheduleStores->first(function ($ss) use ($now) {
                return $ss->start_time->lte($now->copy()->addMinutes((int)($ss->grace_period_minutes ?? 30)))
                    && $ss->end_time->gte($now);
            });
        }

        // Scope lastLog to this schedule so a forgotten yesterday Time Out doesn't bleed into today
        $lastLog = $todaySchedule
            ? AttendanceLog::where('user_id', $user->id)
                ->where('schedule_id', $todaySchedule->id)
                ->latest('log_time')
                ->first()
            : null;

        return Inertia::render('Attendance/Index', [
            'lastLog' => $lastLog,
            'assignedStores' => $assignedStores,
            'totalAssignedCount' => $totalAssignedCount,
            'todaySchedule' => $todaySchedule ? [
                'id'         => $todaySchedule->id,
                'status'     => $todaySchedule->status,
                'start_time' => ($activeStoreEntry ? $activeStoreEntry->start_time : $todaySchedule->start_time)->toIso8601String(),
                'end_time'   => ($activeStoreEntry ? $activeStoreEntry->end_time : $todaySchedule->end_time)->toIso8601String(),
                'store'      => ($activeStoreEntry && $activeStoreEntry->store)
                    ? ['id' => $activeStoreEntry->store->id, 'name' => $activeStoreEntry->store->name]
                    : ($todaySchedule->store 
                        ? ['id' => $todaySchedule->store->id, 'name' => $todaySchedule->store->name]
                        : null),
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
            $query->where(function($q) use ($request) {
                $q->whereHas('scheduleStore', fn($sq) => $sq->where('store_id', $request->store_id))
                  ->orWhereHas('schedule', fn($sq) => $sq->where('store_id', $request->store_id));
            });
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

        // GEOFENCING VALIDATION
        $userLat = $request->latitude;
        $userLng = $request->longitude;

        $assignedStores = $user->stores()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('is_active', true)
            ->get();

        // Fallback for Admins/Devs
        if ($assignedStores->isEmpty() && $user->hasAnyRole(['Admin', 'Dev', 'Solutions Admin'])) {
            $assignedStores = Store::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('is_active', true)
                ->get();
        }

        if ($assignedStores->isEmpty()) {
            return back()->with('error', 'No active work site assigned to your account. Please contact HR.');
        }

        $isWithinVicinity = false;
        $closestDistance = null;

        foreach ($assignedStores as $store) {
            $distance = $this->calculateDistance($userLat, $userLng, $store->latitude, $store->longitude);
            if ($distance <= $store->radius_meters) {
                $isWithinVicinity = true;
                break;
            }
            if ($closestDistance === null || $distance < $closestDistance) {
                $closestDistance = $distance;
            }
        }

        if (!$isWithinVicinity) {
            return back()->with('error', "You are outside the allowed vicinity. (Closest site: " . round($closestDistance) . "m away)");
        }

        $throttleKey = 'attendance-log:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->with('error', 'Too many attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.');
        }

        // Find the active On-site/Off-site schedule for this user right now.
        // Grace period is per schedule_store; query with a generous max window then filter in PHP.
        $now = now('Asia/Manila');

        $schedule = Schedule::where('user_id', $user->id)
            ->whereIn('status', ['On-site', 'Off-site'])
            ->where('start_time', '<=', $now->copy()->addMinutes(480))
            ->where('end_time', '>=', $now)
            ->with('scheduleStores')
            ->get()
            ->first(function ($s) use ($now) {
                return $s->scheduleStores->some(
                    fn ($ss) => $ss->start_time->lte($now->copy()->addMinutes((int)($ss->grace_period_minutes ?? 30)))
                              && $ss->end_time->gte($now)
                ) || $s->scheduleStores->isEmpty(); // fallback for legacy schedules
            });

        if (!$schedule) {
            return back()->with('error', 'No active On-site or Off-site schedule found for your current time. Please contact your supervisor.');
        }

        // Find the specific active store entry
        $activeStoreEntry = $schedule->scheduleStores->first(function ($ss) use ($now) {
            return $ss->start_time->lte($now->copy()->addMinutes((int)($ss->grace_period_minutes ?? 30)))
                && $ss->end_time->gte($now);
        });

        // Determine type per-segment if possible, otherwise per-schedule
        $lastLogQuery = AttendanceLog::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id);
        
        if ($activeStoreEntry) {
            $lastLogQuery->where('schedule_store_id', $activeStoreEntry->id);
        }

        $lastLog = $lastLogQuery->latest('log_time')->first();

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
}
