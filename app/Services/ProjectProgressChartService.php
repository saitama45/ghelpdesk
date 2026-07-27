<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProgressLog;
use App\Models\ProjectTask;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the week-over-week progress comparison shown on the Projects → Progress
 * Chart tab (BS Team's ManCom report).
 *
 * The plotted value is the same completion the /projects list shows — the mean of
 * a project's task progress — replayed at each week's end from project_progress_logs.
 * A task only counts once it exists, so adding activities mid-quarter does not
 * retroactively drag earlier weeks down.
 */
class ProjectProgressChartService
{
    /** The project type the BS Team tracks; also the dropdown's default scope. */
    public const DEFAULT_TYPE = 'Internal Initiative';

    /**
     * Projects available in the Progress Chart dropdown: everything of the given
     * types, tagged with where it actually lives so the BS Team can see at a glance
     * whether an initiative is being run on a Task Board, on /projects, or both.
     *
     * @param  string[]  $types  Empty means every project type.
     */
    public function options(array $types): array
    {
        $projects = Project::query()
            ->when(! empty($types), fn ($q) => $q->whereIn('project_type', $types))
            ->with(['store:id,name', 'subject'])
            ->orderBy('name')
            ->get(['id', 'name', 'project_type', 'status', 'store_id', 'subject_type', 'subject_id']);

        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->pluck('id')->all();

        // A project is "on the Task Board" when a board is linked to it, or when any
        // card points at it (cards can be linked without the whole board being).
        $onBoard = TaskBoard::whereIn('project_id', $ids)->pluck('project_id')
            ->merge(TaskCard::whereIn('project_id', $ids)->pluck('project_id'))
            ->filter()
            ->unique()
            ->flip();

        return $projects->map(fn (Project $project) => [
            'value'   => $project->id,
            'label'   => $project->name,
            'type'    => $project->project_type,
            'status'  => $project->status,
            'context' => $project->subject_label,
            'source'  => $onBoard->has($project->id) ? 'Task Board + Projects' : 'Projects',
        ])->values()->all();
    }

    /**
     * @param  int[]     $projectIds  Empty means every project matching $types.
     * @param  string[]  $types
     */
    public function build(array $projectIds, array $types, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end   = CarbonImmutable::parse($to)->endOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        $weeks    = $this->buildWeeks($start, $end);
        $projects = $this->fetchProjects($projectIds, $types);

        if ($projects->isEmpty() || empty($weeks)) {
            return $this->emptyPayload($weeks, $start, $end);
        }

        $tasks   = $this->fetchTasks($projects->pluck('id')->all());
        $history = $this->fetchHistory($projects->pluck('id')->all(), $end);

        $series = $projects->map(function (Project $project) use ($weeks, $tasks, $history) {
            $projectTasks = $tasks->get($project->id, collect());

            $values = collect($weeks)
                ->map(fn (array $week) => $this->rateAt(
                    $projectTasks,
                    $history,
                    CarbonImmutable::parse($week['end'])
                ))
                ->all();

            return [
                'id'       => $project->id,
                'key'      => 'project-' . $project->id,
                'label'    => $project->name,
                'type'     => $project->project_type,
                'status'   => $project->status,
                'context'  => $project->subject_label,
                'values'   => $values,
                'deltas'   => $this->deltas($values),
                'start'    => $this->firstValue($values),
                'latest'   => $this->lastValue($values),
                'movement' => $this->movement($values),
            ];
        })->values();

        // A project with no activities at all has nothing to measure — it would draw
        // an empty line and take a legend slot. Keep it out of the chart but name it,
        // so "where did my project go?" has an answer on screen.
        $unmeasured = $series->filter(fn (array $s) => $s['latest'] === null)->pluck('label')->values();
        $series = $series->reject(fn (array $s) => $s['latest'] === null)->values();

        $overallValues = [];
        $overallCounts = [];

        foreach ($weeks as $index => $week) {
            $points = $series->pluck('values')
                ->map(fn (array $values) => $values[$index] ?? null)
                ->filter(fn ($value) => $value !== null);

            $overallCounts[] = $points->count();
            $overallValues[] = $points->isEmpty() ? null : round($points->avg(), 2);
        }

        return [
            'weeks'    => $weeks,
            'projects' => $series->all(),
            'overall'  => [
                'key'      => 'overall',
                'label'    => 'Overall',
                'values'   => $overallValues,
                'deltas'   => $this->deltas($overallValues),
                // Overall is the mean of the projects in flight that week, so it moves
                // when the cohort changes as well as when work lands. Exposing the count
                // lets the chart say so instead of the dip reading as lost progress.
                'counts'   => $overallCounts,
                'movement' => $this->movement($overallValues),
            ],
            'summary'  => [
                'from'          => $start->toDateString(),
                'to'            => $end->toDateString(),
                'week_count'    => count($weeks),
                'project_count' => $series->count(),
                'unmeasured'    => $unmeasured->all(),
                'overall_rate'  => $this->lastValue($overallValues),
                'movement'      => $this->movement($overallValues),
                // Latest completed week vs the one before it — the number the BS Team
                // actually reads out at ManCom.
                'last_week_change' => $this->lastDelta($overallValues),
            ],
        ];
    }

    /** ISO weeks (Mon-Sun) intersecting the range, with the first and last clipped to it. */
    private function buildWeeks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $weeks  = [];
        $cursor = $start->startOfWeek();

        while ($cursor->lessThanOrEqualTo($end)) {
            $weekStart = $cursor->greaterThan($start) ? $cursor : $start;
            $weekEnd   = $cursor->endOfWeek();
            $weekEnd   = $weekEnd->greaterThan($end) ? $end : $weekEnd;

            $weeks[] = [
                'label' => $weekStart->format('M j') . '-' . $weekEnd->format(
                    $weekStart->month === $weekEnd->month ? 'j' : 'M j'
                ),
                'start' => $weekStart->toDateString(),
                'end'   => $weekEnd->endOfDay()->toDateTimeString(),
            ];

            $cursor = $cursor->addWeek()->startOfWeek();
        }

        return $weeks;
    }

    /** @param int[] $projectIds */
    private function fetchProjects(array $projectIds, array $types): Collection
    {
        return Project::query()
            ->when(! empty($projectIds), fn ($q) => $q->whereIn('id', $projectIds))
            ->when(! empty($types), fn ($q) => $q->whereIn('project_type', $types))
            ->with(['store:id,name', 'subject'])
            ->orderBy('name')
            // Pinned columns: the projects table carries nvarchar(MAX) remarks that
            // would otherwise ride along on every request.
            ->get(['id', 'name', 'project_type', 'status', 'store_id', 'subject_type', 'subject_id']);
    }

    /**
     * Live task rows keyed by project — the denominator. Soft-deleted tasks are
     * excluded by the model's SoftDeletes scope, so removing an activity drops it
     * from every week rather than leaving a phantom in the history.
     *
     * @param  int[]  $projectIds
     */
    private function fetchTasks(array $projectIds): Collection
    {
        return ProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->get(['id', 'project_id', 'progress', 'created_at'])
            ->groupBy('project_id');
    }

    /**
     * Progress history up to the range end, as task_id => [[timestamp, progress], …]
     * in chronological order.
     *
     * @param  int[]  $projectIds
     */
    private function fetchHistory(array $projectIds, CarbonImmutable $end): Collection
    {
        return ProjectProgressLog::query()
            ->whereIn('project_id', $projectIds)
            ->where('recorded_at', '<=', $end)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['project_task_id', 'progress', 'recorded_at'])
            ->groupBy('project_task_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'at'    => CarbonImmutable::parse($row->recorded_at),
                'value' => (int) $row->progress,
            ])->all());
    }

    /**
     * The /projects completion formula — mean task progress — as of $cutoff.
     * Null when the project had no tasks yet, so the line breaks instead of
     * claiming a flat 0% for weeks the plan did not exist.
     */
    private function rateAt(Collection $tasks, Collection $history, CarbonImmutable $cutoff): ?float
    {
        $existing = $tasks->filter(
            fn (ProjectTask $task) => $task->created_at === null
                || CarbonImmutable::parse($task->created_at)->lessThanOrEqualTo($cutoff)
        );

        if ($existing->isEmpty()) {
            return null;
        }

        $sum = 0;

        foreach ($existing as $task) {
            $sum += $this->progressAt($task, $history->get($task->id, []), $cutoff);
        }

        return round($sum / $existing->count(), 2);
    }

    /**
     * The task's last logged value on or before $cutoff. With no entry that early
     * the task had not moved yet, so it counts as 0 — except for tasks that predate
     * the history table entirely (no rows at all), which fall back to their current
     * value so a project is never under-reported by a missing backfill.
     */
    private function progressAt(ProjectTask $task, array $entries, CarbonImmutable $cutoff): int
    {
        if (empty($entries)) {
            return max(0, min(100, (int) $task->progress));
        }

        $value = 0;

        foreach ($entries as $entry) {
            if ($entry['at']->greaterThan($cutoff)) {
                break;
            }

            $value = $entry['value'];
        }

        return max(0, min(100, $value));
    }

    /** Week-over-week change; null on the first week and wherever a value is missing. */
    private function deltas(array $values): array
    {
        $deltas = [];
        $previous = null;

        foreach ($values as $value) {
            $deltas[] = ($value === null || $previous === null) ? null : round($value - $previous, 2);
            $previous = $value ?? $previous;
        }

        return $deltas;
    }

    private function firstValue(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function lastValue(array $values): ?float
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return $values[$i];
            }
        }

        return null;
    }

    /** Total movement across the whole selected range. */
    private function movement(array $values): ?float
    {
        $first = $this->firstValue($values);
        $last  = $this->lastValue($values);

        return ($first === null || $last === null) ? null : round($last - $first, 2);
    }

    /** Change between the last two weeks that carry a value. */
    private function lastDelta(array $values): ?float
    {
        $points = array_values(array_filter($values, fn ($value) => $value !== null));

        return count($points) < 2 ? null : round(end($points) - prev($points), 2);
    }

    private function emptyPayload(array $weeks, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'weeks'    => $weeks,
            'projects' => [],
            'overall'  => ['key' => 'overall', 'label' => 'Overall', 'values' => [], 'deltas' => [], 'counts' => [], 'movement' => null],
            'summary'  => [
                'from'             => $start->toDateString(),
                'to'               => $end->toDateString(),
                'week_count'       => count($weeks),
                'project_count'    => 0,
                'unmeasured'       => [],
                'overall_rate'     => null,
                'movement'         => null,
                'last_week_change' => null,
            ],
        ];
    }
}
