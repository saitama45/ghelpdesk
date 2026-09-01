<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use App\Support\ProjectPlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Milestone ownership on /projects/{id}?tab=gantt.
 *
 * Three levels, each granting the branch below it:
 *  - milestone owner: add / edit / delete the activities and sub-tasks of THAT
 *    milestone, rename or delete it, and start a milestone of their own;
 *  - activity assignee: edit / delete THAT activity and add / edit / delete its
 *    sub-tasks — not a sibling activity;
 *  - sub-task assignee: edit / delete THAT sub-task. Nothing is ever added under
 *    a sub-task; it is the final level.
 *
 * Row deletion is asserted through ProjectPlanAccess rather than by calling the
 * endpoint: project_tasks soft-deletes, and the soft-delete path is never
 * executed (global database-safety rule). Denied cases DO call the endpoint —
 * they abort before anything is deleted.
 */
class ProjectMilestoneOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercising the controller's own abort_unless checks, not route middleware.
        // The route gate (permission:projects.manage_tasks) is a separate concern
        // and is covered by the module's route-permission checks; stripping it
        // here means a 403 below can only have come from the plan's own rule.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        foreach (['projects.edit', 'projects.delete', 'projects.manage_tasks'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function project(User $owner): Project
    {
        $store = Store::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'Store',
            'sector' => 1,
            'area' => 'A',
            'brand' => 'B',
            'cluster' => 'C',
            'class' => 'Regular',
            'is_active' => true,
        ]);

        return Project::create([
            'store_id' => $store->id,
            'name' => 'Project',
            'status' => 'Planning',
            'created_by' => $owner->id,
        ]);
    }

    private function row(Project $project, string $category, ?int $assignedTo = null, ?int $parentId = null): ProjectTask
    {
        return ProjectTask::create([
            'project_id' => $project->id,
            'parent_task_id' => $parentId,
            'name' => 'Row in ' . $category,
            'category' => $category,
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
            'milestone_order' => 1,
            'assigned_to' => $assignedTo,
        ]);
    }

    private function ownMilestone(Project $project, string $category, User $user): ProjectMilestone
    {
        return ProjectMilestone::create([
            'project_id' => $project->id,
            'category' => $category,
            'assigned_to' => $user->id,
        ]);
    }

    /* ------------------------------------------------------- milestone owner */

    public function test_milestone_owner_can_add_an_activity_to_their_milestone(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'Cabling',
                'category' => 'POS',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $project->id,
            'name' => 'Cabling',
            'category' => 'POS',
        ]);
    }

    public function test_milestone_owner_cannot_add_an_activity_to_another_milestone(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');
        $this->row($project, 'FIT-OUT');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'Intruder',
                'category' => 'FIT-OUT',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_tasks', ['name' => 'Intruder']);
    }

    public function test_milestone_owner_can_edit_any_row_inside_their_milestone(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $someoneElse = User::factory()->create();
        $project = $this->project($manager);
        $task = $this->row($project, 'POS', $someoneElse->id);
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->put(route('projects-tasks.update', $task), ['progress' => 70])
            ->assertRedirect();

        $this->assertSame(70, (int) $task->fresh()->progress);
    }

    public function test_milestone_owner_cannot_edit_a_row_in_another_milestone(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $foreign = $this->row($project, 'FIT-OUT');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->put(route('projects-tasks.update', $foreign), ['progress' => 70])
            ->assertForbidden();

        $this->assertSame(0, (int) $foreign->fresh()->progress);
    }

    public function test_milestone_owner_can_start_their_own_milestone_and_owns_it(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'Kickoff',
                'category' => 'TRAINING',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_milestones', [
            'project_id' => $project->id,
            'category' => 'TRAINING',
            'assigned_to' => $milestoneOwner->id,
        ]);
    }

    public function test_a_user_who_owns_no_milestone_cannot_start_one(): void
    {
        $manager = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($manager);
        // Assigned an activity — that is the level below milestone ownership.
        $this->row($project, 'POS', $assignee->id);

        $this->actingAs($assignee)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'Kickoff',
                'category' => 'TRAINING',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_milestones', ['category' => 'TRAINING']);
    }

    public function test_milestone_owner_may_delete_only_their_own_milestone(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');
        $this->row($project, 'FIT-OUT');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        // Someone else's milestone is refused outright.
        $this->actingAs($milestoneOwner)
            ->delete(route('projects.milestones.destroy', $project), ['category' => 'FIT-OUT'])
            ->assertForbidden();

        $this->assertDatabaseHas('project_tasks', ['category' => 'FIT-OUT', 'deleted_at' => null]);

        // Their own is permitted — asserted through the rule, since deleting the
        // milestone soft-deletes its rows.
        $this->assertTrue(ProjectPlanAccess::canManageMilestone($project, $milestoneOwner, 'POS'));
        $this->assertFalse(ProjectPlanAccess::canManageMilestone($project, $milestoneOwner, 'FIT-OUT'));
    }

    /* ----------------------------------------------------- activity assignee */

    public function test_activity_assignee_can_add_a_sub_task_under_their_activity(): void
    {
        $manager = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($manager);
        $activity = $this->row($project, 'POS', $assignee->id);

        $this->actingAs($assignee)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'parent_task_id' => $activity->id,
                'name' => 'Unbox terminals',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_tasks', [
            'parent_task_id' => $activity->id,
            'name' => 'Unbox terminals',
        ]);
    }

    public function test_activity_assignee_cannot_add_a_sub_task_under_another_activity(): void
    {
        $manager = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS', $assignee->id);
        $foreign = $this->row($project, 'POS');

        $this->actingAs($assignee)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'parent_task_id' => $foreign->id,
                'name' => 'Intruder',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_tasks', ['name' => 'Intruder']);
    }

    public function test_activity_assignee_can_edit_a_sub_task_of_their_activity(): void
    {
        $manager = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($manager);
        $activity = $this->row($project, 'POS', $assignee->id);
        $subTask = $this->row($project, 'POS', null, $activity->id);

        $this->actingAs($assignee)
            ->put(route('projects-tasks.update', $subTask), ['progress' => 30])
            ->assertRedirect();

        $this->assertSame(30, (int) $subTask->fresh()->progress);
    }

    /* ----------------------------------------------------- sub-task assignee */

    public function test_sub_task_assignee_can_edit_their_row_but_not_the_activity_above_it(): void
    {
        $manager = User::factory()->create();
        $subTaskOwner = User::factory()->create();
        $project = $this->project($manager);
        $activity = $this->row($project, 'POS');
        $subTask = $this->row($project, 'POS', $subTaskOwner->id, $activity->id);

        $this->actingAs($subTaskOwner)
            ->put(route('projects-tasks.update', $subTask), ['progress' => 45])
            ->assertRedirect();
        $this->assertSame(45, (int) $subTask->fresh()->progress);

        // The activity above is refused. Its progress is NOT the thing to assert
        // on — an activity with sub-tasks rolls their percentages up, so 45 there
        // is the rollup doing its job. Its name is only ever set by an editor.
        $this->actingAs($subTaskOwner)
            ->put(route('projects-tasks.update', $activity), [
                'name' => 'Renamed by a sub-task assignee',
                'status' => 'Pending',
            ])
            ->assertForbidden();

        $this->assertSame('Row in POS', $activity->fresh()->name);
        $this->assertFalse(ProjectPlanAccess::canEditTask($activity->fresh(), $subTaskOwner));
    }

    public function test_nothing_can_be_added_under_a_sub_task(): void
    {
        $manager = User::factory()->create();
        $subTaskOwner = User::factory()->create();
        $project = $this->project($manager);
        $activity = $this->row($project, 'POS');
        $subTask = $this->row($project, 'POS', $subTaskOwner->id, $activity->id);

        // The last level of the plan: even its assignee cannot nest below it.
        $this->assertFalse(ProjectPlanAccess::canAddSubTask($subTask, $subTaskOwner));

        // The plan's one-sub-task-level rule is checked before authorisation, so
        // the request is refused as invalid rather than as forbidden. Either way
        // no third level is created — and the manager is refused just the same.
        $this->actingAs($subTaskOwner)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'parent_task_id' => $subTask->id,
                'name' => 'Third level',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertSessionHasErrors('parent_task_id');

        $this->actingAs($manager)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'parent_task_id' => $subTask->id,
                'name' => 'Third level',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertSessionHasErrors('parent_task_id');

        $this->assertDatabaseMissing('project_tasks', ['name' => 'Third level']);
    }

    /* ---------------------------------------------------- setting the owner */

    public function test_manager_can_set_a_milestone_owner(): void
    {
        $manager = User::factory()->create();
        $newOwner = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');

        $this->actingAs($manager)
            ->put(route('projects.milestones.owner', $project), [
                'category' => 'POS',
                'assigned_to' => $newOwner->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_milestones', [
            'project_id' => $project->id,
            'category' => 'POS',
            'assigned_to' => $newOwner->id,
        ]);
    }

    public function test_a_stranger_cannot_set_a_milestone_owner(): void
    {
        $manager = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($manager);
        $this->row($project, 'POS');

        $this->actingAs($stranger)
            ->put(route('projects.milestones.owner', $project), [
                'category' => 'POS',
                'assigned_to' => $stranger->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('project_milestones', ['project_id' => $project->id]);
    }

    /* ---------------------------------------------------------- edge cases */

    public function test_a_blank_category_is_owned_under_the_name_general(): void
    {
        // project_tasks.category is nullable and the Gantt labels a blank one
        // "General" — ownership has to be found again under that same name.
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $task = $this->row($project, 'POS');
        $task->update(['category' => null]);
        $this->ownMilestone($project, 'General', $milestoneOwner);

        $this->assertTrue(ProjectPlanAccess::canEditTask($task->fresh(), $milestoneOwner));

        $this->actingAs($milestoneOwner)
            ->put(route('projects-tasks.update', $task), ['progress' => 25])
            ->assertRedirect();

        $this->assertSame(25, (int) $task->fresh()->progress);
    }

    public function test_a_row_cannot_be_moved_into_a_milestone_the_editor_does_not_own(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $task = $this->row($project, 'POS');
        $this->row($project, 'FIT-OUT');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        $this->actingAs($milestoneOwner)
            ->put(route('projects-tasks.update', $task), [
                'name' => $task->name,
                'category' => 'FIT-OUT',
                'status' => 'Pending',
            ])
            ->assertForbidden();

        $this->assertSame('POS', $task->fresh()->category);
    }

    public function test_renaming_a_milestone_carries_its_owner_across(): void
    {
        $manager = User::factory()->create();
        $milestoneOwner = User::factory()->create();
        $project = $this->project($manager);
        $task = $this->row($project, 'POS');
        $this->ownMilestone($project, 'POS', $milestoneOwner);

        // The manager renames the only activity's milestone; nothing is left
        // under the old name, so the ownership record follows it.
        $this->actingAs($manager)
            ->put(route('projects-tasks.update', $task), [
                'name' => $task->name,
                'category' => 'POS ROLLOUT',
                'status' => 'Pending',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_milestones', [
            'project_id' => $project->id,
            'category' => 'POS ROLLOUT',
            'assigned_to' => $milestoneOwner->id,
        ]);
        $this->assertDatabaseMissing('project_milestones', [
            'project_id' => $project->id,
            'category' => 'POS',
        ]);
    }
}
