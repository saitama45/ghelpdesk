<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Edit restrictions on /projects/{id}:
 *  - the project creator (and admins with projects.delete) manage everything;
 *  - everyone else may only edit the activity / sub-task assigned to them;
 *  - all other rows and the project structure are read-only for them.
 */
class ProjectTaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // We are exercising the controller's own abort_unless checks, not the
        // route middleware, so only CSRF/authorize middleware is stripped.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        foreach (['projects.edit', 'projects.delete'] as $name) {
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

    private function task(Project $project, ?int $assignedTo = null): ProjectTask
    {
        return ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Install POS',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
            'assigned_to' => $assignedTo,
        ]);
    }

    public function test_assignee_can_edit_their_own_row(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->task($project, $assignee->id);

        $this->actingAs($assignee)
            ->put(route('projects-tasks.update', $task), ['progress' => 80])
            ->assertRedirect();

        $this->assertSame(80, (int) $task->fresh()->progress);
        $this->assertSame($assignee->id, (int) $task->fresh()->updated_by);
    }

    public function test_non_assignee_cannot_edit_someone_elses_row(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->task($project, $assignee->id);

        $this->actingAs($stranger)
            ->put(route('projects-tasks.update', $task), ['progress' => 80])
            ->assertForbidden();

        $this->assertSame(0, (int) $task->fresh()->progress);
    }

    public function test_owner_can_edit_any_row(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->task($project, $assignee->id);

        $this->actingAs($owner)
            ->put(route('projects-tasks.update', $task), ['progress' => 55])
            ->assertRedirect();

        $this->assertSame(55, (int) $task->fresh()->progress);
    }

    public function test_non_owner_assignee_cannot_delete_or_add_rows(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $project = $this->project($owner);
        $task = $this->task($project, $assignee->id);

        // Deleting a row is a management action even for the assignee.
        $this->actingAs($assignee)
            ->delete(route('projects-tasks.destroy', $task))
            ->assertForbidden();

        // Adding a new activity is manager-only.
        $this->actingAs($assignee)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'New activity',
                'category' => 'POS',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('project_tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    public function test_admin_role_can_manage_projects_they_did_not_create(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        Role::findOrCreate('Admin', 'web');
        $admin->assignRole('Admin');

        $project = $this->project($owner);
        $task = $this->task($project);

        $this->actingAs($admin)
            ->put(route('projects-tasks.update', $task), ['progress' => 42])
            ->assertRedirect();

        $this->assertSame(42, (int) $task->fresh()->progress);
    }

    public function test_projects_delete_permission_alone_does_not_grant_management(): void
    {
        // projects.delete is held by many operational roles; it must NOT let a
        // non-creator manage a project they didn't create.
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $other->givePermissionTo('projects.delete');

        $project = $this->project($owner);
        $task = $this->task($project);

        $this->actingAs($other)
            ->put(route('projects-tasks.update', $task), ['progress' => 99])
            ->assertForbidden();

        $this->assertSame(0, (int) $task->fresh()->progress);
    }

    public function test_legacy_ownerless_project_is_not_editable_by_a_plain_editor(): void
    {
        // Existing projects are backfilled to their team lead; any that stay
        // ownerless are admin-only. A user who merely holds projects.edit must not
        // be able to modify a project they don't own.
        $editor = User::factory()->create();
        $editor->givePermissionTo('projects.edit');

        $store = Store::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'Store', 'sector' => 1, 'area' => 'A', 'brand' => 'B',
            'cluster' => 'C', 'class' => 'Regular', 'is_active' => true,
        ]);
        $project = Project::create([
            'store_id' => $store->id,
            'name' => 'Legacy',
            'status' => 'Planning',
        ]);
        $this->assertNull($project->created_by);

        $this->actingAs($editor)
            ->post(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'name' => 'First activity',
                'category' => 'POS',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertForbidden();

        // No ownership was silently claimed, and nothing was added.
        $this->assertNull($project->fresh()->created_by);
        $this->assertSame(0, ProjectTask::where('project_id', $project->id)->count());
    }
}
