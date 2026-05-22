<?php

namespace App\Http\Controllers;

use App\Models\AgentPointTransaction;
use App\Models\Schedule;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function summary(): JsonResponse
    {
        $user = auth()->user();
        $today = Carbon::today();
        $notifications = [];

        $hasSchedule = Schedule::where('user_id', $user->id)
            ->where('start_time', '<=', $today->copy()->endOfDay())
            ->where('end_time', '>=', $today->copy()->startOfDay())
            ->exists();

        if (!$hasSchedule) {
            $notifications[] = [
                'type'    => 'schedule',
                'title'   => 'No Schedule Today',
                'message' => 'You have no schedule plotted for today.',
                'severity' => 'warning',
                'route'   => 'schedules.index',
                'count'   => null,
            ];
        }

        $openTickets = Ticket::where('assignee_id', $user->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        if ($openTickets > 0) {
            $notifications[] = [
                'type'    => 'tickets',
                'title'   => 'Assigned Tickets',
                'message' => "{$openTickets} open ticket(s) assigned to you",
                'severity' => 'info',
                'route'   => 'tickets.index',
                'count'   => $openTickets,
            ];
        }

        $pointsToday = (int) AgentPointTransaction::where('agent_id', $user->id)
            ->where('awarded_at', '>=', $today->copy()->startOfDay())
            ->where('awarded_at', '<=', $today->copy()->endOfDay())
            ->sum('points');

        if ($pointsToday > 0) {
            $notifications[] = [
                'type'    => 'points',
                'title'   => 'Points Today',
                'message' => "+{$pointsToday} pts earned today",
                'severity' => 'success',
                'route'   => 'dashboard',
                'count'   => $pointsToday,
            ];
        }

        return response()->json([
            'notifications' => $notifications,
            'total'         => count($notifications),
        ]);
    }
}
