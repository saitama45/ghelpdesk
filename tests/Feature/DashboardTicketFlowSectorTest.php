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

    /**
     * Department filtering was removed from the dashboard: any department_id /
     * department_node_id param is ignored and every widget spans all departments.
     * The Ticket Flow Board's Department View still groups store tickets by their
     * store sector.
     */
    public function test_dashboard_ignores_department_filter_and_groups_by_store_sector(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $department = $this->department('Store Support');
        $area = $this->node($department, 'North Area');
        $this->node($department, 'Sector 1', $area);

        // Two assignees in different departments — both must appear regardless of the
        // (ignored) department_node_id param below.
        $assigneeOne = User::factory()->create(['company_id' => $company->id]);
        $assigneeTwo = User::factory()->create(['company_id' => $company->id]);
        $sectorOneStore = $this->store('S001', 1, $company->id);
        $sectorTwoStore = $this->store('S002', 2, $company->id);

        $sectorOneTicket = $this->ticket($company, ['ticket_key' => 'TF-001', 'store_id' => $sectorOneStore->id, 'assignee_id' => $assigneeOne->id]);
        $sectorTwoTicket = $this->ticket($company, ['ticket_key' => 'TF-002', 'store_id' => $sectorTwoStore->id, 'assignee_id' => $assigneeTwo->id]);

        $props = $this->dashboardProps($viewer, [
            // Passed but ignored — the board must not narrow to this department.
            'department_node_id' => $area->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame('department', $report['department_view_mode']);
        $this->assertSame(2, $report['totals']['sub_unit']['all']);
        $this->assertSame([$sectorOneTicket->id], collect($groups['sector_1']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertSame([$sectorTwoTicket->id], collect($groups['sector_2']['columns']['backlogs']['tickets'])->pluck('id')->all());
    }

    public function test_ticket_flow_board_still_respects_store_filter(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $sectorOneStore = $this->store('S101', 1, $company->id);
        $sectorTwoStore = $this->store('S102', 2, $company->id);

        $sectorOneTicket = $this->ticket($company, ['ticket_key' => 'TF-101', 'store_id' => $sectorOneStore->id]);
        $this->ticket($company, ['ticket_key' => 'TF-102', 'store_id' => $sectorTwoStore->id]);

        $props = $this->dashboardProps($viewer, [
            'store_id' => $sectorOneStore->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame(1, $report['totals']['sub_unit']['all']);
        $this->assertSame([$sectorOneTicket->id], collect($groups['sector_1']['columns']['backlogs']['tickets'])->pluck('id')->all());
        $this->assertFalse($groups->has('sector_2'));
    }

    public function test_ticket_flow_board_still_respects_user_filter(): void
    {
        $company = $this->company();
        $viewer = $this->viewer($company);
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $otherAssignee = User::factory()->create(['company_id' => $company->id]);
        $store = $this->store('S201', 1, $company->id);

        $ownTicket = $this->ticket($company, ['ticket_key' => 'TF-201', 'store_id' => $store->id, 'assignee_id' => $assignee->id]);
        $this->ticket($company, ['ticket_key' => 'TF-202', 'store_id' => $store->id, 'assignee_id' => $otherAssignee->id]);

        $props = $this->dashboardProps($viewer, [
            'user_id' => $assignee->id,
        ]);

        $report = $props['kanbanReport'];
        $groups = collect($report['groups']['sub_unit'])->keyBy('key');

        $this->assertSame(1, $report['totals']['sub_unit']['all']);
        $this->assertSame([$ownTicket->id], collect($groups['sector_1']['columns']['backlogs']['tickets'])->pluck('id')->all());
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

    private function store(string $code, int $sector, ?int $companyId = null): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => $sector,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'is_active' => true,
            // Dashboard scopes by store ownership, so fixtures must own their stores.
            'company_id' => $companyId,
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
