<?php

namespace App\Observers;

use App\Models\ProjectProgressLog;
use App\Models\ProjectTask;

class ProjectTaskObserver
{
    /**
     * Handle the ProjectTask "created" event.
     *
     * The opening data point for this task's history line. Every later change is
     * appended by updated(), giving the Progress Chart a real week-over-week
     * series instead of one flat current value.
     */
    public function created(ProjectTask $projectTask): void
    {
        $this->logProgress($projectTask);
    }

    /**
     * Handle the ProjectTask "updated" event.
     */
    public function updated(ProjectTask $projectTask): void
    {
        // Date drags, renames and reordering must not pollute the history — only a
        // change in % is a progress event.
        if (! $projectTask->wasChanged('progress')) {
            return;
        }

        $this->logProgress($projectTask);
    }

    /**
     * Handle the ProjectTask "saved" event.
     */
    public function saved(ProjectTask $projectTask): void
    {
        $projectTask->project?->recalculateStatus();
    }

    /**
     * Handle the ProjectTask "deleted" event.
     */
    public function deleted(ProjectTask $projectTask): void
    {
        $projectTask->project?->recalculateStatus();
    }

    /**
     * Handle the ProjectTask "restored" event.
     */
    public function restored(ProjectTask $projectTask): void
    {
        $projectTask->project?->recalculateStatus();
    }

    private function logProgress(ProjectTask $projectTask): void
    {
        if (! $projectTask->project_id) {
            return;
        }

        ProjectProgressLog::create([
            'project_id'      => $projectTask->project_id,
            'project_task_id' => $projectTask->id,
            'progress'        => max(0, min(100, (int) $projectTask->progress)),
            'recorded_at'     => now(),
        ]);
    }
}
