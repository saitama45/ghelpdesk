<?php

namespace Tests\Feature;

use App\Models\ActivityTemplate;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectActivitySubTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    }

    public function test_activity_template_persists_nested_sub_tasks(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('activity-templates.store'), [
                'name' => 'Nested NSO',
                'project_type' => 'NSO',
                'store_class' => 'Regular',
                'activities' => [
                    [
                        'client_key' => 'parent-1',
                        'parent_client_key' => null,
                        'activity' => 'Install POS',
                        'milestone' => 'POS',
                        'asset_item' => 'Terminal',
                        'model_specs' => 'A1',
                        'qty' => 1,
                        'responsible' => 'IT',
                        'department' => 'TAS',
                        'sub_unit' => 'DS',
                        'default_duration_days' => 2,
                        'order' => 1,
                    ],
                    [
                        'client_key' => 'child-1',
                        'parent_client_key' => 'parent-1',
                        'activity' => 'Configure menu',
                        'milestone' => 'POS',
                        'asset_item' => null,
                        'model_specs' => null,
                        'qty' => 1,
                        'responsible' => 'IT',
                        'department' => 'TAS',
                        'sub_unit' => 'DS',
                        'default_duration_days' => 1,
                        'order' => 1,
                    ],
                ],
            ])
            ->assertRedirect();

        $parent = ActivityTemplate::where('activity', 'Install POS')->firstOrFail();
        $child = ActivityTemplate::where('activity', 'Configure menu')->firstOrFail();

        $this->assertNull($parent->parent_activity_template_id);
        $this->assertSame($parent->id, $child->parent_activity_template_id);
        $this->assertSame('TAS', $parent->department);
        $this->assertSame('DS', $parent->sub_unit);
        $this->assertSame('TAS', $child->department);
        $this->assertSame('DS', $child->sub_unit);
    }

    public function test_activity_template_update_keeps_nested_sub_tasks(): void
    {
        $template = ProjectTemplate::create([
            'name' => 'Nested NSO',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $parent = $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'qty' => 1,
            'default_duration_days' => 2,
            'order' => 1,
        ]);

        $child = $template->activities()->create([
            'parent_activity_template_id' => $parent->id,
            'activity' => 'Configure menu',
            'milestone' => 'POS',
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 1,
        ]);

        $this->actingAs(User::factory()->create())
            ->put(route('activity-templates.update', $template), [
                'name' => 'Updated Nested NSO',
                'project_type' => 'NSO',
                'store_class' => 'Regular',
                'activities' => [
                    [
                        'id' => $parent->id,
                        'client_key' => 'parent-1',
                        'parent_client_key' => null,
                        'activity' => 'Install POS Updated',
                        'milestone' => 'POS',
                        'asset_item' => null,
                        'model_specs' => null,
                        'qty' => 1,
                        'responsible' => null,
                        'department' => 'TAS',
                        'sub_unit' => 'BS',
                        'default_duration_days' => 2,
                        'order' => 1,
                    ],
                    [
                        'id' => $child->id,
                        'client_key' => 'child-1',
                        'parent_client_key' => 'parent-1',
                        'activity' => 'Configure menu Updated',
                        'milestone' => 'POS',
                        'asset_item' => null,
                        'model_specs' => null,
                        'qty' => 1,
                        'responsible' => null,
                        'department' => '',
                        'sub_unit' => '',
                        'default_duration_days' => 1,
                        'order' => 1,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_templates', [
            'id' => $parent->id,
            'activity' => 'Install POS Updated',
            'parent_activity_template_id' => null,
            'department' => 'TAS',
            'sub_unit' => 'BS',
        ]);

        $this->assertDatabaseHas('activity_templates', [
            'id' => $child->id,
            'activity' => 'Configure menu Updated',
            'parent_activity_template_id' => $parent->id,
            'department' => 'TAS',
            'sub_unit' => 'BS',
        ]);
    }

    public function test_applying_nested_template_creates_project_parent_and_sub_task(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $template = ProjectTemplate::create([
            'name' => 'Nested NSO',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $parentActivity = $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'qty' => 1,
            'department' => 'TAS',
            'sub_unit' => 'DS',
            'default_duration_days' => 2,
            'order' => 1,
        ]);

        $template->activities()->create([
            'parent_activity_template_id' => $parentActivity->id,
            'activity' => 'Configure menu',
            'milestone' => 'POS',
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $parentTask = ProjectTask::where('project_id', $project->id)
            ->where('name', 'Install POS')
            ->firstOrFail();

        $this->assertSame('TAS', $parentTask->department);
        $this->assertSame('DS', $parentTask->sub_unit);

        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $project->id,
            'parent_task_id' => $parentTask->id,
            'name' => 'Configure menu',
        ]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, ProjectTask::where('project_id', $project->id)->count());
    }

    public function test_reapplying_template_refreshes_project_activity_sort_order(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $template = ProjectTemplate::create([
            'name' => 'Sorted NSO',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $firstActivity = $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'qty' => 1,
            'default_duration_days' => 2,
            'order' => 1,
        ]);

        $secondActivity = $template->activities()->create([
            'activity' => 'Network Setup',
            'milestone' => 'Network',
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 2,
        ]);

        $childActivity = $template->activities()->create([
            'parent_activity_template_id' => $firstActivity->id,
            'activity' => 'Configure menu',
            'milestone' => 'POS',
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $firstTask = ProjectTask::where('project_id', $project->id)->where('name', 'Install POS')->firstOrFail();
        $secondTask = ProjectTask::where('project_id', $project->id)->where('name', 'Network Setup')->firstOrFail();
        $childTask = ProjectTask::where('project_id', $project->id)->where('name', 'Configure menu')->firstOrFail();

        $firstTask->update(['order' => 50]);
        $secondTask->update(['order' => 10]);
        $childTask->update(['order' => 25]);

        $firstActivity->update(['order' => 3]);
        $secondActivity->update(['order' => 1]);
        $childActivity->update(['order' => 2]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Reapplied "Sorted NSO" template sort order successfully.');

        $this->assertSame(3, $firstTask->refresh()->order);
        $this->assertSame(1, $secondTask->refresh()->order);
        $this->assertSame(2, $childTask->refresh()->order);
        $this->assertSame(3, ProjectTask::where('project_id', $project->id)->count());
    }

    public function test_applying_template_preserves_milestone_order_when_activity_orders_tie(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $template = ProjectTemplate::create([
            'name' => 'Tied Milestone Orders',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $template->activities()->create([
            'activity' => 'Network Setup',
            'milestone' => 'Network',
            'milestone_order' => 1,
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 1,
        ]);

        $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'milestone_order' => 2,
            'qty' => 1,
            'default_duration_days' => 1,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $this->assertSame(
            ['Network', 'POS'],
            ProjectTask::where('project_id', $project->id)
                ->whereNull('parent_task_id')
                ->orderBy('milestone_order')
                ->orderBy('order')
                ->pluck('category')
                ->all()
        );

        ProjectTask::where('project_id', $project->id)->where('category', 'Network')->update(['milestone_order' => 50]);
        ProjectTask::where('project_id', $project->id)->where('category', 'POS')->update(['milestone_order' => 10]);

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, ProjectTask::where('project_id', $project->id)->where('category', 'Network')->firstOrFail()->milestone_order);
        $this->assertSame(2, ProjectTask::where('project_id', $project->id)->where('category', 'POS')->firstOrFail()->milestone_order);
    }

    public function test_deleting_project_milestone_deletes_all_category_tasks_and_sub_tasks(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);

        $posTask = ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Install POS',
            'category' => 'POS',
            'milestone_order' => 1,
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $subTask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_task_id' => $posTask->id,
            'name' => 'Configure menu',
            'category' => 'POS',
            'milestone_order' => 1,
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $otherTask = ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Network Setup',
            'category' => 'Network',
            'milestone_order' => 2,
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('projects.milestones.destroy', $project), [
                'category' => 'POS',
            ])
            ->assertRedirect();

        $this->assertSoftDeleted('project_tasks', ['id' => $posTask->id]);
        $this->assertSoftDeleted('project_tasks', ['id' => $subTask->id]);
        $this->assertDatabaseHas('project_tasks', [
            'id' => $otherTask->id,
            'deleted_at' => null,
        ]);
    }

    public function test_sub_task_parent_must_belong_to_same_project(): void
    {
        $user = User::factory()->create();
        $firstProject = $this->createProject('First Store', $user);
        $secondProject = $this->createProject('Second Store', $user);

        $parentTask = ProjectTask::create([
            'project_id' => $firstProject->id,
            'name' => 'Parent activity',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->from(route('projects.show', $secondProject))
            ->post(route('projects-tasks.store'), [
                'project_id' => $secondProject->id,
                'parent_task_id' => $parentTask->id,
                'name' => 'Invalid sub-task',
                'category' => 'POS',
                'status' => 'Pending',
                'progress' => 0,
            ])
            ->assertSessionHasErrors('parent_task_id');
    }

    public function test_deleting_parent_project_activity_deletes_sub_tasks(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);

        $parentTask = ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Parent activity',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $subTask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_task_id' => $parentTask->id,
            'name' => 'Sub-task',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('projects-tasks.destroy', $parentTask))
            ->assertRedirect();

        $this->assertSoftDeleted('project_tasks', ['id' => $parentTask->id]);
        $this->assertSoftDeleted('project_tasks', ['id' => $subTask->id]);
    }

    private function createProject(string $storeName = 'Test Store', ?User $owner = null): Project
    {
        $store = Store::create([
            'code' => strtoupper(substr(md5($storeName), 0, 8)),
            'name' => $storeName,
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'class' => 'Regular',
            'is_active' => true,
        ]);

        return Project::create([
            'store_id' => $store->id,
            'name' => $storeName . ' Project',
            'status' => 'Planning',
            // The creator owns the project and may manage its full structure.
            'created_by' => $owner?->id,
        ]);
    }
}
