<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\UserPresenceLog;

class PresenceController extends Controller
{
    public function updateStatus(Request $request)
    {
        $request->validate([
            'status' => 'required|in:online,idle,offline,dnd',
        ]);

        $user = Auth::user();
        if ($user) {
            $user->updateStatus($request->status);
        }

        return response()->json(['success' => true]);
    }

    public function getActiveUsers()
    {
        if (!Auth::user()->can('presence.view')) {
            abort(403);
        }

        $users = User::with('lastPresenceLog')
            ->select('id', 'name', 'status', 'last_activity_at', 'sub_unit')
            ->get()
            ->map(function($user) {
                $duration = 0;
                if ($user->lastPresenceLog && !$user->lastPresenceLog->ended_at) {
                    $duration = (int) now()->diffInSeconds($user->lastPresenceLog->started_at);
                }
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'status' => $user->status,
                    'sub_unit' => $user->sub_unit ?: 'Unassigned',
                    'last_activity_at' => $user->last_activity_at,
                    'duration_current_status' => $duration,
                ];
            });

        return response()->json($users);
    }

    public function getUserStats(User $user)
    {
        if (!Auth::user()->can('presence.view')) {
            abort(403);
        }

        // 1. First login of the day
        $firstLogToday = UserPresenceLog::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->orderBy('started_at', 'asc')
            ->first();

        // 2. Find the start of the current "Active" cycle (the last time they were actually 'online')
        $lastOnlineLog = UserPresenceLog::where('user_id', $user->id)
            ->where('status', 'online')
            ->orderBy('started_at', 'desc')
            ->first();

        // 3. Calculate IDLE time since that last 'online' session
        // This ensures it resets to 0 when they go online, but persists if they go offline.
        $query = UserPresenceLog::where('user_id', $user->id)
            ->where('status', 'idle');
        
        if ($lastOnlineLog) {
            $query->where('started_at', '>', $lastOnlineLog->ended_at ?: $lastOnlineLog->started_at);
        }

        $idleLogs = $query->get();
        $idleSecondsSinceOnline = 0;
        foreach ($idleLogs as $log) {
            $start = $log->started_at;
            $end = $log->ended_at ?: now();
            if ($end->isAfter($start)) {
                $idleSecondsSinceOnline += (int) $end->diffInSeconds($start);
            }
        }

        // 4. Last Logout
        $lastLogoutLog = UserPresenceLog::where('user_id', $user->id)
            ->whereIn('status', ['online', 'idle'])
            ->whereNotNull('ended_at')
            ->orderBy('ended_at', 'desc')
            ->first();

        return response()->json([
            'first_login_today' => $firstLogToday ? $firstLogToday->started_at->toIso8601String() : null,
            'current_idle_seconds' => $idleSecondsSinceOnline,
            'last_logout_at' => $lastLogoutLog ? $lastLogoutLog->ended_at->toIso8601String() : null,
            'status' => $user->status
        ]);
    }
}