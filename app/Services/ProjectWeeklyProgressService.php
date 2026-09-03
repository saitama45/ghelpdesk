<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProgressLog;
use App\Models\ProjectTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** One source of truth for the Weekly Timeline and Gantt PDF progress series. */
class ProjectWeeklyProgressService
{
    public function build(Project $project, ?Collection $tasks = null): array
    {
        $tasks ??= $project->tasks()->get();
        $tasks = $tasks->values();
        $parentIds = $tasks->pluck('parent_task_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $leafTasks = $tasks->reject(fn (ProjectTask $task) => $parentIds->contains((int) $task->id))->values();
        $weeks = $this->weeks($project, $tasks);
        $history = $this->history($leafTasks);
        $series = $this->series($leafTasks, $weeks, $history);
        $tasksById = $tasks->keyBy(fn (ProjectTask $task) => (int) $task->id);

        $milestones = $leafTasks
            ->groupBy(function (ProjectTask $task) use ($tasksById) {
                $parent = $task->parent_task_id ? $tasksById->get((int) $task->parent_task_id) : null;
                return $parent?->category ?: $task->category ?: 'General';
            })
            ->map(fn (Collection $group) => $this->series($group->values(), $weeks, $history))
            ->all();

        // The executive review always describes the real current calendar week.
        // Future reported points may be drawn, but they must never relabel today's
        // PDF as a future-week executive review.
        $now = CarbonImmutable::now();
        $activeIndex = collect($weeks)->search(
            fn (array $week) => $now->betweenIncluded($week['start'], $week['end'])
        );
        if ($activeIndex === false) {
            $activeIndex = $now->lessThan($weeks[0]['start']) ? 0 : count($weeks) - 1;
        }

        return [
            'weeks' => collect($weeks)->map(fn (array $week) => [
                'index' => $week['index'],
                'label' => $week['label'],
                'start' => $week['start']->toDateString(),
                'end' => $week['end']->toDateString(),
                'formattedRange' => $week['start']->format('M d').' - '.$week['end']->format('M d, Y'),
            ])->all(),
            'planned' => $series['planned'],
            'actual' => $series['actual'],
            'active_index' => $activeIndex,
            'milestones' => $milestones,
            'history' => $history->flatMap(fn (Collection $entries, $taskId) => $entries->map(fn (array $entry) => [
                'project_task_id' => (int) $taskId,
                'progress' => $entry['progress'],
                'recorded_at' => $entry['at']->toIso8601String(),
            ]))->values()->all(),
        ];
    }

    private function weeks(Project $project, Collection $tasks): array
    {
        $taskStarts = $tasks->pluck('start_date')->filter()->map(fn ($date) => CarbonImmutable::parse($date));
        $taskEnds = $tasks->pluck('end_date')->filter()->map(fn ($date) => CarbonImmutable::parse($date));
        $start = $project->day1_date ? CarbonImmutable::parse($project->day1_date) : $taskStarts->min();
        $end = $project->target_go_live ? CarbonImmutable::parse($project->target_go_live) : $taskEnds->max();
        $start ??= CarbonImmutable::now();
        $end = $end && $end->greaterThanOrEqualTo($start) ? $end : $start->addWeeks(6);
        $cursor = $start->startOfWeek()->startOfDay();
        $last = $end->endOfWeek()->endOfDay();
        $weeks = [];
        $index = 1;

        while ($cursor->lessThanOrEqualTo($last)) {
            $weeks[] = [
                'index' => $index,
                'label' => "Week {$index}",
                'start' => $cursor,
                'end' => $cursor->endOfWeek()->endOfDay(),
            ];
            $cursor = $cursor->addWeek();
            $index++;
        }

        return $weeks;
    }

    private function history(Collection $tasks): Collection
    {
        if ($tasks->isEmpty()) {
            return collect();
        }

        $tasksById = $tasks->keyBy(fn (ProjectTask $task) => (int) $task->id);
        $now = CarbonImmutable::now();

        return ProjectProgressLog::query()
            ->whereIn('project_task_id', $tasksById->keys())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['project_task_id', 'progress', 'recorded_at'])
            ->groupBy('project_task_id')
            ->map(function (Collection $logs, $taskId) use ($tasksById, $now) {
                // The Gantt's Actual bar begins at actual_start_date, so the
                // curve must too: a log entered before the PLANNED start is only
                // pulled forward when the row had not actually begun. Work that
                // really started early stays in the week it happened.
                $taskStart = $this->workStart($tasksById->get((int) $taskId));

                return $logs->map(function (ProjectProgressLog $log) use ($taskStart, $now) {
                    $recorded = CarbonImmutable::parse($log->recorded_at)
                        ->setTimezone(config('app.timezone'));
                    // Older Sunday-end values can be rounded by SQL Server to
                    // Monday 00:00. Move that exact boundary back into Sunday.
                    if ($recorded->greaterThan($now)
                        && $recorded->isMonday()
                        && $recorded->format('H:i:s') === '00:00:00') {
                        $recorded = $recorded->subSecond();
                    }
                    // Compatibility is intentionally limited to the immediately
                    // following week. Otherwise an old early update for a task
                    // months away would make the chart look reported for months.
                    $nextWeekEnd = $recorded->endOfWeek()->addWeek()->endOfDay();
                    $effective = $taskStart
                        && $recorded->lessThan($taskStart)
                        && $taskStart->lessThanOrEqualTo($nextWeekEnd)
                            ? $taskStart
                            : $recorded;

                    return [
                        'at' => $effective,
                        'progress' => max(0, min(100, (int) $log->progress)),
                        // A generated 0% baseline for a future task must not make
                        // the chart look reported through that future week.
                        'extends_horizon' => ! ($log->progress === 0
                            && $recorded->lessThanOrEqualTo($now)
                            && $effective->greaterThan($now)),
                    ];
                })->sortBy('at')->values();
            });
    }

    private function series(Collection $tasks, array $weeks, Collection $history): array
    {
        $totalWeight = (float) $tasks->sum(fn (ProjectTask $task) => $this->weight($task));
        $totalWeight = $totalWeight > 0 ? $totalWeight : 1;
        $actualThrough = CarbonImmutable::now();

        foreach ($history as $entries) {
            foreach ($entries as $entry) {
                if ($entry['extends_horizon'] && $entry['at']->greaterThan($actualThrough)) {
                    $actualThrough = $entry['at'];
                }
            }
        }

        $planned = [];
        $actual = [];

        foreach ($weeks as $week) {
            $plannedSum = 0.0;
            $actualSum = 0.0;
            $hasActual = $week['start']->lessThanOrEqualTo($actualThrough);
            $cutoff = $week['end']->greaterThan($actualThrough) ? $actualThrough : $week['end'];

            foreach ($tasks as $task) {
                $weight = $this->weight($task);
                $start = $task->start_date ? CarbonImmutable::parse($task->start_date)->startOfDay() : null;
                $end = $task->end_date ? CarbonImmutable::parse($task->end_date)->startOfDay() : null;

                if ($start && $end) {
                    if ($week['end']->greaterThanOrEqualTo($end)) {
                        $plannedSum += 100 * $weight;
                    } elseif ($week['end']->greaterThanOrEqualTo($start)) {
                        $duration = max(1, $end->getTimestamp() - $start->getTimestamp());
                        $elapsed = max(0, $week['end']->getTimestamp() - $start->getTimestamp());
                        $plannedSum += min(1, $elapsed / $duration) * 100 * $weight;
                    }
                } else {
                    $plannedSum += ($task->status === 'Done' ? 100 : 0) * $weight;
                }

                if ($hasActual) {
                    $actualSum += $this->progressAt($task, $history->get($task->id, collect()), $cutoff) * $weight;
                }
            }

            $planned[] = (int) round($plannedSum / $totalWeight);
            $actual[] = $hasActual ? (int) round($actualSum / $totalWeight) : null;
        }

        return ['planned' => $planned, 'actual' => $actual];
    }

    private function progressAt(ProjectTask $task, Collection $entries, CarbonImmutable $cutoff): int
    {
        // Nothing counts before the work began. Where an actual start has been
        // reported that is the date the Gantt draws, so it wins over the plan —
        // which is what lets an early start lift the curve early.
        $start = $this->workStart($task);
        if ($start && $cutoff->lessThan($start)) {
            return 0;
        }

        if ($entries->isEmpty()) {
            return max(0, min(100, (int) $task->progress));
        }

        $value = 0;
        foreach ($entries as $entry) {
            if ($entry['at']->greaterThan($cutoff)) {
                break;
            }
            $value = $entry['progress'];
        }

        return $value;
    }

    /**
     * When a row's work actually began, for curve purposes: the reported actual
     * start if there is one, otherwise the planned start. Mirrors the Gantt's
     * actual bar (see ProjectGantt.vue's actualSpan) so the chart, the PDF and
     * the bars all answer "when did this really run?" the same way.
     */
    private function workStart(?ProjectTask $task): ?CarbonImmutable
    {
        $date = $task?->actual_start_date ?: $task?->start_date;

        return $date ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    private function weight(ProjectTask $task): float
    {
        $milestone = (float) $task->milestone_weight > 0 ? (float) $task->milestone_weight / 100 : 1;
        $activity = (float) $task->activity_weight > 0 ? (float) $task->activity_weight / 100 : 1;
        $subTask = $task->parent_task_id && (float) $task->sub_task_weight > 0
            ? (float) $task->sub_task_weight / 100
            : 1;

        return $milestone * $activity * $subTask;
    }
}
