<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringSchedulePlannerService
{
    public const REQUEST_TYPE_RECURRING_REPLACEMENT = 'recurring_plan_replacement';

    private const TIMEZONE = 'Asia/Manila';

    private const LOCATION_OPTIONAL_STATUSES = ['SL', 'VL', 'Restday', 'Holiday', 'Offset', 'N/A'];

    public function preview(array $payload, array $approverIdsByUser): array
    {
        return $this->buildPlan($payload, $approverIdsByUser, false);
    }

    public function save(array $payload, array $approverIdsByUser, int $actorId): array
    {
        return DB::transaction(function () use ($payload, $approverIdsByUser, $actorId) {
            $plan = $this->buildPlan($payload, $approverIdsByUser, true);
            $excludedKeys = collect($payload['excluded_keys'] ?? [])->map(fn ($key) => (string) $key)->flip();
            $counts = ['created' => 0, 'pending_approval' => 0, 'protected' => 0, 'excluded' => 0];
            $requestIds = [];

            foreach ($plan['entries'] as $entry) {
                if ($entry['action'] === 'protected') {
                    $counts['protected']++;
                    continue;
                }

                if ($excludedKeys->has($entry['key'])) {
                    $counts['excluded']++;
                    continue;
                }

                if ($entry['action'] === 'approval') {
                    $requestIds[] = $this->createReplacementRequest(
                        $entry,
                        $actorId,
                        $approverIdsByUser[(int) $entry['user_id']] ?? []
                    )->id;
                    $counts['pending_approval']++;
                    continue;
                } else {
                    $counts['created']++;
                }

                $this->createSchedule($entry, $actorId);
            }

            $counts['request_ids'] = $requestIds;

            return $counts;
        });
    }

    public function approveReplacementRequest(ScheduleChangeRequest $changeRequest, int $actorId): void
    {
        $payload = $changeRequest->requested_payload ?? [];
        $scheduleIds = collect($payload['recurring_replace_schedule_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $date = $payload['recurring_replace_date'] ?? null;

        if (! $date || $scheduleIds->isEmpty()) {
            throw ValidationException::withMessages(['request' => 'This recurring replacement request is incomplete.']);
        }

        $schedules = Schedule::with('scheduleStores.ticket')->whereIn('id', $scheduleIds)->lockForUpdate()->get();
        $storeIds = $schedules->flatMap(fn (Schedule $schedule) => $schedule->scheduleStores->pluck('id'));
        $hasAttendance = AttendanceLog::query()->notVoided()->where(function ($query) use ($scheduleIds, $storeIds) {
            $query->whereIn('schedule_id', $scheduleIds);
            if ($storeIds->isNotEmpty()) {
                $query->orWhereIn('schedule_store_id', $storeIds);
            }
        })->exists();

        if ($hasAttendance || $schedules->contains(fn (Schedule $schedule) => $schedule->scheduleStores->contains(fn ($segment) => $segment->ticket_id))) {
            throw ValidationException::withMessages([
                'request' => 'The existing schedule now has attendance or a linked ticket and can no longer be replaced.',
            ]);
        }

        $entry = [
            'user_id' => (int) $payload['user_id'],
            'status' => $payload['status'],
            'store_id' => $payload['stores'][0]['store_id'] ?? null,
            'start_time' => $payload['stores'][0]['start_time'],
            'end_time' => $payload['stores'][0]['end_time'],
            'grace_period_minutes' => $payload['stores'][0]['grace_period_minutes'] ?? 30,
            'remarks' => $payload['stores'][0]['remarks'] ?? null,
        ];
        $newSchedule = $this->createSchedule($entry, $actorId);
        $changeRequest->update(['schedule_id' => $newSchedule->id]);

        foreach ($scheduleIds as $scheduleId) {
            $this->removeScheduleDate($scheduleId, $date);
        }
    }

    private function buildPlan(array $payload, array $approverIdsByUser, bool $lock): array
    {
        [$periodStart, $periodEnd] = $this->periodBounds($payload);
        $rulesByWeekday = $this->normalizeRules($payload['rules']);
        $userIds = collect($payload['user_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->get(['id', 'name'])
            ->keyBy('id');

        if ($users->count() !== $userIds->count()) {
            throw ValidationException::withMessages(['user_ids' => 'One or more selected employees are unavailable.']);
        }

        $scheduleQuery = Schedule::query()
            ->with(['scheduleStores.ticket:id', 'changeRequests:id,schedule_id,status'])
            ->whereIn('user_id', $userIds)
            ->where('start_time', '<=', $periodEnd)
            ->where('end_time', '>=', $periodStart);

        if ($lock) {
            $scheduleQuery->lockForUpdate();
        }

        $schedules = $scheduleQuery->get();
        $scheduleIds = $schedules->pluck('id');
        $storeScheduleMap = $schedules->flatMap(fn (Schedule $schedule) => $schedule->scheduleStores->mapWithKeys(
            fn ($segment) => [(int) $segment->id => (int) $schedule->id]
        ));
        $attendanceScheduleIds = AttendanceLog::query()
            ->notVoided()
            ->where(function ($query) use ($scheduleIds, $storeScheduleMap) {
                $query->whereIn('schedule_id', $scheduleIds);
                if ($storeScheduleMap->isNotEmpty()) {
                    $query->orWhereIn('schedule_store_id', $storeScheduleMap->keys());
                }
            })
            ->get(['schedule_id', 'schedule_store_id'])
            ->map(fn (AttendanceLog $log) => (int) ($log->schedule_id ?: $storeScheduleMap->get((int) $log->schedule_store_id)))
            ->filter()
            ->flip();
        $pendingRequestScheduleIds = ScheduleChangeRequest::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->where('status', 'pending')
            ->pluck('schedule_id')
            ->map(fn ($id) => (int) $id)
            ->flip();
        $schedulesByUser = $schedules->groupBy(fn (Schedule $schedule) => (int) $schedule->user_id);
        $entries = [];

        foreach ($users as $user) {
            $userSchedules = $schedulesByUser->get((int) $user->id, collect());
            for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
                $rule = $rulesByWeekday->get($date->dayOfWeekIso);
                if (! $rule) {
                    continue;
                }

                $dateKey = $date->toDateString();
                $conflicts = $userSchedules
                    ->filter(fn (Schedule $schedule) => $this->scheduleTouchesDate($schedule, $dateKey))
                    ->values();
                [$action, $reason] = $this->classify(
                    $conflicts,
                    ! empty($approverIdsByUser[(int) $user->id] ?? []),
                    $attendanceScheduleIds,
                    $pendingRequestScheduleIds
                );
                [$startTime, $endTime] = $this->candidateTimes($dateKey, $rule);

                $entries[] = [
                    'key' => $user->id.'|'.$dateKey,
                    'user_id' => (int) $user->id,
                    'user_name' => $user->name,
                    'date' => $dateKey,
                    'weekday' => $date->format('D'),
                    'status' => $rule['status'],
                    'store_id' => $rule['store_id'],
                    'store_name' => $rule['store_name'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'grace_period_minutes' => $rule['grace_period_minutes'],
                    'remarks' => $rule['remarks'],
                    'action' => $action,
                    'protected_reason' => $reason,
                    'existing_statuses' => $conflicts->pluck('status')->unique()->values()->all(),
                    'existing_schedule_ids' => $conflicts->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ];
            }
        }

        $entryCollection = collect($entries);
        $entryCollection->groupBy('user_id')->each(function (Collection $userEntries) {
            $lastEnd = null;
            foreach ($userEntries->sortBy('start_time') as $entry) {
                $start = Carbon::parse($entry['start_time'], self::TIMEZONE);
                $end = Carbon::parse($entry['end_time'], self::TIMEZONE);
                if ($lastEnd && $start->lte($lastEnd)) {
                    throw ValidationException::withMessages([
                        'rules' => 'The selected weekly rules create overlapping shifts. Adjust the affected start or end time.',
                    ]);
                }
                $lastEnd = $end;
            }
        });

        return [
            'period_type' => $payload['period_type'],
            'period' => $payload['period_type'] === 'year' ? $periodStart->format('Y') : $periodStart->format('Y-m'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'entries' => $entries,
            'counts' => [
                'total' => count($entries),
                'create' => $entryCollection->where('action', 'create')->count(),
                'approval' => $entryCollection->where('action', 'approval')->count(),
                'protected' => $entryCollection->where('action', 'protected')->count(),
            ],
        ];
    }

    private function periodBounds(array $payload): array
    {
        if (($payload['period_type'] ?? 'month') === 'year') {
            $year = (int) $payload['year'];

            return [
                Carbon::create($year, 1, 1, 0, 0, 0, self::TIMEZONE)->startOfYear(),
                Carbon::create($year, 12, 31, 23, 59, 59, self::TIMEZONE)->endOfYear(),
            ];
        }

        $month = $payload['month'];
        try {
            $start = Carbon::createFromFormat('!Y-m', $month, self::TIMEZONE);
        } catch (\Throwable) {
            $start = false;
        }

        if (! $start || $start->format('Y-m') !== $month) {
            throw ValidationException::withMessages(['month' => 'Choose a valid calendar month.']);
        }

        return [$start->startOfMonth(), $start->copy()->endOfMonth()];
    }

    private function normalizeRules(array $rules): Collection
    {
        $normalized = collect();

        foreach ($rules as $index => $rule) {
            $status = $rule['status'];
            $locationOptional = in_array($status, self::LOCATION_OPTIONAL_STATUSES, true);
            $storeId = $locationOptional ? null : ($rule['store_id'] ?? null);

            if (! $locationOptional && ! $storeId) {
                throw ValidationException::withMessages(["rules.{$index}.store_id" => 'Location is required for working schedules.']);
            }

            if ($status !== 'Restday' && empty($rule['start_time'])) {
                throw ValidationException::withMessages(["rules.{$index}.start_time" => 'Start time is required.']);
            }

            if ($status !== 'Restday' && empty($rule['end_time'])) {
                throw ValidationException::withMessages(["rules.{$index}.end_time" => 'End time is required.']);
            }

            foreach ($rule['weekdays'] as $weekday) {
                $weekday = (int) $weekday;
                if ($normalized->has($weekday)) {
                    throw ValidationException::withMessages(['rules' => 'Each weekday can belong to only one rule.']);
                }

                $normalized->put($weekday, [
                    'status' => $status,
                    'store_id' => $storeId ? (int) $storeId : null,
                    'store_name' => $storeId ? Store::query()->whereKey($storeId)->value('name') : null,
                    'start_time' => $rule['start_time'] ?? null,
                    'end_time' => $rule['end_time'] ?? null,
                    'grace_period_minutes' => (int) ($rule['grace_period_minutes'] ?? 30),
                    'remarks' => trim((string) ($rule['remarks'] ?? '')) ?: null,
                ]);
            }
        }

        return $normalized;
    }

    private function scheduleTouchesDate(Schedule $schedule, string $date): bool
    {
        $dayStart = Carbon::parse($date, self::TIMEZONE)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $segments = $schedule->scheduleStores;

        if ($segments->isNotEmpty()) {
            return $segments->contains(fn ($segment) => $segment->start_time->lte($dayEnd) && $segment->end_time->gte($dayStart));
        }

        return $schedule->start_time->lte($dayEnd) && $schedule->end_time->gte($dayStart);
    }

    private function classify(Collection $conflicts, bool $hasManagerApprover, Collection $attendanceIds, Collection $pendingRequestIds): array
    {
        if ($conflicts->isEmpty()) {
            return ['create', null];
        }

        if (! $hasManagerApprover) {
            return ['protected', 'No eligible manager is available to approve this replacement.'];
        }

        if ($conflicts->contains(fn ($schedule) => $attendanceIds->has((int) $schedule->id))) {
            return ['protected', 'Attendance has already been recorded.'];
        }

        if ($conflicts->contains(fn ($schedule) => $schedule->scheduleStores->contains(fn ($segment) => $segment->ticket_id))) {
            return ['protected', 'A ticket is linked to this schedule.'];
        }

        if ($conflicts->contains(fn ($schedule) => $pendingRequestIds->has((int) $schedule->id))) {
            return ['protected', 'A schedule change request is pending.'];
        }

        if ($conflicts->contains(fn ($schedule) => $schedule->scheduleStores->isEmpty() && $schedule->start_time->toDateString() !== $schedule->end_time->toDateString())) {
            return ['protected', 'This legacy schedule spans multiple dates and must be edited manually.'];
        }

        return ['approval', null];
    }

    private function candidateTimes(string $date, array $rule): array
    {
        if ($rule['status'] === 'Restday') {
            return [$date.'T00:00', $date.'T23:59'];
        }

        $start = Carbon::parse($date.' '.$rule['start_time'], self::TIMEZONE);
        $end = Carbon::parse($date.' '.$rule['end_time'], self::TIMEZONE);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return [$start->format('Y-m-d\TH:i'), $end->format('Y-m-d\TH:i')];
    }

    private function createReplacementRequest(array $entry, int $actorId, array $approverIds): ScheduleChangeRequest
    {
        $anchorSchedule = Schedule::with('scheduleStores')->findOrFail($entry['existing_schedule_ids'][0]);
        $requestedPayload = [
            'user_id' => $entry['user_id'],
            'status' => $entry['status'],
            'stores' => [[
                'store_id' => $entry['store_id'],
                'ticket_id' => null,
                'start_time' => $entry['start_time'],
                'end_time' => $entry['end_time'],
                'grace_period_minutes' => $entry['grace_period_minutes'],
                'remarks' => $entry['remarks'],
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
            'scope_date' => $entry['date'],
            'recurring_replace_date' => $entry['date'],
            'recurring_replace_schedule_ids' => $entry['existing_schedule_ids'],
        ];

        return ScheduleChangeRequest::create([
            'schedule_id' => $anchorSchedule->id,
            'requester_id' => $actorId,
            'request_type' => self::REQUEST_TYPE_RECURRING_REPLACEMENT,
            'assigned_approver_ids' => array_values(array_unique(array_map('intval', $approverIds))),
            'status' => 'pending',
            'original_payload' => $this->schedulePayload($anchorSchedule),
            'requested_payload' => $requestedPayload,
            'requester_remarks' => 'Replacement proposed through schedule planning for '.$entry['date'].'.',
        ]);
    }

    private function schedulePayload(Schedule $schedule): array
    {
        return [
            'user_id' => (int) $schedule->user_id,
            'status' => $schedule->status,
            'pickup_start' => $schedule->pickup_start ? substr($schedule->pickup_start, 0, 5) : null,
            'pickup_end' => $schedule->pickup_end ? substr($schedule->pickup_end, 0, 5) : null,
            'backlogs_start' => $schedule->backlogs_start ? substr($schedule->backlogs_start, 0, 5) : null,
            'backlogs_end' => $schedule->backlogs_end ? substr($schedule->backlogs_end, 0, 5) : null,
            'stores' => $schedule->scheduleStores->map(fn ($segment) => [
                'id' => $segment->id,
                'store_id' => $segment->store_id,
                'ticket_id' => $segment->ticket_id,
                'start_time' => $segment->start_time?->toIso8601String(),
                'end_time' => $segment->end_time?->toIso8601String(),
                'grace_period_minutes' => $segment->grace_period_minutes ?? 30,
                'remarks' => $segment->remarks,
            ])->values()->all(),
            'scope_date' => null,
        ];
    }

    private function removeScheduleDate(int $scheduleId, string $date): void
    {
        $schedule = Schedule::with('scheduleStores')->find($scheduleId);
        if (! $schedule) {
            return;
        }

        if ($schedule->scheduleStores->isEmpty()) {
            $schedule->delete();
            return;
        }

        $dayStart = Carbon::parse($date, self::TIMEZONE)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $segmentIds = $schedule->scheduleStores
            ->filter(fn ($segment) => $segment->start_time->lte($dayEnd) && $segment->end_time->gte($dayStart))
            ->pluck('id');
        $schedule->scheduleStores()->whereIn('id', $segmentIds)->delete();
        $remaining = $schedule->scheduleStores()->get();

        if ($remaining->isEmpty()) {
            $schedule->delete();
            return;
        }

        $schedule->update([
            'start_time' => $remaining->min('start_time'),
            'end_time' => $remaining->max('end_time'),
        ]);
    }

    private function createSchedule(array $entry, int $actorId): Schedule
    {
        $schedule = Schedule::create([
            'user_id' => $entry['user_id'],
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'status' => $entry['status'],
            'start_time' => $entry['start_time'],
            'end_time' => $entry['end_time'],
            'remarks' => $entry['remarks'],
        ]);

        $schedule->scheduleStores()->create([
            'store_id' => $entry['store_id'],
            'start_time' => $entry['start_time'],
            'end_time' => $entry['end_time'],
            'grace_period_minutes' => $entry['grace_period_minutes'],
            'remarks' => $entry['remarks'],
        ]);

        return $schedule;
    }
}
