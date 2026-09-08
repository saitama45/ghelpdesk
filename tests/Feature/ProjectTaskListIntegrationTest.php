<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\TaskChecklistItem;
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

    public function test_opening_project_task_list_populates_monthly_boards_with_project_card_checklists_and_subtasks(): void
    {
        $project = $this->createProject();
        $teamUsers = $this->createProjectTeamTargets($project, ['DS', 'BS', 'SD']);
        $parentTask = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $parentTask->id,
            'category' => 'POS',
            'assigned_to' => $teamUsers['DS']->id,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.task-board', $project))
            ->assertRedirect();

        foreach (['DS', 'BS', 'SD'] as $subUnit) {
            $board = TaskBoard::where([
                'board_source' => 'monthly',
                'department' => 'TAS',
                'sub_unit' => $subUnit,
                'board_month' => 5,
                'board_year' => 2026,
                'title' => "{$subUnit} May 2026",
            ])->firstOrFail();

            $card = TaskCard::where([
                'task_board_id' => $board->id,
                'project_id' => $project->id,
                'title' => $project->name,
            ])->firstOrFail();

            $checklist = $card->checklists()->where('title', 'POS')->firstOrFail();
            $activityItem = TaskChecklistItem::where([
                'task_checklist_id' => $checklist->id,
                'project_task_id' => $parentTask->id,
                'title' => 'Install POS',
            ])->whereNull('parent_item_id')->firstOrFail();

            TaskChecklistItem::where([
                'task_checklist_id' => $checklist->id,
                'parent_item_id' => $activityItem->id,
                'project_task_id' => $subTask->id,
                'title' => 'Configure menu',
                'assigned_to' => $teamUsers['DS']->id,
            ])->firstOrFail();
        }

        $this->actingAs($user)
            ->post(route('projects.task-board', $project))
            ->assertRedirect();

        $this->assertSame(3, TaskBoard::where('board_source', 'monthly')->where('department', 'TAS')->count());
        $this->assertSame(3, TaskCard::where('project_id', $project->id)->count());
        $this->assertSame(6, TaskChecklistItem::whereIn('project_task_id', [$parentTask->id, $subTask->id])->count());
    }

    public function test_updating_checklist_subtask_assignment_syncs_project_task_and_returns_assignee_sub_unit(): void
    {
        $project = $this->createProject();
        $teamUsers = $this->createProjectTeamTargets($project, ['DS', 'SD']);
        $task = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $task->id,
            'category' => 'POS',
            'assigned_to' => $teamUsers['DS']->id,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.task-board', $project));

        $item = TaskChecklistItem::where('project_task_id', $subTask->id)->firstOrFail();

        $this->actingAs($user)
            ->putJson(route('task-checklist-items.update', $item), [
                'assigned_to' => $teamUsers['SD']->id,
                'is_complete' => true,
            ])
            ->assertOk()
            ->assertJsonPath('card.checklists.0.items.0.children.0.assigned_to', $teamUsers['SD']->id)
            ->assertJsonPath('card.checklists.0.items.0.children.0.assignee.org_path', 'SD');

        $subTask->refresh();

        $this->assertSame($teamUsers['SD']->id, $subTask->assigned_to);
        $this->assertSame('Done', $subTask->status);
        $this->assertSame(100, $subTask->progress);
    }

    public function test_gantt_updates_sync_linked_checklist_item_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $task = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);

        $this->actingAs($user)->post(route('projects.task-board', $project));

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
                'auto_create_monthly_boards' => true,
            ])
            ->assertOk();

        $item = TaskChecklistItem::where('project_task_id', $task->id)->firstOrFail();

        $this->assertTrue($item->is_complete);
        $this->assertSame('2026-05-12 23:59:59', $item->due_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, $item->sort_order);
    }

    public function test_deleting_parent_project_activity_removes_linked_checklist_items_from_monthly_cards(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $parentTask = $this->createProjectTask($project, 'Install POS', ['category' => 'POS']);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $parentTask->id,
            'category' => 'POS',
        ]);

        $this->actingAs($user)->post(route('projects.task-board', $project));

        $this->actingAs($user)
            ->delete(route('projects-tasks.destroy', $parentTask))
            ->assertRedirect();

        $this->assertSame(0, TaskChecklistItem::whereIn('project_task_id', [$parentTask->id, $subTask->id])->count());
        $this->assertSame(1, TaskCard::where('project_id', $project->id)->count());
    }

    public function test_deleting_project_milestone_removes_linked_checklist_items_from_monthly_cards(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $parentTask = $this->createProjectTask($project, 'Install POS', [
            'category' => 'POS',
            'milestone_order' => 1,
        ]);
        $subTask = $this->createProjectTask($project, 'Configure menu', [
            'parent_task_id' => $parentTask->id,
            'category' => 'POS',
            'milestone_order' => 1,
        ]);
        $networkTask = $this->createProjectTask($project, 'Network Setup', [
            'category' => 'Network',
            'milestone_order' => 2,
        ]);

        $this->actingAs($user)->post(route('projects.task-board', $project));

        $this->actingAs($user)
            ->delete(route('projects.milestones.destroy', $project), [
                'category' => 'POS',
                'auto_create_monthly_boards' => true,
            ])
            ->assertRedirect();

        $this->assertSame(0, TaskChecklistItem::whereIn('project_task_id', [$parentTask->id, $subTask->id])->count());
        $this->assertSame(1, TaskChecklistItem::where('project_task_id', $networkTask->id)->count());
        $this->assertSame(1, TaskCard::where('project_id', $project->id)->count());
    }

    public function test_applying_templates_reuses_a_soft_deleted_project_card(): void
    {
        $this->withoutMiddleware(\Spatie\Permission\Middleware\PermissionMiddleware::class);
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $sync = app(\App\Services\ProjectTaskBoardSyncService::class);
        $sync->syncProject($project->fresh(), $user);

        $card = TaskCard::where('project_id', $project->id)->sole();
        $card->delete();

        $template = \App\Models\ProjectTemplate::create([
            'name' => 'Installation',
            'project_type' => $project->fresh()->project_type,
        ]);
        $template->activities()->create([
            'activity' => 'Install POS',
            'milestone' => 'POS',
            'milestone_order' => 1,
            'order' => 1,
            'default_duration_days' => 1,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($user)
                ->post(route('projects.apply-templates', $project), [
                    'project_template_id' => $template->id,
                    'auto_create_monthly_boards' => true,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $this->assertSame(1, TaskCard::withTrashed()->where('project_id', $project->id)->count());
            $this->assertSame($card->id, TaskCard::where('project_id', $project->id)->sole()->id);
            $task = ProjectTask::where('project_id', $project->id)->where('name', 'Install POS')->sole();
            $this->assertDatabaseHas('task_checklist_items', [
                'task_checklist_id' => $card->checklists()->where('title', 'POS')->sole()->id,
                'project_task_id' => $task->id,
                'title' => 'Install POS',
            ]);
        }
    }

    public function test_adding_sub_tasks_reuses_a_deleted_then_active_project_card(): void
    {
        $this->withoutMiddleware(\Spatie\Permission\Middleware\PermissionMiddleware::class);
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $parent = $this->createProjectTask($project, 'Site assessment');
        app(\App\Services\ProjectTaskBoardSyncService::class)->syncProject($project->fresh(), $user);
        $card = TaskCard::where('project_id', $project->id)->sole();
        $card->delete();

        foreach (['First sub-task', 'Second sub-task'] as $name) {
            $this->actingAs($user)->postJson(route('projects-tasks.store'), [
                'project_id' => $project->id,
                'parent_task_id' => $parent->id,
                'name' => $name,
                'status' => 'Pending',
                'lead_time_days' => 1,
                'auto_create_monthly_boards' => true,
            ])->assertCreated();

            $this->assertSame(1, TaskCard::withTrashed()->where('project_id', $project->id)->count());
            $this->assertSame($card->id, TaskCard::where('project_id', $project->id)->sole()->id);
            $task = ProjectTask::where('project_id', $project->id)->where('name', $name)->sole();
            $parentItem = TaskChecklistItem::where('project_task_id', $parent->id)->sole();
            $this->assertDatabaseHas('task_checklist_items', [
                'task_checklist_id' => $parentItem->task_checklist_id,
                'parent_item_id' => $parentItem->id,
                'project_task_id' => $task->id,
            ]);
        }
    }

    public function test_project_card_sync_recovers_when_another_insert_wins_after_lookup(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject('Test Store', $user);
        $this->createProjectTeamTargets($project, ['DS']);
        $winner = null;
        $inserted = false;

        // Insert after the empty SELECT, before the sync attempts its INSERT.
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($project, $user, &$winner, &$inserted) {
            if ($inserted || !str_starts_with($query->sql, 'select')
                || !str_contains($query->sql, 'from "task_cards"')
                || !str_contains($query->sql, 'limit 1')) {
                return;
            }
            $inserted = true;
            $board = TaskBoard::where('board_source', 'monthly')->sole();
            $winner = TaskCard::create([
                'task_board_id' => $board->id,
                'project_id' => $project->id,
                'title' => 'Concurrent card',
                'status' => 'Backlogs',
                'created_by' => $user->id,
            ]);
        });

        app(\App\Services\ProjectTaskBoardSyncService::class)->syncProject($project->fresh(), $user);

        $this->assertNotNull($winner);
        $card = TaskCard::where('project_id', $project->id)->sole();
        $this->assertSame($winner->id, $card->id);
        $this->assertSame($project->name, $card->title);
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
            'board_month' => 5,
            'board_year' => 2026,
            'created_by' => $owner?->id,
        ]);
    }

    private function createProjectTeamTargets(Project $project, array $subUnits): array
    {
        $users = [];

        foreach ($subUnits as $subUnit) {
            $user = User::factory()->create([
                'department' => 'TAS',
                'org_path' => $subUnit,
            ]);

            ProjectTeamMember::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'department' => 'TAS',
                'sub_unit' => $subUnit,
                'role_type' => 'Implementer',
                'team_category' => 'CASA Team',
            ]);

            $users[$subUnit] = $user;
        }

        return $users;
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
