<?php

namespace Tests\Feature;

use App\Models\{Company, Department, Role, Ticket, TicketSlaMetric, TicketStatus, TicketStatusVisibility, User};
use App\Support\{CompanyContext, DepartmentContext};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TicketStatusVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $tas;
    private Department $fm;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Model::unguarded(function () {
            $this->company = Company::create(['name' => 'Entity', 'code' => 'TGI', 'is_active' => true]);
            $this->tas = Department::create(['name' => 'TAS', 'code' => 'TAS', 'company_id' => $this->company->id, 'is_active' => true]);
            $this->fm = Department::create(['name' => 'FM', 'code' => 'FM', 'company_id' => $this->company->id, 'is_active' => true]);
        });
        $this->admin = $this->user($this->tas);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
    }

    private function user(Department $department, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'department_id' => $department->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function as(User $user, Department $viewed): static
    {
        return $this->actingAs($user)->withSession([
            CompanyContext::SESSION_KEY => $this->company->id,
            DepartmentContext::SESSION_KEY => $viewed->id,
        ]);
    }

    private function ticket(Department $department, string $status = 'open'): Ticket
    {
        return Model::unguarded(fn () => Ticket::withoutEvents(fn () => Ticket::create([
            'title' => 'Help', 'status' => $status, 'priority' => 'low', 'company_id' => $this->company->id,
            'ticket_key' => 'TGI-'.random_int(1000, 99999), 'reporter_id' => $this->admin->id,
            'serving_department_id' => $department->id,
        ])));
    }

    private function hide(Department $department, string $status): void
    {
        TicketStatusVisibility::create(['department_id' => $department->id, 'status' => $status, 'is_visible' => false]);
    }

    public function test_matrix_lists_departments_and_toggles_one_cell(): void
    {
        $this->as($this->admin, $this->tas)->get(route('ticket-statuses.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('TicketStatuses/Index')->has('departments', 2)->has('statuses', 7));

        $this->put(route('ticket-statuses.visibility'), ['department_id' => $this->fm->id, 'status' => 'for_schedule', 'is_visible' => false])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_status_visibilities', ['department_id' => $this->fm->id, 'status' => 'for_schedule', 'is_visible' => false]);

        // Lifecycle statuses cannot be hidden.
        $this->put(route('ticket-statuses.visibility'), ['department_id' => $this->fm->id, 'status' => 'open', 'is_visible' => false])
            ->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('ticket_status_visibilities', ['status' => 'open']);
    }

    public function test_restricted_editor_manages_only_their_home_department(): void
    {
        $editor = $this->user($this->tas, ['ticket_statuses.view', 'ticket_statuses.edit']);
        $this->as($editor, $this->tas)->put(route('ticket-statuses.visibility'), ['department_id' => $this->fm->id, 'status' => 'in_progress', 'is_visible' => false])
            ->assertForbidden();
        $this->put(route('ticket-statuses.visibility'), ['department_id' => $this->tas->id, 'status' => 'in_progress', 'is_visible' => false])
            ->assertSessionHasNoErrors();

        $viewer = $this->user($this->tas, ['ticket_statuses.view']);
        $this->as($viewer, $this->tas)->get(route('ticket-statuses.index'))->assertOk();
        $this->put(route('ticket-statuses.visibility'), ['department_id' => $this->tas->id, 'status' => 'in_progress', 'is_visible' => true])
            ->assertForbidden();

        $this->as($this->user($this->tas), $this->tas)->get(route('ticket-statuses.index'))->assertForbidden();
    }

    public function test_hidden_status_is_refused_for_that_department_only(): void
    {
        $this->hide($this->tas, 'waiting_client_feedback');
        $tasTicket = $this->ticket($this->tas);
        $fmTicket = $this->ticket($this->fm);

        $this->as($this->admin, $this->tas)
            ->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$tasTicket->id], 'status' => 'waiting_client_feedback'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('open', $tasTicket->fresh()->status);

        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$fmTicket->id], 'status' => 'waiting_client_feedback'])
            ->assertSessionHasNoErrors();
        $this->assertSame('waiting_client_feedback', $fmTicket->fresh()->status);

        $this->get(route('tickets.edit', $tasTicket))
            ->assertInertia(fn ($page) => $page->where('hiddenTicketStatuses', ['waiting_client_feedback']));
    }

    public function test_ticket_already_in_a_hidden_status_keeps_it(): void
    {
        $ticket = $this->ticket($this->tas, 'in_progress');
        $this->hide($this->tas, 'in_progress');

        $this->as($this->admin, $this->tas)
            ->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$ticket->id], 'status' => 'in_progress'])
            ->assertSessionHasNoErrors();
    }

    public function test_child_tickets_need_their_own_permission(): void
    {
        $ticket = $this->ticket($this->tas);
        foreach (['tickets.view', 'tickets.edit'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $editor = $this->user($this->tas, ['tickets.view', 'tickets.edit']);

        $this->as($editor, $this->tas)->post(route('tickets.store-child', $ticket), [])->assertForbidden();
        $this->postJson(route('tickets.bulk-child'), ['tickets' => []])->assertForbidden();

        $editor->givePermissionTo('tickets.create_child');
        // Past the permission gate: now it fails on the empty payload instead.
        $this->as($editor->fresh(), $this->tas)->postJson(route('tickets.store-child', $ticket), [])->assertUnprocessable();
    }

    public function test_custom_status_is_created_for_chosen_departments_only(): void
    {
        $this->as($this->admin, $this->tas)->post(route('ticket-statuses.store'), [
            'label' => 'Waiting for Parts', 'color' => 'amber', 'behaves_like' => 'waiting_service_provider',
            'department_ids' => [$this->tas->id],
        ])->assertSessionHasNoErrors();

        $status = TicketStatus::where('label', 'Waiting for Parts')->firstOrFail();
        $this->assertSame('waiting_for_parts', $status->key);
        $this->assertFalse($status->is_system);

        // Duplicate names are refused, case-insensitively.
        $this->post(route('ticket-statuses.store'), ['label' => 'waiting for parts', 'color' => 'red', 'behaves_like' => 'open'])
            ->assertSessionHasErrors('label');

        $tasTicket = $this->ticket($this->tas);
        $fmTicket = $this->ticket($this->fm);
        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$fmTicket->id], 'status' => 'waiting_for_parts'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$tasTicket->id], 'status' => 'waiting_for_parts'])
            ->assertSessionHasNoErrors();
        $this->assertSame('waiting_for_parts', $tasTicket->fresh()->status);

        // Unknown statuses are refused outright.
        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$tasTicket->id], 'status' => 'made_up'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_custom_status_pauses_sla_like_the_status_it_behaves_like(): void
    {
        TicketStatus::create(['key' => 'for_parts', 'label' => 'For Parts', 'color' => 'amber',
            'behaves_like' => 'waiting_service_provider', 'is_system' => false, 'sort_order' => 100]);
        TicketStatusVisibility::create(['department_id' => $this->tas->id, 'status' => 'for_parts', 'is_visible' => true]);
        $ticket = $this->ticket($this->tas, 'in_progress');
        TicketSlaMetric::create(['ticket_id' => $ticket->id, 'response_target_at' => now()->addDay(), 'resolution_target_at' => now()->addDays(2)]);

        $this->as($this->admin, $this->tas)
            ->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$ticket->id], 'status' => 'for_parts'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(TicketSlaMetric::where('ticket_id', $ticket->id)->value('paused_at'));
        $this->assertContains('for_parts', \App\Support\TicketStatuses::like(['waiting_service_provider']));
    }

    public function test_rename_changes_label_not_key_and_needs_edit_permission(): void
    {
        $open = TicketStatus::where('key', 'open')->firstOrFail();
        $this->as($this->admin, $this->tas)->put(route('ticket-statuses.update', $open), ['label' => 'New', 'color' => 'cyan'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ticket_statuses', ['id' => $open->id, 'key' => 'open', 'label' => 'New', 'color' => 'cyan', 'behaves_like' => null]);

        $this->get(route('tickets.index'))->assertInertia(fn ($page) => $page->where('ticketStatuses.0.label', 'New'));

        $viewer = $this->user($this->tas, ['ticket_statuses.view']);
        $this->as($viewer, $this->tas)->put(route('ticket-statuses.update', $open), ['label' => 'Nope', 'color' => 'red'])->assertForbidden();
        $this->post(route('ticket-statuses.store'), ['label' => 'Nope', 'color' => 'red', 'behaves_like' => 'open'])->assertForbidden();
    }

}
