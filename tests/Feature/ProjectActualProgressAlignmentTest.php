<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectProgressLog;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectWeeklyProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Weekly Timeline's actual curve — and the PDF built from the same series —
 * must agree with the Gantt's Actual bar: work counts from the date it really
 * started, not from the date it was planned to start.
 */
class ProjectActualProgressAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_reported_before_the_planned_start_lifts_the_curve_in_the_week_it_happened(): void
    {
        // Two whole weeks: the task is planned for week 2 but really ran in week 1.
        $weekOneMonday = CarbonImmutable::parse('2026-03-02');
        $project = $this->project();
        $project->update([
            'day1_date' => $weekOneMonday->toDateString(),
            'target_go_live' => $weekOneMonday->addWeek()->addDays(4)->toDateString(),
        ]);

        $task = $this->task($project, [
            'start_date' => $weekOneMonday->addWeek()->toDateString(),
            'end_date' => $weekOneMonday->addWeek()->addDays(4)->toDateString(),
            'actual_start_date' => $weekOneMonday->toDateString(),
            'actual_end_date' => $weekOneMonday->addDays(3)->toDateString(),
            'progress' => 100,
            'status' => 'Done',
        ]);

        ProjectProgressLog::create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'progress' => 100,
            'recorded_at' => $weekOneMonday->addDays(3)->setTime(12, 0),
        ]);

        $series = app(ProjectWeeklyProgressService::class)->build($project->fresh());

        $this->assertSame(100, $series['actual'][0], 'Week 1 must show the work that actually happened in week 1.');
    }

    public function test_the_curve_still_ignores_progress_logged_before_work_began(): void
    {
        // No actual start reported: the planned start remains the anchor, so an
        // early entry for a future task cannot pull the curve backwards.
        $weekOneMonday = CarbonImmutable::parse('2026-03-02');
        $project = $this->project();
        $project->update([
            'day1_date' => $weekOneMonday->toDateString(),
            'target_go_live' => $weekOneMonday->addWeek()->addDays(4)->toDateString(),
        ]);

        $task = $this->task($project, [
            'start_date' => $weekOneMonday->addWeek()->toDateString(),
            'end_date' => $weekOneMonday->addWeek()->addDays(4)->toDateString(),
            'progress' => 100,
            'status' => 'Done',
        ]);
        // Written directly: the model would otherwise stamp an actual start.
        ProjectTask::whereKey($task->id)->update(['actual_start_date' => null, 'actual_end_date' => null]);

        ProjectProgressLog::create([
            'project_id' => $project->id,
            'project_task_id' => $task->id,
            'progress' => 100,
            'recorded_at' => $weekOneMonday->addDays(3)->setTime(12, 0),
        ]);

        $series = app(ProjectWeeklyProgressService::class)->build($project->fresh());

        $this->assertSame(0, $series['actual'][0], 'Week 1 must stay flat: the task had not started yet.');
        $this->assertSame(100, $series['actual'][1]);
    }

    private function project(): Project
    {
        return Project::create([
            'name' => 'Curve alignment',
            'project_type' => 'General',
            'status' => 'In Progress',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function task(Project $project, array $overrides = []): ProjectTask
    {
        return ProjectTask::create(array_merge([
            'project_id' => $project->id,
            'name' => 'Cutover',
            'category' => 'Build',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ], $overrides));
    }
}
