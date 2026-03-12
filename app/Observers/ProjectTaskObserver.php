<?php

namespace App\Observers;

use App\Models\ProjectTask;

class ProjectTaskObserver
{
    /**
     * Handle the ProjectTask "saved" event.
     */
    public function saved(ProjectTask $projectTask): void
    {
        $projectTask->project->recalculateStatus();
    }

    /**
     * Handle the ProjectTask "deleted" event.
     */
    public function deleted(ProjectTask $projectTask): void
    {
        $projectTask->project->recalculateStatus();
    }

    /**
     * Handle the ProjectTask "restored" event.
     */
    public function restored(ProjectTask $projectTask): void
    {
        $projectTask->project->recalculateStatus();
    }
}
