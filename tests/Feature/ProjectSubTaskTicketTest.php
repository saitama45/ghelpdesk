<?php

namespace Tests\Feature;

use App\Jobs\SendTicketCreationNotifications;
use App\Models\Company;
use App\Models\Item;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProjectSubTaskTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sub_task_can_have_multiple_tickets_with_sla_details(): void
    {
        Queue::fake();
        $company = Company::create([
            'name' => 'Test Entity',
            'code' => 'TST',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $store = Store::create([
            'company_id' => $company->id,
            'code' => 'STORE-1',
            'name' => 'Test Store',
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'class' => 'Regular',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);

        foreach (['tickets.create', 'tickets.view', 'projects.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $user->givePermissionTo(['tickets.create', 'tickets.view', 'projects.view']);

        $project = Project::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'name' => 'Project One',
            'status' => 'Planning',
            'created_by' => $user->id,
        ]);
        $activity = ProjectTask::create([
            'project_id' => $project->id,
            'name' => 'Install equipment',
            'category' => 'Deployment',
            'order' => 1,
        ]);
        $subTask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_task_id' => $activity->id,
            'name' => 'Configure terminal',
            'category' => 'Deployment',
            'order' => 1,
        ]);
        $item = Item::create([
            'name' => 'Terminal configuration',
            'priority' => 'High',
            'concern_type' => 'Service Request',
            'is_active' => true,
        ]);

        $payload = [
            'project_task_id' => $subTask->id,
            'company_id' => $company->id,
            'item_id' => $item->id,
            'title' => 'Configure terminal issue',
            'description' => 'Linked from the Gantt sub-task.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'is_self_requester' => true,
            'notify_requester' => false,
        ];

        $this->actingAs($user)
            ->postJson(route('tickets.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('ticket.project_task_id', $subTask->id)
            ->assertJsonPath('ticket.ticket_key', 'TST-1')
            ->assertJsonStructure(['ticket' => ['sla_metric' => ['resolution_target_at']]]);
        $this->actingAs($user)->post(route('tickets.store'), [
            ...$payload,
            'title' => 'Follow-up terminal issue',
        ])->assertSessionHasNoErrors();

        $tickets = Ticket::withoutGlobalScopes()
            ->where('project_task_id', $subTask->id)
            ->with('slaMetric')
            ->get();

        $this->assertCount(2, $tickets);
        $this->assertTrue($tickets->every(fn (Ticket $ticket) => $ticket->slaMetric !== null));
        $this->assertTrue($tickets->every(fn (Ticket $ticket) => $ticket->slaMetric->resolution_target_at !== null));
        Queue::assertPushed(SendTicketCreationNotifications::class, 2);

        $this->actingAs($user)
            ->get(route('projects.show', ['project' => $project, 'tab' => 'gantt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->has('projectProgressHistory', 1)
                ->where('projectProgressHistory.0.project_task_id', $subTask->id)
                ->has('project.tasks', 2)
                ->where('project.tasks.1.id', $subTask->id)
                ->has('project.tasks.1.tickets', 2)
                ->has('project.tasks.1.tickets.0.sla_metric'));
    }

    public function test_a_ticket_cannot_be_linked_to_a_top_level_activity(): void
    {
        $company = Company::create(['name' => 'Test Entity', 'code' => 'TST', 'is_active' => true]);
        $store = Store::create([
            'company_id' => $company->id,
            'code' => 'STORE-1',
            'name' => 'Test Store',
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'class' => 'Regular',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        Permission::findOrCreate('tickets.create');
        Permission::findOrCreate('projects.view');
        $user->givePermissionTo(['tickets.create', 'projects.view']);
        $project = Project::create(['company_id' => $company->id, 'store_id' => $store->id, 'name' => 'Project']);
        $activity = ProjectTask::create(['project_id' => $project->id, 'name' => 'Top-level activity']);
        $item = Item::create(['name' => 'Support item', 'priority' => 'Medium', 'is_active' => true]);

        $this->actingAs($user)->post(route('tickets.store'), [
            'project_task_id' => $activity->id,
            'company_id' => $company->id,
            'item_id' => $item->id,
            'title' => 'Invalid link',
            'status' => 'open',
        ])->assertSessionHasErrors('project_task_id');

        $this->assertDatabaseCount('tickets', 0);
    }
}
