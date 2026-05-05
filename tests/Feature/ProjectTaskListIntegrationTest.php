<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTaskListIntegrationTest extends TestCase
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

    public function test_opening_project_task_list_creates_one_board_and_cards_for_activities_and_sub_tasks(): void
    {
        $project = $this->createProject();
        $parentTask = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $parentTask->id,
            'category' => 'POS',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.task-list', $project))
            ->assertRedirect();

        $board = TaskBoard::where('project_id', $project->id)->firstOrFail();

        $this->assertDatabaseHas('task_cards', [
            'task_board_id' => $board->id,
            'project_task_id' => $parentTask->id,
            'title' => 'Install POS',
        ]);
        $this->assertDatabaseHas('task_cards', [
            'task_board_id' => $board->id,
            'project_task_id' => $subTask->id,
            'title' => 'Configure menu',
        ]);

        $this->actingAs($user)
            ->post(route('projects.task-list', $project))
            ->assertRedirect();

        $this->assertSame(1, TaskBoard::where('project_id', $project->id)->count());
        $this->assertSame(2, TaskCard::where('task_board_id', $board->id)->count());
    }

    public function test_moving_project_card_to_verification_updates_project_task_to_ongoing_ninety_percent(): void
    {
        $project = $this->createProject();
        $task = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.task-list', $project));

        $card = TaskCard::where('project_task_id', $task->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('task-cards.move', $card), [
                'status' => 'For Verification',
                'ordered_card_ids' => [$card->id],
            ])
            ->assertOk();

        $task->refresh();
        $card->refresh();

        $this->assertSame('Ongoing', $task->status);
        $this->assertSame(90, $task->progress);
        $this->assertSame('For Verification', $card->status);
    }

    public function test_gantt_updates_sync_linked_project_card_fields(): void
    {
        $project = $this->createProject();
        $task = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.task-list', $project));

        $this->actingAs($user)
            ->postJson(route('projects.tasks.gantt-update'), [
                'tasks' => [
                    [
                        'id' => $task->id,
                        'start_date' => '2026-05-10',
                        'end_date' => '2026-05-12',
                        'progress' => 100,
                        'order' => 5,
                    ],
                ],
            ])
            ->assertOk();

        $card = TaskCard::where('project_task_id', $task->id)->firstOrFail();

        $this->assertSame('Done', $card->status);
        $this->assertSame('2026-05-10 00:00:00', $card->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 23:59:59', $card->due_at->format('Y-m-d H:i:s'));
    }

    public function test_deleting_parent_project_activity_archives_linked_parent_and_sub_task_cards(): void
    {
        $project = $this->createProject();
        $parentTask = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $parentTask->id,
            'category' => 'POS',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.task-list', $project));

        $this->actingAs($user)
            ->delete(route('projects-tasks.destroy', $parentTask))
            ->assertRedirect();

        $this->assertNotNull(TaskCard::where('project_task_id', $parentTask->id)->firstOrFail()->archived_at);
        $this->assertNotNull(TaskCard::where('project_task_id', $subTask->id)->firstOrFail()->archived_at);
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

    private function createProjectTask(Project $project, string $name, array $overrides = []): ProjectTask
    {
        return ProjectTask::create([
            'project_id' => $project->id,
            'name' => $name,
            'category' => 'General',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
            ...$overrides,
        ]);
    }
}
