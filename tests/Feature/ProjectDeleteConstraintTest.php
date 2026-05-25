<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\TaskChecklist;
use App\Models\TaskChecklistItem;
use App\Models\TaskCardAttachment;
use App\Models\TaskCardComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectDeleteConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    public function test_deleting_project_successfully_cleans_up_task_cards_and_dependencies(): void
    {
        $company = Company::create(['name' => 'TAS Company', 'code' => 'TAS', 'is_active' => true]);
        $store = Store::create([
            'code' => 'STORE1',
            'name' => 'Store 1',
            'sector' => 1,
            'area' => 'Area 1',
            'brand' => 'Brand 1',
            'cluster' => 'Cluster 1',
            'class' => 'Regular',
            'is_active' => true,
        ]);

        $project = Project::create([
            'store_id' => $store->id,
            'name' => 'Store 1 Project',
            'status' => 'Planning',
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        \Spatie\Permission\Models\Permission::create(['name' => 'projects.delete']);
        $user->givePermissionTo('projects.delete');
        $this->actingAs($user);

        // Create TaskBoard
        $taskBoard = TaskBoard::create([
            'project_id' => $project->id,
            'title' => 'Store 1 Board',
            'created_by' => $user->id,
        ]);

        // Create ProjectTask
        $parentTask = ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Parent Task',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $subTask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_task_id' => $parentTask->id,
            'name' => 'Sub Task',
            'category' => 'POS',
            'status' => 'Pending',
            'progress' => 0,
            'order' => 1,
        ]);

        // Create TaskCard
        $taskCard = TaskCard::create([
            'task_board_id' => $taskBoard->id,
            'project_id' => $project->id,
            'project_task_id' => $subTask->id,
            'title' => 'POS Terminals Setup',
            'status' => 'Backlogs',
            'created_by' => $user->id,
        ]);

        // Create TaskCard relations
        $taskCard->assignees()->attach($user->id);
        $taskCard->watchers()->attach($user->id);

        $comment = TaskCardComment::create([
            'task_card_id' => $taskCard->id,
            'user_id' => $user->id,
            'comment_text' => 'Starting POS setup soon.',
        ]);

        $attachment = TaskCardAttachment::create([
            'task_card_id' => $taskCard->id,
            'user_id' => $user->id,
            'file_name' => 'specs.pdf',
            'file_storage_path' => 'specs.pdf',
        ]);

        // Create Checklist
        $checklist = TaskChecklist::create([
            'task_card_id' => $taskCard->id,
            'title' => 'Setup steps',
        ]);

        $checklistItem = TaskChecklistItem::create([
            'task_checklist_id' => $checklist->id,
            'title' => 'Verify spec sheet',
        ]);

        // Create Activity
        DB::table('task_card_activities')->insert([
            'task_board_id' => $taskBoard->id,
            'task_card_id' => $taskCard->id,
            'actor_id' => $user->id,
            'action' => 'card.created',
            'description' => 'created this card',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify databases have the elements before deletion
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('task_cards', ['id' => $taskCard->id]);

        // Delete the Project
        $response = $this->delete(route('projects.destroy', $project));
        $response->assertRedirect(route('projects.index'));

        // Verify the Project and Task Board have been force-deleted
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('task_boards', ['id' => $taskBoard->id]);

        // Verify everything else is force-deleted and does not conflict
        $this->assertDatabaseMissing('task_cards', ['id' => $taskCard->id]);
        $this->assertDatabaseMissing('task_card_assignees', ['task_card_id' => $taskCard->id]);
        $this->assertDatabaseMissing('task_card_watchers', ['task_card_id' => $taskCard->id]);
        $this->assertDatabaseMissing('task_checklists', ['id' => $checklist->id]);
        $this->assertDatabaseMissing('task_checklist_items', ['id' => $checklistItem->id]);
        $this->assertDatabaseMissing('task_card_comments', ['id' => $comment->id]);
        $this->assertDatabaseMissing('task_card_attachments', ['id' => $attachment->id]);

        // Check project tasks are force-deleted
        $this->assertDatabaseMissing('project_tasks', ['id' => $parentTask->id]);
        $this->assertDatabaseMissing('project_tasks', ['id' => $subTask->id]);
    }
}
