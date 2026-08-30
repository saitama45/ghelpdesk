<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProjectGanttProductivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['projects.view', 'projects.manage_tasks'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_owner_can_bulk_assign_project_team_member_to_selected_rows(): void
    {
        $owner = $this->projectUser();
        $assignee = User::factory()->create();
        $project = $this->project($owner);
        ProjectTeamMember::create([
            'project_id' => $project->id,
            'user_id' => $assignee->id,
            'role_type' => 'Project Member',
            'team_category' => 'Project Team',
        ]);
        $activity = $this->task($project, 'Build API');
        $subTask = $this->task($project, 'Validate API', $activity->id);

        $response = $this->actingAs($owner)->patchJson(route('projects.tasks.bulk-assign', $project), [
            'task_ids' => [$activity->id, $subTask->id],
            'assigned_to' => $assignee->id,
            'only_unassigned' => true,
        ]);

        $response->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame($assignee->id, (int) $activity->fresh()->assigned_to);
        $this->assertSame($assignee->id, (int) $subTask->fresh()->assigned_to);
        $this->assertSame($owner->id, (int) $subTask->fresh()->updated_by);
    }

    public function test_bulk_assignment_rejects_rows_from_another_project(): void
    {
        $owner = $this->projectUser();
        $assignee = User::factory()->create();
        $project = $this->project($owner);
        $otherProject = $this->project($owner);
        ProjectTeamMember::create([
            'project_id' => $project->id,
            'user_id' => $assignee->id,
            'role_type' => 'Project Member',
            'team_category' => 'Project Team',
        ]);

        $this->actingAs($owner)->patchJson(route('projects.tasks.bulk-assign', $project), [
            'task_ids' => [$this->task($otherProject, 'Foreign task')->id],
            'assigned_to' => $assignee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('task_ids');
    }

    public function test_direct_progress_change_is_rejected_for_activity_rolled_up_from_sub_tasks(): void
    {
        $owner = $this->projectUser();
        $project = $this->project($owner);
        $activity = $this->task($project, 'Build API');
        $this->task($project, 'Validate API', $activity->id);

        $this->actingAs($owner)->putJson(route('projects-tasks.update', $activity), [
            'progress' => 50,
            'status' => 'Ongoing',
        ])->assertUnprocessable()->assertJsonValidationErrors('progress');

        $this->assertSame(0, (int) $activity->fresh()->progress);
    }

    public function test_project_gantt_pdf_opens_as_an_inline_landscape_report(): void
    {
        $owner = $this->projectUser();
        $project = $this->project($owner);
        $this->task($project, 'Discovery', null, [
            'category' => 'Planning',
            'progress' => 50,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'milestone_weight' => 100,
            'activity_weight' => 100,
        ]);

        $response = $this->actingAs($owner)->get(route('projects.gantt-pdf', $project));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    private function projectUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['projects.view', 'projects.manage_tasks']);

        return $user;
    }

    private function project(User $owner): Project
    {
        return Project::create([
            'name' => 'DAVID',
            'project_type' => 'General',
            'status' => 'In Progress',
            'created_by' => $owner->id,
        ]);
    }

    private function task(Project $project, string $name, ?int $parentId = null, array $overrides = []): ProjectTask
    {
        return ProjectTask::create(array_merge([
            'project_id' => $project->id,
            'parent_task_id' => $parentId,
            'name' => $name,
            'category' => 'Build',
            'status' => 'Pending',
            'progress' => 0,
            'order' => ProjectTask::where('project_id', $project->id)->count() + 1,
        ], $overrides));
    }
}
