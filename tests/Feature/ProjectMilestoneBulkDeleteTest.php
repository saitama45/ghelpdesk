<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Bulk delete of ticked rows inside one milestone on the Gantt.
 *
 * Only the guards are exercised — every request here is rejected before any row
 * is touched. project_tasks soft-deletes and the soft-delete path is never
 * executed (global database-safety rule); the success path is verified by review.
 */
class ProjectMilestoneBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        Permission::findOrCreate('projects.manage_tasks', 'web');
    }

    private function project(User $owner): Project
    {
        $store = Store::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'Store', 'sector' => 1, 'area' => 'A', 'brand' => 'B',
            'cluster' => 'C', 'class' => 'Regular', 'is_active' => true,
        ]);

        return Project::create(['store_id' => $store->id, 'name' => 'Project', 'status' => 'Planning', 'created_by' => $owner->id]);
    }

    private function row(Project $project, string $category, ?int $parentId = null): ProjectTask
    {
        return ProjectTask::create([
            'project_id' => $project->id, 'parent_task_id' => $parentId, 'name' => 'Row '.uniqid(),
            'category' => $category, 'status' => 'Pending', 'progress' => 0, 'order' => 1, 'milestone_order' => 1,
        ]);
    }

    private function url(Project $project): string
    {
        return route('projects.milestones.tasks.bulk-destroy', $project);
    }

    public function test_route_is_gated_by_manage_tasks(): void
    {
        $middleware = Route::getRoutes()->getByName('projects.milestones.tasks.bulk-destroy')->gatherMiddleware();

        $this->assertContains('permission:projects.manage_tasks', $middleware);
        $this->assertContains('permission:projects.view', $middleware);
    }

    public function test_owner_of_another_milestone_cannot_delete_rows_here(): void
    {
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->project($manager);
        $activity = $this->row($project, 'UAT');
        $sub = $this->row($project, 'UAT', $activity->id);
        ProjectMilestone::create(['project_id' => $project->id, 'category' => 'OTHER', 'assigned_to' => $outsider->id]);
        $this->row($project, 'OTHER');

        $this->actingAs($outsider)
            ->postJson($this->url($project), ['category' => 'UAT', 'task_ids' => [$activity->id, $sub->id]])
            ->assertForbidden();

        $this->assertSame(3, ProjectTask::where('project_id', $project->id)->count());
    }

    public function test_rows_outside_the_milestone_or_project_are_rejected(): void
    {
        $manager = User::factory()->create();
        $project = $this->project($manager);
        $otherProject = $this->project($manager);
        $uat = $this->row($project, 'UAT');
        $pilot = $this->row($project, 'PILOT');
        $pilotSub = $this->row($project, 'PILOT', $pilot->id);
        $foreign = $this->row($otherProject, 'UAT');

        $this->actingAs($manager)
            ->postJson($this->url($project), ['category' => 'UAT', 'task_ids' => [$uat->id, $pilotSub->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('task_ids');

        $this->actingAs($manager)
            ->postJson($this->url($project), ['category' => 'UAT', 'task_ids' => [$uat->id, $foreign->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('task_ids');

        $this->actingAs($manager)
            ->postJson($this->url($project), ['category' => 'UAT', 'task_ids' => []])
            ->assertStatus(422);

        $this->assertSame(3, ProjectTask::where('project_id', $project->id)->count());
        $this->assertSame(1, ProjectTask::where('project_id', $otherProject->id)->count());
    }
}
