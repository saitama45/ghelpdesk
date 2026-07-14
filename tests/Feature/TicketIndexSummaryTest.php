<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vendor;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
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
                ->where('filters.ticket_keys', ['SLA-1001', 'SLA-1002'])
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($tickets) => collect($tickets)
                    ->pluck('ticket_key')
                    ->sort()
                    ->values()
                    ->all() === ['SLA-1001', 'SLA-1002'])
            );
    }

    public function test_ticket_type_defaults_to_all_tickets(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $parent = $this->ticket($company, ['ticket_key' => 'ALL-1001']);
        $child = $this->ticket($company, ['ticket_key' => 'ALL-1002', 'parent_id' => $parent->id]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', ['status' => ['all'], 'skip_default_department' => true]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('filters.ticket_scope', 'all')
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($tickets) => collect($tickets)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$parent->id, $child->id])->sort()->values()->all())
            );
    }

    public function test_requester_filter_supports_internal_and_external_requesters_and_scopes_options(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $otherCompany = Company::create(['name' => 'Other Company', 'code' => 'OC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $internalRequester = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Internal Requester',
            'email' => 'internal@example.test',
        ]);
        $internalTicket = $this->ticket($company, [
            'ticket_key' => 'REQ-1001',
            'reporter_id' => $internalRequester->id,
        ]);
        $externalTicket = $this->ticket($company, [
            'ticket_key' => 'REQ-1002',
            'sender_name' => 'External Requester',
            'sender_email' => 'External@Example.test',
        ]);
        $this->ticket($company, ['ticket_key' => 'REQ-1003']);
        $this->ticket($otherCompany, [
            'ticket_key' => 'HIDDEN-1001',
            'sender_name' => 'Hidden Requester',
            'sender_email' => 'hidden@example.test',
        ]);

        $requesterKeys = ['user:'.$internalRequester->id, 'email:external@example.test'];

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'skip_default_department' => true,
                'requester_keys' => $requesterKeys,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('filters.requester_keys', $requesterKeys)
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($tickets) => collect($tickets)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$internalTicket->id, $externalTicket->id])->sort()->values()->all())
                ->where('ticketKeyOptions', fn ($options) => collect($options)
                    ->pluck('value')
                    ->contains('REQ-1001')
                    && ! collect($options)->pluck('value')->contains('HIDDEN-1001'))
                ->where('requesterOptions', fn ($options) => collect($options)
                    ->pluck('value')
                    ->contains('user:'.$internalRequester->id)
                    && collect($options)->pluck('value')->contains('email:external@example.test')
                    && ! collect($options)->pluck('value')->contains('email:hidden@example.test'))
            );
    }

    public function test_ticket_key_options_follow_the_selected_accessible_entities(): void
    {
        $tgi = Company::create(['name' => 'The Table Group', 'code' => 'TGI', 'is_active' => true]);
        $cbtl = Company::create(['name' => 'Coffee Bean and Tea Leaf', 'code' => 'CBTL', 'is_active' => true]);
        $hidden = Company::create(['name' => 'Hidden Company', 'code' => 'HIDDEN', 'is_active' => true]);

        Permission::findOrCreate('tickets.filter_entity', 'web');
        $role = Role::create(['name' => 'Cross-entity ticket viewer', 'guard_name' => 'web']);
        $role->companies()->attach([$tgi->id, $cbtl->id]);
        $role->givePermissionTo('tickets.filter_entity');

        $viewer = User::factory()->create(['company_id' => $tgi->id]);
        $viewer->assignRole($role);

        $tgiTicket = $this->ticket($tgi, ['ticket_key' => 'TGI-1001']);
        $cbtlTicket = $this->ticket($cbtl, ['ticket_key' => 'CBTL-2001']);
        $this->ticket($hidden, ['ticket_key' => 'HIDDEN-3001']);

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'skip_default_department' => true,
                'entity_ids' => [$tgi->id, $cbtl->id],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.entity_ids', [$tgi->id, $cbtl->id])
                ->where('tickets.data', fn ($tickets) => collect($tickets)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$tgiTicket->id, $cbtlTicket->id])->sort()->values()->all())
                ->where('ticketKeyOptions', fn ($options) => collect($options)->contains(fn ($option) =>
                    $option['value'] === 'TGI-1001' && $option['company_id'] === $tgi->id
                ) && collect($options)->contains(fn ($option) =>
                    $option['value'] === 'CBTL-2001' && $option['company_id'] === $cbtl->id
                ) && ! collect($options)->pluck('value')->contains('HIDDEN-3001'))
            );

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'skip_default_department' => true,
                'entity_ids' => [$cbtl->id],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.entity_ids', [$cbtl->id])
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $cbtlTicket->id)
                ->where('ticketKeyOptions', fn ($options) => collect($options)->pluck('value')->all() === ['CBTL-2001'])
            );
    }

    public function test_cross_entity_ticket_family_relations_remain_visible_on_index_and_edit_pages(): void
    {
        $tgi = Company::create(['name' => 'The Table Group', 'code' => 'TGI', 'is_active' => true]);
        $cbtl = Company::create(['name' => 'Coffee Bean and Tea Leaf', 'code' => 'CBTL', 'is_active' => true]);
        $vendor = Vendor::create([
            'code' => 'VENDOR-1',
            'name' => 'Service Vendor',
            'vendor_type' => 'Service Provider',
            'email' => 'vendor@example.test',
            'is_active' => true,
        ]);

        Permission::findOrCreate('tickets.filter_entity', 'web');
        $role = Role::create(['name' => 'Cross-entity family viewer', 'guard_name' => 'web']);
        $role->companies()->attach([$tgi->id, $cbtl->id]);
        $role->givePermissionTo('tickets.filter_entity');

        $viewer = User::factory()->create(['company_id' => $tgi->id]);
        $viewer->assignRole($role);

        $parent = $this->ticket($cbtl, [
            'ticket_key' => 'CBTL-1473',
            'title' => 'Parent ticket',
            'status' => 'waiting_service_provider',
        ]);
        $child = $this->ticket($cbtl, [
            'ticket_key' => 'CBTL-1475',
            'title' => 'Vendor Escalation: Parent ticket',
            'parent_id' => $parent->id,
            'vendor_id' => $vendor->id,
        ]);

        $session = [CompanyContext::SESSION_KEY => $tgi->id];

        $this->actingAs($viewer)
            ->withSession($session)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'skip_default_department' => true,
                'ticket_scope' => 'all',
                'entity_ids' => [$cbtl->id],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.data', function ($tickets) use ($parent, $child, $vendor) {
                    $byKey = collect($tickets)->keyBy('ticket_key');
                    $parentRow = $byKey->get($parent->ticket_key);
                    $childRow = $byKey->get($child->ticket_key);

                    return data_get($childRow, 'parent.ticket_key') === $parent->ticket_key
                        && data_get($parentRow, 'children.0.ticket_key') === $child->ticket_key
                        && data_get($parentRow, 'children.0.vendor.id') === $vendor->id;
                })
            );

        $this->actingAs($viewer)
            ->withSession($session)
            ->get(route('tickets.edit', $child))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Edit')
                ->where('ticket.parent.ticket_key', $parent->ticket_key)
                ->where('ticket.vendor.id', $vendor->id)
            );

        $this->actingAs($viewer)
            ->withSession($session)
            ->get(route('tickets.edit', $parent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Edit')
                ->where('ticket.children.0.ticket_key', $child->ticket_key)
                ->where('ticket.children.0.vendor.id', $vendor->id)
            );
    }

    public function test_ticket_index_can_filter_vendor_escalations_by_multiple_vendors(): void
    {
        $company = Company::create(['name' => 'Vendor Filter Company', 'code' => 'VFC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $firstVendor = Vendor::create([
            'code' => 'FILTER-1',
            'name' => 'First Filter Vendor',
            'vendor_type' => 'Service Provider',
            'is_active' => true,
        ]);
        $secondVendor = Vendor::create([
            'code' => 'FILTER-2',
            'name' => 'Second Filter Vendor',
            'vendor_type' => 'Service Provider',
            'is_active' => true,
        ]);
        $otherVendor = Vendor::create([
            'code' => 'FILTER-3',
            'name' => 'Other Filter Vendor',
            'vendor_type' => 'Service Provider',
            'is_active' => true,
        ]);

        $firstTicket = $this->ticket($company, ['vendor_id' => $firstVendor->id]);
        $secondTicket = $this->ticket($company, ['vendor_id' => $secondVendor->id]);
        $this->ticket($company, ['vendor_id' => $otherVendor->id]);

        $this->actingAs($viewer)
            ->get(route('tickets.index', [
                'status' => ['all'],
                'skip_default_department' => true,
                'vendor_id' => [$firstVendor->id, $secondVendor->id],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.vendor_id', [$firstVendor->id, $secondVendor->id])
                ->where('tickets.total', 2)
                ->where('tickets.data', fn ($tickets) => collect($tickets)->pluck('id')->sort()->values()->all()
                    === collect([$firstTicket->id, $secondTicket->id])->sort()->values()->all())
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
