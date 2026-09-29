<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Ticket;
use App\Support\TicketStatuses;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Picks who handles a new ticket from the schedules tagged for Ticket Duty on
 * /schedules.
 *
 * Pool: tagged schedules with a working status (On-site/Off-site/WFH) whose owner
 * is active and belongs to the ticket's serving department. A ticket with no
 * serving department uses the intake department from Settings → Auto Assignee
 * (blank = any department).
 *
 * Who: people on duty at the moment of intake. When nobody is, the people whose
 * duty shift starts next. When nobody has a shift at all, no one (the ticket
 * stays unassigned). Among several, "same store first" (optional) narrows to
 * whoever is scheduled at the ticket's store, then the fewest active tickets
 * wins, ties going to whoever received a ticket longest ago.
 */
class TicketDutyAssigner
{
    public function enabled(): bool
    {
        return (string) Setting::get('auto_assignee_duty_enabled', '0') === '1';
    }

    /**
     * @return array{assignee_id: int, reason: string}|null
     */
    public function pick(?int $servingDepartmentId, ?int $storeId = null, ?Carbon $at = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $at = ($at ?? now())->copy()->timezone(config('app.timezone'));
        $departmentId = $servingDepartmentId ?: $this->intakeDepartmentId();

        // Two tickets arriving together would otherwise both see the same loads
        // and land on the same person. The lock serialises the choice; the
        // stamp written inside it moves the tie-break on for the next caller.
        try {
            return Cache::lock('ticket-duty-pick:'.($departmentId ?: 'any'), 10)
                ->block(5, fn () => $this->choose($departmentId, $storeId, $at));
        } catch (LockTimeoutException) {
            return $this->choose($departmentId, $storeId, $at);
        }
    }

    private function choose(?int $departmentId, ?int $storeId, Carbon $at): ?array
    {
        $upcoming = false;
        $schedules = $this->dutySchedules($departmentId)
            ->where('start_time', '<=', $at)
            ->where('end_time', '>', $at)
            ->get();

        if ($schedules->isEmpty()) {
            $nextStart = $this->dutySchedules($departmentId)
                ->where('start_time', '>', $at)
                ->min('start_time');

            if (! $nextStart) {
                return null;
            }

            $upcoming = true;
            $schedules = $this->dutySchedules($departmentId)
                ->where('start_time', Carbon::parse($nextStart))
                ->get();
        }

        // One row per person (the one-per-day rule makes this a no-op, but rows
        // that predate it can still overlap).
        $schedules = $schedules->unique('user_id')->values();

        $atStore = false;
        if ($storeId && $this->storeFirst()) {
            $scheduledHere = $schedules->filter(fn (Schedule $schedule) => $schedule->scheduleStores->contains(
                fn ($entry) => (int) $entry->store_id === $storeId
                    && ($upcoming || ($entry->start_time <= $at && $entry->end_time > $at))
            ));

            if ($scheduledHere->isNotEmpty()) {
                $schedules = $scheduledHere->values();
                $atStore = true;
            }
        }

        $userIds = $schedules->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $loads = $this->activeTicketCounts($userIds);
        $lastAssigned = $this->lastAssignedAt($userIds);

        $chosen = $schedules->sort(function (Schedule $a, Schedule $b) use ($loads, $lastAssigned) {
            return [$loads[$a->user_id] ?? 0, $lastAssigned[$a->user_id] ?? 0.0, $a->user_id]
                <=> [$loads[$b->user_id] ?? 0, $lastAssigned[$b->user_id] ?? 0.0, $b->user_id];
        })->first();

        $userId = (int) $chosen->user_id;
        Cache::put($this->stampKey($userId), (float) now()->format('U.u'), now()->addDays(2));

        $load = $loads[$userId] ?? 0;
        $reason = $upcoming
            ? 'next on ticket duty, from '.$chosen->start_time->format('D M j, g:i A')
            : 'on ticket duty until '.$chosen->end_time->format('g:i A');
        if ($atStore) {
            $reason .= ', scheduled at this store';
        }
        $reason .= ', '.$load.' active '.($load === 1 ? 'ticket' : 'tickets');

        return ['assignee_id' => $userId, 'reason' => $reason];
    }

    private function dutySchedules(?int $departmentId): Builder
    {
        // Unscoped by entity: schedules are stamped with whatever entity their
        // creator was viewing, and the requester's entity switcher must not hide
        // the desk's roster. The desk (department) is the real boundary.
        return Schedule::withoutGlobalScope(ActiveEntityScope::class)
            ->select(['id', 'user_id', 'start_time', 'end_time'])
            ->with('scheduleStores:id,schedule_id,store_id,start_time,end_time')
            ->where('ticket_duty', true)
            ->whereIn('status', Schedule::TICKET_DUTY_STATUSES)
            ->whereHas('user', fn ($query) => $query
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId)));
    }

    /**
     * Tickets being worked or waiting to be picked up. Paused ones (waiting on
     * the client/partner, for schedule) are not load. Unscoped by entity: a
     * person's workload spans every entity they serve.
     *
     * @return array<int, int>
     */
    public function activeTicketCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->whereIn('assignee_id', $userIds)
            ->whereIn('status', TicketStatuses::like(['open', 'in_progress']))
            ->groupBy('assignee_id')
            ->selectRaw('assignee_id, COUNT(*) as active_count')
            ->pluck('active_count', 'assignee_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * When each person last received a ticket, as a Unix timestamp. The newest
     * ticket assigned to them is the durable record; the cache stamp covers
     * picks made in the last few seconds whose ticket is not committed yet.
     *
     * @return array<int, float>
     */
    private function lastAssignedAt(array $userIds): array
    {
        $fromTickets = Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->whereIn('assignee_id', $userIds)
            ->groupBy('assignee_id')
            ->selectRaw('assignee_id, MAX(created_at) as last_at')
            ->pluck('last_at', 'assignee_id');

        return collect($userIds)->mapWithKeys(function (int $userId) use ($fromTickets) {
            $ticketAt = $fromTickets[$userId] ?? null;

            return [$userId => max(
                $ticketAt ? (float) Carbon::parse($ticketAt)->getTimestamp() : 0.0,
                (float) Cache::get($this->stampKey($userId), 0.0),
            )];
        })->all();
    }

    private function storeFirst(): bool
    {
        return (string) Setting::get('auto_assignee_duty_store_first', '0') === '1';
    }

    private function intakeDepartmentId(): ?int
    {
        $id = (int) Setting::get('auto_assignee_duty_intake_department_id', 0);

        return $id > 0 ? $id : null;
    }

    private function stampKey(int $userId): string
    {
        return "ticket-duty:last-pick:{$userId}";
    }
}
