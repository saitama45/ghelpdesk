<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use App\Services\MilestoneActivityImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Per-milestone Excel import on /projects/{id}?tab=gantt.
 * Uses References/gantt_milestone_import_template.xlsx as the real fixture.
 */
class ProjectMilestoneImportTest extends TestCase
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

        return Project::create([
            'store_id' => $store->id,
            'name' => 'Project',
            'status' => 'Planning',
            'created_by' => $owner->id,
            'day1_date' => '2026-09-14',
        ]);
    }

    private function milestone(Project $project, string $category, ?User $owner = null): void
    {
        ProjectMilestone::create(['project_id' => $project->id, 'category' => $category, 'assigned_to' => $owner?->id]);
    }

    private function referenceFile(): UploadedFile
    {
        return new UploadedFile(base_path('References/gantt_milestone_import_template.xlsx'), 'template.xlsx', null, null, true);
    }

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'msimp').'.xlsx';
        (new Xlsx(app(MilestoneActivityImportService::class)->buildTemplate('X', $rows)))->save($path);

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    public function test_reference_template_imports_into_the_chosen_milestone_and_reimport_updates(): void
    {
        $manager = User::factory()->create();
        $project = $this->project($manager);
        $this->milestone($project, 'UAT');
        $this->milestone($project, 'OTHER');

        $this->actingAs($manager)
            ->postJson(route('projects.milestones.import', $project), ['category' => 'UAT', 'file' => $this->referenceFile()])
            ->assertOk()
            ->assertJsonPath('message', 'Imported into UAT: 18 added, 0 updated.');

        $tasks = ProjectTask::where('project_id', $project->id)->get()->keyBy('name');
        $this->assertCount(18, $tasks);
        $this->assertTrue($tasks->every(fn ($task) => $task->category === 'UAT'));

        $parent = $tasks['Confirm stakeholder and decision-maker list'];
        $sibling = $tasks['Select store manager and head-office admin representatives'];
        $this->assertSame($parent->id, $sibling->parent_task_id);
        $this->assertSame($tasks['Select cashier and barista representatives']->id, $sibling->depends_on_task_id);
        $this->assertTrue($sibling->can_run_parallel);
        $this->assertSame(2, $parent->lead_time_days);
        $this->assertNotNull($tasks['Obtain formal UAT sign-off']->start_date);

        $this->actingAs($manager)
            ->postJson(route('projects.milestones.import', $project), ['category' => 'UAT', 'file' => $this->referenceFile()])
            ->assertOk()
            ->assertJsonPath('message', 'Imported into UAT: 0 added, 18 updated.');

        $this->assertSame(18, ProjectTask::where('project_id', $project->id)->count());
    }

    public function test_an_invalid_row_rejects_the_whole_file(): void
    {
        $manager = User::factory()->create();
        $project = $this->project($manager);
        $this->milestone($project, 'UAT');

        $response = $this->actingAs($manager)->postJson(route('projects.milestones.import', $project), [
            'category' => 'UAT',
            'file' => $this->workbook([
                ['Code' => 'A', 'Name' => 'Good row', 'Lead Time (Days)' => 2],
                ['Code' => 'B', 'Parent Code' => 'A', 'Name' => 'Bad dep', 'Lead Time (Days)' => 1, 'Depends On Code' => 'A,Z'],
                ['Code' => 'C', 'Name' => 'Bad lead', 'Lead Time (Days)' => 0],
            ]),
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.file');
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Row 3', $errors[0]);
        $this->assertStringContainsString('Row 4', $errors[1]);
        $this->assertSame(0, ProjectTask::where('project_id', $project->id)->count());
    }

    public function test_only_the_milestone_owner_or_manager_may_import(): void
    {
        $manager = User::factory()->create();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->project($manager);
        $this->milestone($project, 'UAT', $owner);
        $this->milestone($project, 'OTHER', $outsider);
        $file = fn () => $this->workbook([['Code' => 'A', 'Name' => 'Row', 'Lead Time (Days)' => 1]]);

        $this->actingAs($outsider)
            ->postJson(route('projects.milestones.import', $project), ['category' => 'UAT', 'file' => $file()])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('projects.milestones.import', $project), ['category' => 'UAT', 'file' => $file()])
            ->assertOk();

        $this->actingAs($manager)
            ->postJson(route('projects.milestones.import', $project), ['category' => 'MISSING', 'file' => $file()])
            ->assertStatus(422);

        $this->assertSame(1, ProjectTask::where('project_id', $project->id)->count());
    }
}
