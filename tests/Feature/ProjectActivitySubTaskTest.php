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
        ]);

        $this->assertDatabaseHas('activity_templates', [
            'id' => $child->id,
            'activity' => 'Configure menu Updated',
            'parent_activity_template_id' => $parent->id,
        ]);
    }

    public function test_applying_nested_template_creates_project_parent_and_sub_task(): void
    {
        $project = $this->createProject();
        $template = ProjectTemplate::create([
            'name' => 'Nested NSO',
            'project_type' => 'NSO',
            'store_class' => 'Regular',
        ]);

        $parentActivity = $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'qty' => 1,
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

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.apply-templates', $project), [
                'project_template_id' => $template->id,
            ])
            ->assertRedirect();

        $parentTask = ProjectTask::where('project_id', $project->id)
            ->where('name', 'Install POS')
            ->firstOrFail();

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

    public function test_sub_task_parent_must_belong_to_same_project(): void
    {
        $firstProject = $this->createProject('First Store');
        $secondProject = $this->createProject('Second Store');

        $parentTask = ProjectTask::create([
            'project_id' => $firstProject->id,
            'name' => 'Parent activity',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $this->actingAs(User::factory()->create())
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
        $project = $this->createProject();

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

        $this->actingAs(User::factory()->create())
            ->delete(route('projects-tasks.destroy', $parentTask))
            ->assertRedirect();

        $this->assertSoftDeleted('project_tasks', ['id' => $parentTask->id]);
        $this->assertSoftDeleted('project_tasks', ['id' => $subTask->id]);
    }

    private function createProject(string $storeName = 'Test Store'): Project
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
        ]);
    }
}
