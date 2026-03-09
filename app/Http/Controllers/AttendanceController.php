<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
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

    public function index()
    {
        $user = auth()->user();
        return Inertia::render('Attendance/Index', [
            'lastLog' => $user->lastAttendanceLog,
            'assignedStores' => $user->stores()
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->where('is_active', true)
                ->get()
        ]);
    }

    public function logs()
    {
        $logs = auth()->user()->attendanceLogs()
            ->with('user')
            ->latest('log_time')
            ->paginate(20);

        return Inertia::render('Attendance/Logs', [
            'logs' => $logs
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

        // Determine type (Toggle logic: if last was time_in, this is time_out)
        $lastLog = $user->lastAttendanceLog;
        $type = (!$lastLog || $lastLog->type === 'time_out') ? 'time_in' : 'time_out';

        // Optional: Prevent duplicate logs within a short window (e.g., 5 minutes)
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
