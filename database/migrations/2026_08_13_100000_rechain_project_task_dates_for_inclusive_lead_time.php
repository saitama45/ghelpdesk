<?php

use App\Models\Project;
use App\Services\ProjectScheduler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Task dates are derived from Day 1, but only when something touches the plan —
 * so every project scheduled before the lead time became inclusive
 * (Finish = Start + Lead Time - 1) keeps its old, one-day-long dates until it is
 * next edited.
 *
 * This re-runs the chain once for every project that has a Day 1 Date, which is
 * idempotent: it recomputes the same values on a second run. Pinned start dates
 * (start_anchor_date) are respected, so hand-placed rows stay where they were put.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scheduler = app(ProjectScheduler::class);

        Project::whereNotNull('day1_date')
            ->orderBy('id')
            ->chunkById(50, function ($projects) use ($scheduler) {
                foreach ($projects as $project) {
                    try {
                        $scheduler->reschedule($project);
                    } catch (Throwable $exception) {
                        // One unschedulable project must not abort the deploy.
                        Log::warning('Could not re-chain project '.$project->id.': '.$exception->getMessage());
                    }
                }
            });
    }

    public function down(): void
    {
        // Dates are derived, not stored by hand — there is nothing to restore.
    }
};
