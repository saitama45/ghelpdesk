<?php

namespace App\Http\Controllers;

use App\Models\AgentPointTransaction;
use App\Models\Schedule;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    /**
     * Recent activity notifications (tickets / task boards / project tracker)
     * plus the ambient "reminders" (no schedule today, etc.). The bell badge
     * counts unread activity + active reminders.
     */
    public function summary(): JsonResponse
    {
        $user = auth()->user();

        $unread = $user->unreadNotifications()->count();

        // NB: the notifications() relation is already ordered latest-first.
        // Adding ->latest() here produces a duplicate ORDER BY that SQL Server rejects.
        $notifications = $user->notifications()
            ->limit(20)
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'domain'     => $n->data['domain'] ?? 'general',
                'event'      => $n->data['event'] ?? null,
                'title'      => $n->data['title'] ?? 'Notification',
                'message'    => $n->data['message'] ?? '',
                'actor_name' => $n->data['actor_name'] ?? null,
                'url'        => $n->data['url'] ?? null,
                'severity'   => $n->data['severity'] ?? 'info',
                'read'       => $n->read_at !== null,
                'created_at' => $n->created_at,
            ]);

        $reminders = $this->reminders($user);

        return response()->json([
            'notifications' => $notifications,
            'reminders'     => $reminders,
            'unread'        => $unread,
            'total'         => $unread + count($reminders),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if ($notification && $notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }

    /**
     * Ambient, always-recomputed reminders (not stored, not "read/unread").
     */
    private function reminders($user): array
    {
        $today = Carbon::today();
        $reminders = [];

        $hasSchedule = Schedule::where('user_id', $user->id)
            ->where('start_time', '<=', $today->copy()->endOfDay())
            ->where('end_time', '>=', $today->copy()->startOfDay())
            ->exists();

        if (!$hasSchedule) {
            $reminders[] = [
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
            $reminders[] = [
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
            $reminders[] = [
                'type'    => 'points',
                'title'   => 'Points Today',
                'message' => "+{$pointsToday} pts earned today",
                'severity' => 'success',
                'route'   => 'dashboard',
                'count'   => $pointsToday,
            ];
        }

        return $reminders;
    }
}
