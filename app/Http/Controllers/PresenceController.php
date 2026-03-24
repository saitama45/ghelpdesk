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
                $status = $user->status;
                // If the user's last activity was more than 3 minutes ago, consider them offline
                if ($status !== 'offline' && $user->last_activity_at && $user->last_activity_at < now()->subMinutes(3)) {
                    $status = 'offline';
                }

                $duration = 0;
                if ($status !== 'offline' && $user->lastPresenceLog && !$user->lastPresenceLog->ended_at) {
                    $duration = (int) now()->diffInSeconds($user->lastPresenceLog->started_at);
                }
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'status' => $status,
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

        $status = $user->status;
        if ($status !== 'offline' && $user->last_activity_at && $user->last_activity_at < now()->subMinutes(3)) {
            $status = 'offline';
        }

        // 1. First login of the day
        $firstLogToday = UserPresenceLog::where('user_id', $user->id)
            ->whereDate('started_at', today())
            ->orderBy('started_at', 'asc')
            ->first();
            
        // Fallback: If no login today, but the user is active, find the earliest un-ended log
        if (!$firstLogToday && $status !== 'offline') {
            $firstLogToday = UserPresenceLog::where('user_id', $user->id)
                ->whereNull('ended_at')
                ->orderBy('started_at', 'asc')
                ->first();
        }

        // 2. Find the start of the current "Active" cycle (the last time they were actually 'online')
        $lastOnlineLog = UserPresenceLog::where('user_id', $user->id)
            ->where('status', 'online')
            ->orderBy('started_at', 'desc')
            ->first();

        // 3. Calculate IDLE time since that last 'online' session
        $query = UserPresenceLog::where('user_id', $user->id)
            ->where('status', 'idle');
        
        if ($lastOnlineLog) {
            // Using >= to ensure we don't miss logs created in the same second
            $query->where('started_at', '>=', $lastOnlineLog->ended_at ?: $lastOnlineLog->started_at);
        }

        $idleLogs = $query->get();
        $idleBaseSeconds = 0;
        $currentIdleStartedAt = null;

        foreach ($idleLogs as $log) {
            if ($log->ended_at) {
                $idleBaseSeconds += (int) $log->duration_seconds;
            } else {
                $currentIdleStartedAt = $log->started_at->toIso8601String();
            }
        }

        // 4. Last Logout
        $lastLogoutLog = UserPresenceLog::where('user_id', $user->id)
            ->whereIn('status', ['online', 'idle'])
            ->whereNotNull('ended_at')
            ->orderBy('ended_at', 'desc')
            ->first();

        $lastLogoutAt = $lastLogoutLog ? $lastLogoutLog->ended_at->toIso8601String() : null;
        
        if ($status === 'offline' && $user->status !== 'offline') {
            $lastLogoutAt = $user->last_activity_at ? $user->last_activity_at->toIso8601String() : null;
        }

        return response()->json([
            'first_login_today' => $firstLogToday ? $firstLogToday->started_at->toIso8601String() : null,
            'idle_base_seconds' => $idleBaseSeconds,
            'current_idle_started_at' => ($status === 'idle') ? $currentIdleStartedAt : null,
            'last_logout_at' => $lastLogoutAt,
            'status' => $status
        ]);
    }
}