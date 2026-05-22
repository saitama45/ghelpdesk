<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Role;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTicketFlowSectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_view_uses_store_sector_for_sector_child_nodes_regardless_of_assignee_filters(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $department = $this->department('Store Support');
        $area = $this->node($department, 'North Area');
        $this->node($department, 'Sector 1', $area);
        $this->node($department, 'Sector 2', $area);

        $filterUser = User::factory()->create(['company_id' => $company->id]);
        $outsideAssignee = User::factory()->create(['company_id' => $company->id]);
        $sectorOneStore = $this->store('S001', 1);
        $sectorTwoStore = $this->store('S002', 2);
        $sectorThreeStore = $this->store('S003', 3);

        $sectorOneTicket = $this->ticket($company, ['ticket_key' => 'TF-001', 'store_id' => $sectorOneStore->id, 'assignee_id' => $outsideAssignee->id]);
        $sectorTwoTicket = $this->ticket($company, ['ticket_key' => 'TF-002', 'store_id' => $sectorTwoStore->id]);
        $this->ticket($company, ['ticket_key' => 'TF-003', 'store_id' => $sectorThreeStore->id]);
        $noStoreTicket = $this->ticket($company, ['ticket_key' => 'TF-004', 'store_id' => null]);

        $props = $this->dashboardProps($viewer, [
            'department_node_id' => $area->id,
            'user_id' => $filterUser->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame('sector', $report['department_view_mode']);
        $this->assertSame('Sector', $report['department_view_label']);
        $this->assertSame(3, $report['totals']['sub_unit']['all']);
        $this->assertSame(0, $report['totals']['user']['all']);

        $this->assertSame(1, $groups['sector_1']['total']);
        $this->assertSame([$sectorOneTicket->id], collect($groups['sector_1']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertSame(1, $groups['sector_2']['total']);
        $this->assertSame([$sectorTwoTicket->id], collect($groups['sector_2']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertSame(1, $groups['no_store_sector']['total']);
        $this->assertSame([$noStoreTicket->id], collect($groups['no_store_sector']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertFalse($groups->has('sector_3'));
    }

    public function test_sector_department_view_still_respects_store_filter(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $department = $this->department('Store Support');
        $area = $this->node($department, 'South Area');
        $this->node($department, 'Sector 1', $area);
        $this->node($department, 'Sector 2', $area);
        $sectorOneStore = $this->store('S101', 1);
        $sectorTwoStore = $this->store('S102', 2);

        $sectorOneTicket = $this->ticket($company, ['ticket_key' => 'TF-101', 'store_id' => $sectorOneStore->id]);
        $this->ticket($company, ['ticket_key' => 'TF-102', 'store_id' => $sectorTwoStore->id]);
        $this->ticket($company, ['ticket_key' => 'TF-103', 'store_id' => null]);

        $props = $this->dashboardProps($viewer, [
            'department_node_id' => $area->id,
            'store_id' => $sectorOneStore->id,
        ]);

        $groups = collect($props['kanbanReport']['groups']['sub_unit'])->keyBy('key');

        $this->assertSame(1, $props['kanbanReport']['totals']['sub_unit']['all']);
        $this->assertSame([$sectorOneTicket->id], collect($groups['sector_1']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertSame(0, $groups['sector_2']['total']);
        $this->assertFalse($groups->has('no_store_sector'));
    }

    public function test_department_level_sector_view_detects_nested_sector_nodes_and_uses_store_sector(): void
    {
        $company = $this->company();
        $department = $this->department('Store Support');
        $viewer = $this->viewer($company, [
            'department_id' => $department->id,
        ]);
        $southArea = $this->node($department, 'South Area');
        $sectorFive = $this->node($department, 'Sector 5', $southArea);
        $sectorEight = $this->node($department, 'Sector 8', $southArea);
        $sectorEightAssignee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $sectorEight->id,
            'org_path' => 'South Area > Sector 8',
        ]);
        $sectorFiveStore = $this->store('S501', 5);
        $sectorEightStore = $this->store('S801', 8);

        $sectorFiveTicket = $this->ticket($company, [
            'ticket_key' => 'NONOS-29',
            'status' => 'in_progress',
            'store_id' => $sectorFiveStore->id,
            'assignee_id' => $sectorEightAssignee->id,
        ]);
        $sectorEightTicket = $this->ticket($company, [
            'ticket_key' => 'TF-801',
            'status' => 'in_progress',
            'store_id' => $sectorEightStore->id,
            'assignee_id' => $sectorEightAssignee->id,
        ]);

        $props = $this->dashboardProps($viewer, [
            'skip_default_department' => 1,
            'department_node_id' => $southArea->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame('sector', $report['department_view_mode']);
        $this->assertSame([$sectorFiveTicket->id], collect($groups['sector_5']['columns']['in_progress']['tickets'])->pluck('id')->all());
        $this->assertSame([$sectorEightTicket->id], collect($groups['sector_8']['columns']['in_progress']['tickets'])->pluck('id')->all());
        $this->assertNotContains($sectorFiveTicket->id, collect($groups['sector_8']['columns']['in_progress']['tickets'])->pluck('id')->all());
    }

    public function test_root_department_filter_uses_store_sector_grouping_and_keeps_empty_sector_rows(): void
    {
        $company = $this->company();
        $department = $this->department('Store Support');
        $viewer = $this->viewer($company, [
            'department_id' => $department->id,
        ]);
        $southArea = $this->node($department, 'South Area');
        $this->node($department, 'Sector 5', $southArea);
        $sectorEight = $this->node($department, 'Sector 8', $southArea);
        $sectorEightUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $sectorEight->id,
            'org_path' => 'South Area > Sector 8',
        ]);
        $sectorFiveStore = $this->store('S551', 5);
        $ticket = $this->ticket($company, [
            'ticket_key' => 'TF-551',
            'store_id' => $sectorFiveStore->id,
            'assignee_id' => $sectorEightUser->id,
        ]);

        $props = $this->dashboardProps($viewer, [
            'skip_default_department' => 1,
            'department_id' => $department->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame('sector', $report['department_view_mode']);
        $this->assertSame('Sector', $report['department_view_label']);
        $this->assertSame([$ticket->id], collect($groups['sector_5']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertSame(0, $groups['sector_8']['total']);
    }

    public function test_non_sector_department_view_keeps_assignee_node_grouping(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $department = $this->department('Help Desk');
        $support = $this->node($department, 'Support');
        $field = $this->node($department, 'Field', $support);
        $fieldUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'department_node_id' => $field->id,
            'org_path' => 'Support > Field',
        ]);
        $outsideUser = User::factory()->create(['company_id' => $company->id]);
        $store = $this->store('S201', 1);

        $visibleTicket = $this->ticket($company, ['ticket_key' => 'TF-201', 'store_id' => $store->id, 'assignee_id' => $fieldUser->id]);
        $this->ticket($company, ['ticket_key' => 'TF-202', 'store_id' => $store->id, 'assignee_id' => $outsideUser->id]);

        $props = $this->dashboardProps($viewer, [
            'department_node_id' => $support->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame('department', $report['department_view_mode']);
        $this->assertSame(1, $report['totals']['sub_unit']['all']);
        $this->assertSame(1, $groups['node_'.$field->id]['total']);
        $this->assertSame([$visibleTicket->id], collect($groups['node_'.$field->id]['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertFalse($groups->has('sector_1'));
    }

    private function dashboardProps(User $user, array $query = []): array
    {
        $response = $this->actingAs($user)->get(route('dashboard', $query));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Test Company',
            'code' => 'TC'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function viewer(Company $company, array $overrides = []): User
    {
        $viewer = User::factory()->create(array_merge(['company_id' => $company->id], $overrides));
        $role = Role::create(['name' => 'Dashboard Viewer '.uniqid(), 'guard_name' => 'web']);
        $role->companies()->attach($company->id);
        $viewer->assignRole($role);

        return $viewer;
    }

    private function department(string $name): Department
    {
        return Department::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function node(Department $department, string $name, ?DepartmentNode $parent = null): DepartmentNode
    {
        return DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function store(string $code, int $sector): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => $sector,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'is_active' => true,
        ]);
    }

    private function ticket(Company $company, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_key' => 'TF-'.uniqid(),
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
