<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketIndexSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_summary_counts_all_matching_tickets_and_quick_filter_is_server_side(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Support',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);

        $assignedUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);

        $newTicket = $this->ticket($company, ['title' => 'New visible ticket']);
        $this->ticket($company, ['title' => 'Assigned open ticket', 'assignee_id' => $assignedUser->id]);
        $this->ticket($company, ['title' => 'Unassigned in progress ticket', 'status' => 'in_progress']);

        $this->actingAs($user)
            ->get(route('tickets.index', ['dashboard_filter' => 'new']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('summaryStats.new', 1)
                ->where('summaryStats.unassigned', 1)
                ->where('filters.dashboard_filter', 'new')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $newTicket->id)
            );
    }

    public function test_so_and_cs_summary_boxes_count_only_tickets_assigned_to_department_users(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $soNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Service Operations',
            'code' => 'SO',
            'is_active' => true,
        ]);

        $csNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Corporate Services',
            'code' => 'CS',
            'is_active' => true,
        ]);

        $viewer = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $soNode->id,
        ]);

        $soUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $soNode->id,
        ]);

        $csUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $csNode->id,
        ]);

        $soUrgent = $this->ticket($company, [
            'title' => 'SO urgent',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assignee_id' => $soUser->id,
        ]);
        $this->ticket($company, [
            'title' => 'SO new assigned',
            'status' => 'open',
            'assignee_id' => $soUser->id,
        ]);
        $this->ticket($company, [
            'title' => 'SO waiting',
            'status' => 'waiting_service_provider',
            'assignee_id' => $soUser->id,
        ]);
        $this->ticket($company, [
            'title' => 'SO closed',
            'status' => 'closed',
            'assignee_id' => $soUser->id,
        ]);

        $this->ticket($company, [
            'title' => 'CS urgent',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assignee_id' => $csUser->id,
        ]);
        $this->ticket($company, [
            'title' => 'Unassigned urgent should not count',
            'priority' => 'urgent',
            'assignee_id' => null,
        ]);
        $this->ticket($company, [
            'title' => 'Unassigned waiting should not count',
            'status' => 'waiting_client_feedback',
            'assignee_id' => null,
        ]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['status' => ['all'], 'skip_default_department' => true]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('summaryStatsByDept.SO.stats.new', 1)
                ->where('summaryStatsByDept.SO.stats.open', 1)
                ->where('summaryStatsByDept.SO.stats.total', 4)
                ->where('summaryStatsByDept.SO.stats.waiting', 1)
                ->where('summaryStatsByDept.SO.stats.urgent', 1)
                ->where('summaryStatsByDept.SO.stats.closed', 1)
                ->where('summaryStatsByDept.CS.stats.new', 0)
                ->where('summaryStatsByDept.CS.stats.open', 0)
                ->where('summaryStatsByDept.CS.stats.total', 1)
                ->where('summaryStatsByDept.CS.stats.waiting', 0)
                ->where('summaryStatsByDept.CS.stats.urgent', 1)
                ->where('summaryStatsByDept.CS.stats.closed', 0)
            );

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'department_node_id' => $soNode->id,
                'dashboard_filter' => 'urgent',
                'skip_default_department' => true,
                'assigned_department_only' => true,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('filters.assigned_department_only', true)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $soUrgent->id)
            );
    }

    public function test_ticket_index_includes_assignee_department_node_for_sector_display(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $sector = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Sector 1',
            'is_active' => true,
        ]);

        $viewer = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);

        $assignee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $sector->id,
        ]);

        $ticket = $this->ticket($company, [
            'assignee_id' => $assignee->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['status' => ['all'], 'skip_default_department' => true]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $ticket->id)
                ->where('tickets.data.0.assignee.department_node_id', $sector->id)
            );
    }

    public function test_ticket_key_filter_shows_only_linked_sla_notification_tickets(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $viewerDepartment = Department::create([
            'name' => 'Viewer Department',
            'is_active' => true,
        ]);
        $otherDepartment = Department::create([
            'name' => 'Other Department',
            'is_active' => true,
        ]);

        $viewer = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $viewerDepartment->id,
        ]);
        $assignee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $otherDepartment->id,
        ]);

        $parent = $this->ticket($company, [
            'ticket_key' => 'SLA-1001',
            'status' => 'in_progress',
            'assignee_id' => $assignee->id,
        ]);
        $this->ticket($company, [
            'ticket_key' => 'SLA-1002',
            'status' => 'waiting_client_feedback',
            'assignee_id' => $assignee->id,
            'parent_id' => $parent->id,
        ]);
        $this->ticket($company, [
            'ticket_key' => 'OTHER-1003',
            'assignee_id' => $assignee->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'ticket_keys' => 'SLA-1001,SLA-1002',
                'status' => ['all'],
                'ticket_scope' => 'all',
                'skip_default_department' => true,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('filters.ticket_keys', 'SLA-1001,SLA-1002')
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($tickets) => collect($tickets)
                    ->pluck('ticket_key')
                    ->sort()
                    ->values()
                    ->all() === ['SLA-1001', 'SLA-1002'])
            );
    }

    public function test_department_filter_matches_ticket_department_field_not_unassigned(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $viewerDepartment = Department::create(['name' => 'Support', 'is_active' => true]);
        $newDepartment = Department::create(['name' => 'Business Development', 'is_active' => true]);

        $viewer = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $viewerDepartment->id,
        ]);

        // Belongs to the selected department by its department field.
        $tagged = $this->ticket($company, [
            'ticket_key' => 'BD-1001',
            'department' => 'Business Development',
        ]);
        // Unassigned but tagged to another department — must NOT leak in.
        $this->ticket($company, [
            'ticket_key' => 'SUP-1002',
            'department' => 'Support',
        ]);
        // Unassigned with no department at all — must NOT leak in.
        $this->ticket($company, ['ticket_key' => 'NONE-1003']);

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'department_id' => $newDepartment->id,
                'status' => ['all'],
                'ticket_scope' => 'all',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tagged->id)
            );
    }

    public function test_automatic_department_scope_is_not_returned_as_an_explicit_filter(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $department = Department::create(['name' => 'Support', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id, 'department_id' => $department->id]);
        $assignee = User::factory()->create(['company_id' => $company->id, 'department_id' => $department->id]);

        $assigned = $this->ticket($company, [
            'ticket_key' => 'AUTO-1001',
            'title' => 'Scope marker',
            'assignee_id' => $assignee->id,
            'department' => null,
        ]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['search' => 'Scope marker']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.department_id', null)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $assigned->id)
            );
    }

    public function test_quick_filter_is_not_intersected_with_open_status(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $department = Department::create(['name' => 'Support', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id, 'department_id' => $department->id]);
        $inProgress = $this->ticket($company, ['status' => 'in_progress']);
        $this->ticket($company, ['status' => 'open']);

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'dashboard_filter' => 'in_progress',
                'skip_default_department' => true,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $inProgress->id)
            );
    }

    public function test_each_date_bound_filters_independently_and_inverted_range_is_rejected(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $department = Department::create(['name' => 'Support', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id, 'department_id' => $department->id]);
        $older = $this->ticket($company);
        $older->forceFill(['created_at' => '2026-06-01 12:00:00'])->saveQuietly();
        $newer = $this->ticket($company);
        $newer->forceFill(['created_at' => '2026-06-10 12:00:00'])->saveQuietly();

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['status' => ['all'], 'skip_default_department' => true, 'start_date' => '2026-06-05']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $newer->id)
            );

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['status' => ['all'], 'skip_default_department' => true, 'end_date' => '2026-06-05']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $older->id)
            );

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['start_date' => '2026-06-10', 'end_date' => '2026-06-01']))
            ->assertSessionHasErrors('end_date');
    }

    private function ticket(Company $company, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'POS issue',
            'description' => 'The terminal needs support.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'is_deleted' => false,
        ], $overrides));
    }
}
