<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Role-permission gate on both Gantt delete paths — the per-row trash icon
 * (projects-tasks.destroy) and the milestone "select all" bulk delete.
 *
 * Permission middleware stays ON here. Every request is refused before the
 * controller runs, so no row is deleted (soft-delete is never executed).
 */
class ProjectTaskDeletePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private ProjectTask $activity;

    private ProjectTask $subTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        foreach (['projects.view', 'projects.manage_tasks'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $manager = User::factory()->create();
        $store = Store::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'Store', 'sector' => 1, 'area' => 'A', 'brand' => 'B',
            'cluster' => 'C', 'class' => 'Regular', 'is_active' => true,
        ]);
        $this->project = Project::create(['store_id' => $store->id, 'name' => 'Project', 'status' => 'Planning', 'created_by' => $manager->id]);

        $row = fn (?int $parentId) => ProjectTask::create([
            'project_id' => $this->project->id, 'parent_task_id' => $parentId, 'name' => 'Row '.uniqid(),
            'category' => 'UAT', 'status' => 'Pending', 'progress' => 0, 'order' => 1, 'milestone_order' => 1,
        ]);
        $this->activity = $row(null);
        $this->subTask = $row($this->activity->id);
    }

    /**
     * Has plan access to the rows (assigned to the activity) but lacks the role
     * permission. Not a milestone owner — owners are granted manage_tasks
     * automatically, see the tests at the bottom.
     */
    private function assigneeWithout(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->activity->update(['assigned_to' => $user->id]);

        return $user;
    }

    public function test_becoming_a_milestone_owner_grants_manage_tasks(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('projects.view');
        $this->assertFalse($owner->hasPermissionTo('projects.manage_tasks'));

        $milestone = ProjectMilestone::create(['project_id' => $this->project->id, 'category' => 'UAT', 'assigned_to' => null]);
        $this->assertFalse($owner->fresh()->hasPermissionTo('projects.manage_tasks'));

        $milestone->update(['assigned_to' => $owner->id]);
        $this->assertTrue($owner->fresh()->hasPermissionTo('projects.manage_tasks'));

        // The route gate now lets them through to the plan's own rules: an edit saves.
        $this->actingAs($owner->fresh())
            ->putJson(route('projects-tasks.update', $this->subTask), ['name' => 'Renamed by owner'])
            ->assertOk();

        $this->assertSame('Renamed by owner', $this->subTask->fresh()->name);
    }

    public function test_milestone_owner_endpoint_grants_manage_tasks_to_the_new_owner(): void
    {
        $manager = User::find($this->project->created_by);
        $manager->givePermissionTo(['projects.view', 'projects.manage_tasks']);
        $newOwner = User::factory()->create();

        $this->actingAs($manager)
            ->putJson(route('projects.milestones.owner', $this->project), ['category' => 'UAT', 'assigned_to' => $newOwner->id])
            ->assertRedirect(); // the owner endpoint redirects back to the Gantt on success

        $this->assertTrue($newOwner->fresh()->hasPermissionTo('projects.manage_tasks'));
    }

    public function test_the_granted_permission_still_does_not_open_another_milestone(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('projects.view');
        ProjectMilestone::create(['project_id' => $this->project->id, 'category' => 'OTHER', 'assigned_to' => $owner->id]);

        $this->actingAs($owner->fresh())
            ->putJson(route('projects-tasks.update', $this->subTask), ['name' => 'Intruder'])
            ->assertForbidden();
    }

    public function test_per_row_delete_requires_manage_tasks_permission(): void
    {
        $user = $this->assigneeWithout('projects.view');

        $this->actingAs($user)
            ->deleteJson(route('projects-tasks.destroy', $this->subTask))
            ->assertForbidden();

        $this->assertSame(2, ProjectTask::where('project_id', $this->project->id)->count());
    }

    public function test_select_all_bulk_delete_requires_manage_tasks_permission(): void
    {
        $user = $this->assigneeWithout('projects.view');

        $this->actingAs($user)
            ->postJson(route('projects.milestones.tasks.bulk-destroy', $this->project), [
                'category' => 'UAT',
                'task_ids' => [$this->activity->id, $this->subTask->id],
            ])
            ->assertForbidden();

        $this->assertSame(2, ProjectTask::where('project_id', $this->project->id)->count());
    }

    public function test_both_delete_paths_require_projects_view_permission(): void
    {
        $user = $this->assigneeWithout('projects.manage_tasks');

        $this->actingAs($user)
            ->deleteJson(route('projects-tasks.destroy', $this->subTask))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('projects.milestones.tasks.bulk-destroy', $this->project), [
                'category' => 'UAT',
                'task_ids' => [$this->subTask->id],
            ])
            ->assertForbidden();

        $this->assertSame(2, ProjectTask::where('project_id', $this->project->id)->count());
    }
}
