<?php

namespace App\Http\Controllers;

use App\Models\AgentPointTransaction;
use App\Models\AttendanceLog;
use App\Models\NotificationReminderRead;
use App\Models\Schedule;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApprovalNotificationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class NotificationController extends Controller
{
    /**
     * Recent activity notifications (tickets / task boards / project tracker)
     * plus the ambient "reminders" (no schedule today, etc.). The bell badge
     * counts unread activity + unread reminders.
     */
    public function summary(): JsonResponse
    {
        $user = auth()->user();

        // NB: the notifications() relation is already ordered latest-first.
        // Adding ->latest() here produces a duplicate ORDER BY that SQL Server rejects.
        $listed = $user->notifications()->limit(20)->get();
        $resolved = $this->resolvedApprovals($user, $listed);
        $resolvedIds = $resolved->pluck('id')->flip();

        $unread = $user->unreadNotifications()->count()
            - $resolved->whereNull('read_at')->count();

        $notifications = $listed
            ->reject(fn ($n) => $resolvedIds->has($n->id))
            ->values()
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

        $reminders = $this->withReadState($user, $this->reminders($user));
        $unreadReminders = collect($reminders)->where('read', false)->count();

        return response()->json([
            'notifications'    => $notifications,
            'reminders'        => $reminders,
            'unread'           => $unread,
            'unread_reminders' => $unreadReminders,
            'total'            => $unread + $unreadReminders,
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

    /**
     * Marks every activity notification read and acknowledges every reminder
     * showing right now (Assigned Tickets, SLA Breached…), so the badge clears.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        // One UPDATE, not a load-and-save round trip per unread row.
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->acknowledgeReminders($user, $this->reminders($user));

        return response()->json(['unread' => 0]);
    }

    public function markReminderRead(Request $request, string $type): JsonResponse
    {
        $user = $request->user();
        $reminder = collect($this->reminders($user))->firstWhere('type', $type);

        if ($reminder) {
            $this->acknowledgeReminders($user, [$reminder]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * "Needs approval" notifications that no longer need this user — see
     * ApprovalNotificationResolver. Candidates are the listed rows plus every
     * unread approval, so the count stays exact even when a stale one sits
     * below the first 20.
     */
    private function resolvedApprovals($user, Collection $listed): Collection
    {
        $candidates = $listed->merge(
            $user->unreadNotifications()
                ->where('data->domain', 'approval')
                // `received`: accounting review pings sent before they used `pending`.
                ->whereIn('data->event', ['pending', 'received'])
                ->get()
        );

        return app(ApprovalNotificationResolver::class)->resolved($candidates);
    }

    /**
     * A reminder reads as read once the user has acknowledged every item it
     * lists now; a ticket or date outside that set makes it unread again.
     * `items` is internal and dropped from the response.
     */
    private function withReadState($user, array $reminders): array
    {
        $acknowledged = NotificationReminderRead::where('user_id', $user->id)
            ->get(['type', 'items'])
            ->keyBy('type');

        return array_map(function (array $reminder) use ($acknowledged) {
            $seen = $acknowledged->get($reminder['type'])?->items;
            $reminder['read'] = is_array($seen) && array_diff($reminder['items'], $seen) === [];
            unset($reminder['items']);

            return $reminder;
        }, $reminders);
    }

    private function acknowledgeReminders($user, array $reminders): void
    {
        foreach ($reminders as $reminder) {
            NotificationReminderRead::updateOrCreate(
                ['user_id' => $user->id, 'type' => $reminder['type']],
                ['items' => array_values($reminder['items']), 'read_at' => now()]
            );
        }
    }

    /**
     * Ambient, always-recomputed reminders (not stored). Each carries `items` —
     * the tickets / user-dates it covers — which drive its read state.
     */
    private function reminders($user): array
    {
        $today = Carbon::today();
        $reminders = [];

        // Team-wide reminders (missing schedule / time logs, SLA) cover the user
        // plus their direct reports, but only managers see the team view — a plain
        // user only ever sees their own reminders.
        $scopeUserIds = collect([(int) $user->id]);

        if ($user->is_manager) {
            $scopeUserIds = $scopeUserIds->merge($user->subordinates()->pluck('id'));
        }

        // Exclude inactive users and vacant-position placeholders (created only to
        // fill the org chart) from every reminder — they never log in and should
        // never surface in "missing schedule / time" or any other notification.
        $scopeUserIds = User::whereIn('id', $scopeUserIds->map(fn ($id) => (int) $id)->unique()->all())
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $this->scheduleReminders($reminders, $scopeUserIds);

        $openTicketIds = Ticket::where('assignee_id', $user->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->pluck('id');
        $openTickets = $openTicketIds->count();

        if ($openTickets > 0) {
            $reminders[] = [
                'type'    => 'tickets',
                'title'   => 'Assigned Tickets',
                'message' => "{$openTickets} open ticket(s) assigned to you",
                'severity' => 'info',
                'route'   => 'tickets.index',
                'count'   => $openTickets,
                'items'   => $openTicketIds->map(fn ($id) => (string) $id)->all(),
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
                'items'   => [$today->toDateString() . ':' . $pointsToday],
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
    private function scheduleReminders(array &$reminders, $scopeUserIds): void
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

        // Statuses where physical attendance (time in/out) is not expected.
        $optionalStatuses = ['SL', 'VL', 'Restday', 'Holiday', 'N/A'];

        $scheduledTodayUserIds = [];
        $expectedPunchDates = [];   // keyed "userId|date" to dedupe overlapping schedules

        foreach ($schedules as $s) {
            $sStartStr = $s->start_time->copy()->timezone($tz)->toDateString();
            $sEndStr = $s->end_time->copy()->timezone($tz)->toDateString();

            if ($sStartStr <= $todayStr && $todayStr <= $sEndStr) {
                $scheduledTodayUserIds[$s->user_id] = true;
            }

            if (in_array($s->status, $optionalStatuses, true)) {
                continue;
            }

            foreach ([$yesterdayStr, $todayStr] as $dateStr) {
                if ($dateStr < $sStartStr || $dateStr > $sEndStr) {
                    continue;
                }

                $expectedPunchDates[$s->user_id . '|' . $dateStr] = true;
            }
        }

        // Punches are matched per user/date, not per schedule: a day is often
        // covered by several schedule rows (an On-site row plus a WFH row, a
        // re-issued shift), and a punch only ever attaches to one of them. Asking
        // each schedule for its own logs made every sibling report the day as
        // missing even though the user had clearly timed in.
        $loggedTypes = [];

        if (!empty($expectedPunchDates)) {
            AttendanceLog::whereIn('user_id', $scopeUserIds)
                ->notVoided()
                ->whereBetween('log_time', [$rangeStart, $rangeEnd])
                ->get(['user_id', 'type', 'log_time'])
                ->each(function ($log) use (&$loggedTypes, $tz) {
                    $dateStr = $log->log_time?->copy()->timezone($tz)->toDateString();

                    if ($dateStr !== null) {
                        $loggedTypes[$log->user_id . '|' . $dateStr][$log->type] = true;
                    }
                });
        }

        $missingTimeIn = [];
        $missingTimeOut = [];

        foreach (array_keys($expectedPunchDates) as $key) {
            [, $dateStr] = explode('|', $key, 2);

            if (!isset($loggedTypes[$key]['time_in'])) {
                $missingTimeIn[$key] = true;
            }

            // Time-out is only expected once the day is over (yesterday).
            if ($dateStr === $yesterdayStr && !isset($loggedTypes[$key]['time_out'])) {
                $missingTimeOut[$key] = true;
            }
        }

        $missingScheduleUserIds = $scopeUserIds->diff(array_keys($scheduledTodayUserIds))->values();
        $namesByUserId = User::whereIn('id', $scopeUserIds)
            ->pluck('name', 'id');
        $userName = fn ($userId) => $namesByUserId->get((int) $userId, "User {$userId}");

        if ($missingScheduleUserIds->isNotEmpty()) {
            $missingScheduleNames = $missingScheduleUserIds
                ->map($userName)
                ->sortBy(fn ($name) => mb_strtolower($name))
                ->values();

            $reminders[] = [
                'type'     => 'missing_schedule',
                'title'    => 'Missing Schedule',
                'message'  => 'No schedule today: ' . $missingScheduleNames->implode(', ') . '.',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => $missingScheduleUserIds->count(),
                'items'    => $missingScheduleUserIds->map(fn ($id) => $id . '|' . $todayStr)->all(),
            ];
        }

        if (!empty($missingTimeIn)) {
            $missingTimeInDetails = collect(array_keys($missingTimeIn))
                ->map(function ($key) use ($userName, $todayStr) {
                    [$userId, $date] = explode('|', $key, 2);

                    return [
                        'name' => $userName($userId),
                        'date' => $date,
                        'label' => $date === $todayStr ? 'Today' : 'Yesterday',
                    ];
                })
                ->sortBy(fn ($entry) => $entry['date'] . '|' . mb_strtolower($entry['name']))
                ->map(fn ($entry) => "{$entry['name']} ({$entry['label']})")
                ->values();

            $reminders[] = [
                'type'     => 'missing_time_in',
                'title'    => 'Missing Time-In',
                'message'  => 'No time-in: ' . $missingTimeInDetails->implode(', ') . '.',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => count($missingTimeIn),
                'items'    => array_map('strval', array_keys($missingTimeIn)),
            ];
        }

        if (!empty($missingTimeOut)) {
            $missingTimeOutNames = collect(array_keys($missingTimeOut))
                ->map(fn ($key) => $userName(explode('|', $key, 2)[0]))
                ->sortBy(fn ($name) => mb_strtolower($name))
                ->values();

            $reminders[] = [
                'type'     => 'missing_time_out',
                'title'    => 'Missing Time-Out',
                'message'  => 'No time-out yesterday: ' . $missingTimeOutNames->implode(', ') . '.',
                'severity' => 'warning',
                'route'    => 'schedules.index',
                'params'   => ['tab' => 'missing-schedules'],
                'count'    => count($missingTimeOut),
                'items'    => array_map('strval', array_keys($missingTimeOut)),
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
            ->orderBy('tickets.ticket_key')
            ->pluck('tickets.ticket_key');

        $dueIn1Day = $base()
            ->where('sm.is_resolution_breached', false)
            ->whereBetween('sm.resolution_target_at', [$now, $in1Day])
            ->orderBy('tickets.ticket_key')
            ->pluck('tickets.ticket_key');

        $dueIn2Days = $base()
            ->where('sm.is_resolution_breached', false)
            ->where('sm.resolution_target_at', '>', $in1Day)
            ->where('sm.resolution_target_at', '<=', $in2Days)
            ->orderBy('tickets.ticket_key')
            ->pluck('tickets.ticket_key');

        if ($breached->isNotEmpty()) {
            $reminders[] = [
                'type'     => 'sla_breached',
                'title'    => 'SLA Breached',
                'message'  => 'Past due SLA: ' . $breached->implode(', ') . '.',
                'severity' => 'warning',
                'route'    => 'tickets.index',
                'params'   => $this->slaTicketParams($breached),
                'count'    => $breached->count(),
                'items'    => $breached->map(fn ($key) => (string) $key)->all(),
            ];
        }

        if ($dueIn1Day->isNotEmpty()) {
            $reminders[] = [
                'type'     => 'sla_due_1d',
                'title'    => 'SLA Due in 1 Day',
                'message'  => 'Due within 24 hours: ' . $dueIn1Day->implode(', ') . '.',
                'severity' => 'warning',
                'route'    => 'tickets.index',
                'params'   => $this->slaTicketParams($dueIn1Day),
                'count'    => $dueIn1Day->count(),
                'items'    => $dueIn1Day->map(fn ($key) => (string) $key)->all(),
            ];
        }

        if ($dueIn2Days->isNotEmpty()) {
            $reminders[] = [
                'type'     => 'sla_due_2d',
                'title'    => 'SLA Due in 2 Days',
                'message'  => 'Due within 2 days: ' . $dueIn2Days->implode(', ') . '.',
                'severity' => 'info',
                'route'    => 'tickets.index',
                'params'   => $this->slaTicketParams($dueIn2Days),
                'count'    => $dueIn2Days->count(),
                'items'    => $dueIn2Days->map(fn ($key) => (string) $key)->all(),
            ];
        }
    }

    private function slaTicketParams($ticketKeys): array
    {
        return [
            'ticket_keys' => $ticketKeys->implode(','),
            'status' => ['all'],
            'ticket_scope' => 'all',
            'skip_default_department' => 1,
        ];
    }
}
