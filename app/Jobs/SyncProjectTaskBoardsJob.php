<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectTaskBoardSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the project → monthly task board sync off the web request.
 *
 * ProjectTaskBoardSyncService::syncProject() finds or creates a monthly board per
 * target department, syncs its members, the project card and every checklist row
 * for every project task — seconds of work inside one transaction. Doing that
 * inline made "add a team member" feel frozen, because the member insert itself
 * is a single fast row.
 *
 * Unique per project so a burst of member edits does not queue the same rebuild
 * many times over. The lock releases when the job starts (not when it ends), so
 * a member added while a sync is already running still gets its own run; the
 * sync re-reads the whole team each time, so the last run wins either way.
 */
class SyncProjectTaskBoardsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $projectId,
        public ?int $actorId = null,
        public bool $autoCreateMonthlyBoards = true,
    ) {}

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(ProjectTaskBoardSyncService $sync): void
    {
        $project = Project::with(['teamMembers.user', 'tasks'])->find($this->projectId);

        if (! $project) {
            return;
        }

        $sync->syncProject(
            $project,
            $this->actorId ? User::find($this->actorId) : null,
            null,
            $this->autoCreateMonthlyBoards
        );
    }
}
