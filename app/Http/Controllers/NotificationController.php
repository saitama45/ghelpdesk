<?php

namespace App\Http\Controllers;

use App\Models\AgentPointTransaction;
use App\Models\AttendanceLog;
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

        // Reminders that cover the user plus their direct reports (self first).
        $scopeUserIds = collect([(int) $user->id])
            ->merge($user->subordinates()->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->scheduleReminders($reminders, $user, $scopeUserIds);

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

        $this->slaReminders($reminders, $scopeUserIds);

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

    /**
     * Missing schedule (today) + unlogged time-in / time-out (yesterday + today
     * for time-in, yesterday only for time-out so in-progress shifts don't
     * false-positive) for the user and their direct reports.
     *
     * @param  array<int,array>  $reminders  built up by reference
     */
    private function scheduleReminders(array &$reminders, $user, $scopeUserIds): void
    {
        $tz = 'Asia/Manila';
        $today = Carbon::today($tz);
        $yesterday = $today->copy()->subDay();
        $todayStr = $today->toDateString();
        $yesterdayStr = $yesterday->toDateString();

        $rangeStart = $yesterday->copy()->startOfDay();
        $rangeEnd = $today->copy()->endOfDay();

        $schedules = Schedule::whereIn('user_id', $scopeUserIds)
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart)
            ->get(['id', 'user_id', 'status', 'start_time', 'end_time']);

        $logsBySchedule = AttendanceLog::whereIn('schedule_id', $schedules->pluck('id')->filter()->values())
            ->notVoided()
            ->get(['schedule_id', 'type', 'log_time'])
            ->groupBy('schedule_id');

        // Statuses where physical attendance (time in/out) is not expected.
        $optionalStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];

        $scheduledTodayUserIds = [];
        $missingTimeIn = [];   // keyed "userId|date" to dedupe overlapping schedules
        $missingTimeOut = [];

        foreach ($schedules as $s) {
            $sStartStr = $s->start_time->copy()->timezone($tz)->toDateString();
            $sEndStr = $s->end_time->copy()->timezone($tz)->toDateString();

            if ($sStartStr <= $todayStr && $todayStr <= $sEndStr) {
                $scheduledTodayUserIds[$s->user_id] = true;
            }

            if (in_array($s->status, $optionalStatuses, true)) {
                continue;
            }

            $logsByDate = $logsBySchedule->get($s->id, collect())
                ->groupBy(fn ($log) => $log->log_time?->copy()->timezone($tz)->toDateString());

            foreach ([$yesterdayStr, $todayStr] as $dateStr) {
                if ($dateStr < $sStartStr || $dateStr > $sEndStr) {
                    continue;
                }

                $daily = $logsByDate->get($dateStr, collect());
                $key = $s->user_id . '|' . $dateStr;

                if (!$daily->contains(fn ($log) => $log->type === 'time_in')) {
                    $missingTimeIn[$key] = true;
                }

                // Time-out is only expected once the day is over (yesterday).
                if ($dateStr === $yesterdayStr && !$daily->contains(fn ($log) => $log->type === 'time_out')) {
                    $missingTimeOut[$key] = true;
                }
            }
        }

        $missingScheduleUserIds = $scopeUserIds->diff(array_keys($scheduledTodayUserIds))->values();

        if ($missingScheduleUserIds->isNotEmpty()) {
            $selfOnly = $missingScheduleUserIds->count() === 1
                && (int) $missingScheduleUserIds->first() === (int) $user->id;

            $reminders[] = [
                'type'     => 'missing_schedule',
                'title'    => 'Missing Schedule',
                'message'  => $selfOnly
                    ? 'You have no schedule plotted for today.'
                    : $missingScheduleUserIds->count() . ' staff missing a schedule today.',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => $missingScheduleUserIds->count(),
            ];
        }

        if (!empty($missingTimeIn)) {
            $reminders[] = [
                'type'     => 'missing_time_in',
                'title'    => 'Missing Time-In',
                'message'  => count($missingTimeIn) . ' unlogged time-in (yesterday/today).',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => count($missingTimeIn),
            ];
        }

        if (!empty($missingTimeOut)) {
            $reminders[] = [
                'type'     => 'missing_time_out',
                'title'    => 'Missing Time-Out',
                'message'  => count($missingTimeOut) . ' unlogged time-out (yesterday).',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => count($missingTimeOut),
            ];
        }
    }

    /**
     * Resolution-SLA reminders for open tickets assigned to the user or their
     * direct reports: past-due (breach), due within 1 day, due within 2 days.
     *
     * @param  array<int,array>  $reminders  built up by reference
     */
    private function slaReminders(array &$reminders, $scopeUserIds): void
    {
        $now = Carbon::now();
        $in1Day = $now->copy()->addDay();
        $in2Days = $now->copy()->addDays(2);

        $base = fn () => Ticket::whereIn('tickets.assignee_id', $scopeUserIds->all())
            ->whereNotIn('tickets.status', ['resolved', 'closed'])
            ->join('ticket_sla_metrics as sm', 'sm.ticket_id', '=', 'tickets.id')
            ->whereNull('sm.resolved_at');

        $breached = $base()
            ->where(function ($q) use ($now) {
                $q->where('sm.is_resolution_breached', true)
                    ->orWhere('sm.resolution_target_at', '<', $now);
            })
            ->count();

        $dueIn1Day = $base()
            ->where('sm.is_resolution_breached', false)
            ->whereBetween('sm.resolution_target_at', [$now, $in1Day])
            ->count();

        $dueIn2Days = $base()
            ->where('sm.is_resolution_breached', false)
            ->where('sm.resolution_target_at', '>', $in1Day)
            ->where('sm.resolution_target_at', '<=', $in2Days)
            ->count();

        if ($breached > 0) {
            $reminders[] = [
                'type'     => 'sla_breached',
                'title'    => 'SLA Breached',
                'message'  => "{$breached} ticket(s) past due SLA.",
                'severity' => 'warning',
                'route'    => 'tickets.index',
                'count'    => $breached,
            ];
        }

        if ($dueIn1Day > 0) {
            $reminders[] = [
                'type'     => 'sla_due_1d',
                'title'    => 'SLA Due in 1 Day',
                'message'  => "{$dueIn1Day} ticket(s) breaching within 24 hours.",
                'severity' => 'warning',
                'route'    => 'tickets.index',
                'count'    => $dueIn1Day,
            ];
        }

        if ($dueIn2Days > 0) {
            $reminders[] = [
                'type'     => 'sla_due_2d',
                'title'    => 'SLA Due in 2 Days',
                'message'  => "{$dueIn2Days} ticket(s) breaching within 2 days.",
                'severity' => 'info',
                'route'    => 'tickets.index',
                'count'    => $dueIn2Days,
            ];
        }
    }
}
