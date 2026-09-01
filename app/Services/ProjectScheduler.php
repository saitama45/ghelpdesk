<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;

/**
 * Owns "what dates does this project's plan actually land on".
 *
 * Every path that can move a row — editing a lead time, adding or deleting a
 * task, applying a template, repointing a requisite, flipping Can Run Parallel,
 * or switching the project between working and calendar day counting — funnels
 * through reschedule(), so the plan is always derived from Day 1 rather than
 * patched in place.
 */
class ProjectScheduler
{
    public function __construct(private HolidayCalendar $holidays)
    {
    }

    /** Date arithmetic in the project's own counting mode. */
    public function calculatorFor(?Project $project): ScheduleCalculator
    {
        return new ScheduleCalculator(
            $this->holidays,
            ScheduleCalculator::normalizeMode($project?->schedule_day_mode)
        );
    }

    public function chainFor(?Project $project): ScheduleChain
    {
        return new ScheduleChain($this->calculatorFor($project));
    }

    /**
     * Lay out a template's rows against a project's Day 1 Date. Returns [] when
     * the project has no Day 1 Date yet.
     *
     * @return array<int, array{start: string, end: string, days: int}>
     */
    public function scheduleForTemplate($activities, ?Project $project): array
    {
        $rows = $activities->map(fn ($activity) => [
            'id' => $activity->id,
            'parent_id' => $activity->parent_activity_template_id,
            'depends_on' => $activity->depends_on_template_id,
            'parallel' => (bool) $activity->can_run_parallel,
            'days' => (int) $activity->default_duration_days,
            'anchor' => null,
            'milestone_order' => (int) $activity->milestone_order,
            'order' => (float) $activity->order,
        ])->values()->all();

        return $this->chainFor($project)->resolve($rows, $project?->day1_date);
    }

    /**
     * An activity with sub-tasks owns no figures of its own: its progress is
     * their lead-time-weighted average and its lead time is the SUM of theirs
     * (Business Requirement, "Developer Logic" rule 5). Parallel sub-tasks
     * shorten the bar the milestone covers but not the effort it totals, so the
     * sum stands — reschedule() only re-derives its dates.
     */
    public function syncParentRollups(Project $project): array
    {
        $tasks = ProjectTask::where('project_id', $project->id)->get();
        $childrenByParent = $tasks->filter(fn ($task) => !empty($task->parent_task_id))->groupBy('parent_task_id');
        $changedIds = [];

        foreach ($tasks->filter(fn ($task) => empty($task->parent_task_id)) as $parent) {
            $children = $childrenByParent[$parent->id] ?? collect();

            if ($children->isEmpty()) {
                continue;
            }

            $leadTime = $children->sum(fn ($child) => max(1, (int) ($child->lead_time_days ?? 1)));
            $hasExplicitWeights = $children->every(fn ($child) => $child->sub_task_weight !== null);
            $weightTotal = $hasExplicitWeights
                ? (float) $children->sum(fn ($child) => (float) $child->sub_task_weight)
                : (float) $leadTime;
            $progress = $weightTotal > 0
                ? (int) round($children->sum(function ($child) use ($hasExplicitWeights) {
                    $weight = $hasExplicitWeights
                        ? (float) $child->sub_task_weight
                        : max(1, (int) ($child->lead_time_days ?? 1));
                    return $weight * (int) $child->progress;
                }) / $weightTotal)
                : 0;

            $status = $progress >= 100 ? 'Done' : ($progress > 0 ? 'Ongoing' : 'Pending');

            if ((int) $parent->lead_time_days !== $leadTime || (int) $parent->progress !== $progress || $parent->status !== $status) {
                $parent->update([
                    'lead_time_days' => $leadTime,
                    'progress' => $progress,
                    'status' => $status,
                ]);
                $changedIds[] = (int) $parent->id;
            }
        }

        return $changedIds;
    }

    /**
     * Re-derive every task's Start/End Date from the project's Day 1 Date, using
     * each row's lead time, requisite and Can Run Parallel flag, counted in the
     * project's day mode. No-op without a Day 1 Date.
     */
    public function reschedule(Project $project): array
    {
        $changedIds = $this->syncParentRollups($project);

        if (!$project->day1_date) {
            return $changedIds;
        }

        $tasks = ProjectTask::where('project_id', $project->id)->get();

        $rows = $tasks->map(fn (ProjectTask $task) => [
            'id' => $task->id,
            'parent_id' => $task->parent_task_id,
            'depends_on' => $task->depends_on_task_id,
            'parallel' => (bool) $task->can_run_parallel,
            'days' => (int) ($task->lead_time_days ?? 1),
            'anchor' => $task->start_anchor_date?->toDateString(),
            'milestone_order' => (int) $task->milestone_order,
            'order' => (float) $task->order,
        ])->values()->all();

        $schedule = $this->chainFor($project)->resolve($rows, $project->day1_date);

        foreach ($tasks as $task) {
            $span = $schedule[(string) $task->id] ?? null;

            if (!$span) {
                continue;
            }

            $updates = [];

            if ($task->start_date?->toDateString() !== $span['start'] || $task->end_date?->toDateString() !== $span['end']) {
                $updates['start_date'] = $span['start'];
                $updates['end_date'] = $span['end'];
            }

            // A parent's lead time stays the sum syncParentRollups() wrote — the
            // span its sub-tasks cover is not its lead time.

            if ($updates !== []) {
                $task->update($updates);
                $changedIds[] = (int) $task->id;
            }
        }

        return array_values(array_unique($changedIds));
    }
}
