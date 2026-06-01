<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StoreReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreHealthSectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sector_summary_respects_department_node_and_child_filters(): void
    {
        $department = Department::create(['name' => 'IT']);
        $parentNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Parent',
        ]);
        $childNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $parentNode->id,
            'name' => 'Child',
        ]);
        $otherNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Other',
        ]);

        $parentUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $parentNode->id,
        ]);
        $childUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $childNode->id,
        ]);
        $otherUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $otherNode->id,
        ]);

        $sectorOneStore = $this->store('S001', 1);
        $sectorTwoStore = $this->store('S002', 2);
        $inactiveSectorOneStore = $this->store('S003', 1, false);

        $this->ticket('HD-001', $sectorOneStore, $otherUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-002', $sectorOneStore, $parentUser, 'in_progress', '2026-05-20 10:00:00');
        $this->ticket('HD-003', $sectorOneStore, $childUser, 'resolved', '2026-05-20 11:00:00');
        $this->ticket('HD-004', $sectorTwoStore, $childUser, 'open', '2026-05-20 12:00:00');
        $this->ticket('HD-005', $inactiveSectorOneStore, $childUser, 'open', '2026-05-20 13:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'department_node_id' => $parentNode->id,
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');

        $this->assertSame(1, $north[1]['total_tickets']);
        $this->assertSame(1, $north[2]['total_tickets']);
        $this->assertSame(1, $north[1]['store_count']);
        $this->assertSame(1, $north[1]['health_counts']['green']);
        $this->assertSame(1, $north[2]['store_count']);
        $this->assertSame(1, $north[2]['health_counts']['green']);

        $reportStores = collect($data['reportData'])
            ->flatMap(fn (array $userData) => $userData['stores'])
            ->pluck('code')
            ->all();

        $this->assertContains('S001', $reportStores);
        $this->assertContains('S002', $reportStores);
        $this->assertNotContains('S003', $reportStores);
    }

    public function test_sector_summary_still_respects_store_and_as_of_date_filters(): void
    {
        $user = User::factory()->create();
        $includedStore = $this->store('S101', 1);
        $otherStore = $this->store('S102', 1);

        $this->ticket('HD-101', $includedStore, $user, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-102', $includedStore, $user, 'open', '2026-05-22 09:00:00');
        $this->ticket('HD-103', $otherStore, $user, 'open', '2026-05-20 09:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'store_id' => $includedStore->id,
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');

        $this->assertSame(1, $north[1]['total_tickets']);
        $this->assertSame(1, $north[1]['store_count']);
        $this->assertSame(1, $north[1]['health_counts']['green']);
    }

    public function test_sector_summary_counts_tickets_per_health_legend_for_affected_stores(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 16; $i++) {
            $store = $this->store(sprintf('S6%02d', $i), 6);

            if ($i > 14) {
                $this->ticket("HD-6{$i}A", $store, $user, 'open', '2026-05-20 09:00:00');
                $this->ticket("HD-6{$i}B", $store, $user, 'open', '2026-05-20 10:00:00');
                $this->ticket("HD-6{$i}C", $store, $user, 'open', '2026-05-20 11:00:00');
            }
        }

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'store_id' => 'all',
            'user_id' => 'all',
        ]);

        $south = collect($data['summary']['south'])->keyBy('sector');

        $this->assertSame(2, $south[6]['store_count']);
        $this->assertSame(6, $south[6]['total_tickets']);
        $this->assertSame(0, $south[6]['health_counts']['green']);
        $this->assertSame(6, $south[6]['health_counts']['yellow']);
        $this->assertSame(0, $south[6]['health_counts']['orange']);
        $this->assertSame(0, $south[6]['health_counts']['red']);
    }

    public function test_sector_summary_matches_table_totals_for_two_healthy_tickets(): void
    {
        $department = Department::create(['name' => 'IT']);
        $southArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'South Area',
        ]);
        $sectorSixNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $southArea->id,
            'name' => 'Sector 6',
        ]);

        $sectorSixUser = User::factory()->create([
            'name' => 'Princess Dacuma',
            'department_id' => $department->id,
            'department_node_id' => $sectorSixNode->id,
        ]);
        $unassignedSectorUser = User::factory()->create([
            'name' => 'Ron Bayanay',
            'department_id' => $department->id,
            'department_node_id' => null,
        ]);

        for ($i = 1; $i <= 30; $i++) {
            $this->store(sprintf('S6%02d', $i), 6);
        }

        $firstAffectedStore = $this->store('NN3CN', 6);
        $secondAffectedStore = $this->store('CBTLMAX', 6);
        $thirdAffectedStore = $this->store('CBTLPHL', 6);

        $this->ticket('HD-601', $firstAffectedStore, $sectorSixUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-602', $secondAffectedStore, $sectorSixUser, 'open', '2026-05-20 10:00:00');
        $this->ticket('HD-603', $thirdAffectedStore, $unassignedSectorUser, 'open', '2026-05-20 11:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $south = collect($data['summary']['south'])->keyBy('sector');
        $tableSectorSixTickets = collect($data['reportData'])
            ->flatMap(fn (array $userData) => $userData['stores'])
            ->where('sector', 6)
            ->sum('ticket_count');

        $this->assertSame(3, $south[6]['store_count']);
        $this->assertSame(3, $south[6]['total_tickets']);
        $this->assertSame(3, $south[6]['health_counts']['green']);
        $this->assertSame(0, $south[6]['health_counts']['yellow']);
        $this->assertSame(3, $tableSectorSixTickets);

        $sectorSixReport = collect($data['reportData'])->firstWhere('name', 'Princess Dacuma');
        $this->assertNotNull($sectorSixReport);
        $this->assertEqualsCanonicalizing(['NN3CN', 'CBTLMAX', 'CBTLPHL'], collect($sectorSixReport['stores'])->pluck('code')->all());
    }

    public function test_duplicate_sector_nodes_merge_assigned_user_names(): void
    {
        $department = Department::create(['name' => 'IT']);
        $northArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'North Area',
        ]);
        $southArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'South Area',
        ]);
        $northSectorTwo = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $northArea->id,
            'name' => 'Sector 2',
        ]);
        DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $southArea->id,
            'name' => 'Sector 2',
        ]);

        $sectorUser = User::factory()->create([
            'name' => 'Sector Two Owner',
            'department_id' => $department->id,
            'department_node_id' => $northSectorTwo->id,
        ]);
        $sectorStore = $this->store('S201', 2);

        $this->ticket('HD-201', $sectorStore, $sectorUser, 'open', '2026-05-20 09:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');

        $this->assertSame('Sector Two Owner', $north[2]['user']);
        $this->assertSame(1, $north[2]['total_tickets']);
    }

    public function test_report_table_stores_are_sorted_by_sector_number(): void
    {
        $user = User::factory()->create();
        $sectorThreeStore = $this->store('S301', 3);
        $sectorOneStore = $this->store('S101', 1);
        $sectorTwoStore = $this->store('S201', 2);

        $this->ticket('HD-301', $sectorThreeStore, $user, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-101', $sectorOneStore, $user, 'open', '2026-05-20 10:00:00');
        $this->ticket('HD-201', $sectorTwoStore, $user, 'open', '2026-05-20 11:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $sectors = collect($data['reportData'])
            ->firstWhere('id', "assignee-{$user->id}")['stores']
            ->pluck('sector')
            ->all();

        $this->assertSame([1, 2, 3], $sectors);
    }

    public function test_report_sections_are_sorted_by_sector_number(): void
    {
        $department = Department::create(['name' => 'IT']);
        $northArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'North Area',
        ]);
        $sectorOneNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $northArea->id,
            'name' => 'Sector 1',
        ]);
        $sectorTwoNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $northArea->id,
            'name' => 'Sector 2',
        ]);

        $sectorTwoUser = User::factory()->create([
            'name' => 'Allen Nueva',
            'department_id' => $department->id,
            'department_node_id' => $sectorTwoNode->id,
        ]);
        $sectorOneUser = User::factory()->create([
            'name' => 'JC Dela Cruz',
            'department_id' => $department->id,
            'department_node_id' => $sectorOneNode->id,
        ]);

        $sectorTwoStore = $this->store('S201', 2);
        $sectorOneStore = $this->store('S101', 1);

        $this->ticket('HD-201', $sectorTwoStore, $sectorTwoUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-101', $sectorOneStore, $sectorOneUser, 'open', '2026-05-20 10:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $this->assertSame(['JC Dela Cruz', 'Allen Nueva'], collect($data['reportData'])->pluck('name')->all());
        $this->assertSame([1, 2], collect($data['reportData'])->pluck('sector')->all());
    }

    public function test_user_filter_matches_sector_owner_not_only_ticket_assignee(): void
    {
        $department = Department::create(['name' => 'IT']);
        $southArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'South Area',
        ]);
        $sectorSixNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $southArea->id,
            'name' => 'Sector 6',
        ]);

        $sectorOwner = User::factory()->create([
            'name' => 'Princess Dacuma',
            'department_id' => $department->id,
            'department_node_id' => $sectorSixNode->id,
        ]);
        $ticketAssignee = User::factory()->create([
            'name' => 'Ron Bayanay',
            'department_id' => $department->id,
            'department_node_id' => null,
        ]);
        $sectorStore = $this->store('S601', 6);

        $this->ticket('HD-601', $sectorStore, $ticketAssignee, 'open', '2026-05-20 09:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => $sectorOwner->id,
            'store_id' => 'all',
        ]);

        $south = collect($data['summary']['south'])->keyBy('sector');

        $this->assertSame(1, $south[6]['total_tickets']);
        $this->assertSame('Princess Dacuma', $data['reportData'][0]['name']);
        $this->assertSame(['S601'], collect($data['reportData'][0]['stores'])->pluck('code')->all());
    }

    public function test_store_filter_matches_table_and_boxes_for_selected_store(): void
    {
        $department = Department::create(['name' => 'IT']);
        $southArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'South Area',
        ]);
        $sectorSixNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $southArea->id,
            'name' => 'Sector 6',
        ]);

        $sectorOwner = User::factory()->create([
            'name' => 'Princess Dacuma',
            'department_id' => $department->id,
            'department_node_id' => $sectorSixNode->id,
        ]);
        $selectedStore = $this->store('S601', 6);
        $otherStore = $this->store('S602', 6);

        $this->ticket('HD-601', $selectedStore, $sectorOwner, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-602', $otherStore, $sectorOwner, 'open', '2026-05-20 10:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => $selectedStore->id,
        ]);

        $south = collect($data['summary']['south'])->keyBy('sector');
        $stores = collect($data['reportData'])->flatMap(fn (array $userData) => $userData['stores']);

        $this->assertSame(1, $south[6]['total_tickets']);
        $this->assertSame(['S601'], $stores->pluck('code')->all());
    }

    public function test_report_table_and_boxes_exclude_stores_outside_assigned_user_sector(): void
    {
        $department = Department::create(['name' => 'IT']);
        $southArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'South Area',
        ]);
        $northArea = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'North Area',
        ]);
        $sectorSixNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $southArea->id,
            'name' => 'Sector 6',
        ]);
        $sectorTwoNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $northArea->id,
            'name' => 'Sector 2',
        ]);

        $sectorSixUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $sectorSixNode->id,
        ]);
        $sectorTwoUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $sectorTwoNode->id,
        ]);

        $sectorSixStore = $this->store('S601', 6);
        $sectorTwoStore = $this->store('S201', 2);

        $included = $this->ticket('HD-601', $sectorSixStore, $sectorSixUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-201', $sectorTwoStore, $sectorSixUser, 'open', '2026-05-20 10:00:00');
        $this->ticket('HD-602', $sectorSixStore, $sectorTwoUser, 'open', '2026-05-20 11:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');
        $south = collect($data['summary']['south'])->keyBy('sector');

        $this->assertSame(0, $north[2]['total_tickets']);
        $this->assertSame(1, $south[6]['total_tickets']);

        $reportStores = collect($data['reportData'])
            ->flatMap(fn (array $userData) => $userData['stores'])
            ->pluck('code')
            ->all();

        $this->assertContains('S601', $reportStores);
        $this->assertNotContains('S201', $reportStores);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.store_health']);
        $sectorSixUser->givePermissionTo('reports.store_health');

        $sectorResponse = $this->actingAs($sectorSixUser)->getJson(route('reports.store-health.sector-tickets', [
            'sector' => 6,
            'as_of_date' => '2026-05-20',
        ]));

        $sectorResponse->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $included->id);

        $storeResponse = $this->actingAs($sectorSixUser)->getJson(route('reports.store-health.tickets', [
            'store' => $sectorSixStore->id,
            'as_of_date' => '2026-05-20',
        ]));

        $storeResponse->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $included->id);
    }

    public function test_sector_ticket_endpoint_matches_department_filters(): void
    {
        $department = Department::create(['name' => 'IT']);
        $matchingNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Matching',
        ]);
        $otherNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Other',
        ]);
        $matchingUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $matchingNode->id,
        ]);
        $otherUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $otherNode->id,
        ]);
        $sectorOneStore = $this->store('S201', 1);
        $sectorTwoStore = $this->store('S202', 2);
        $inactiveSectorOneStore = $this->store('S203', 1, false);

        $included = $this->ticket('HD-201', $sectorOneStore, $matchingUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-200', $sectorOneStore, $otherUser, 'open', '2026-05-20 09:30:00');
        $this->ticket('HD-202', $sectorOneStore, $matchingUser, 'closed', '2026-05-20 10:00:00');
        $this->ticket('HD-203', $sectorTwoStore, $matchingUser, 'open', '2026-05-20 11:00:00');
        $this->ticket('HD-204', $inactiveSectorOneStore, $matchingUser, 'open', '2026-05-20 12:00:00');

        $response = $this->actingAs($matchingUser)->getJson(route('reports.store-health.sector-tickets', [
            'sector' => 1,
            'as_of_date' => '2026-05-20',
            'department_node_id' => $matchingNode->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('store_name', 'Sector 1')
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $included->id);
    }

    public function test_corporate_technology_uses_sector_zero_store_code_boxes_and_table_groups(): void
    {
        $department = Department::create(['name' => 'IT']);
        $ctNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Corporate Technology',
            'code' => 'CT',
        ]);
        $ctChildNode = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $ctNode->id,
            'name' => 'Technology Administration',
            'code' => 'TAS-TECH-CT-TA',
        ]);

        $ctUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $ctChildNode->id,
        ]);
        $otherUser = User::factory()->create([
            'department_id' => $department->id,
        ]);

        $firstCtStore = $this->store('CFE I', 0);
        $secondCtStore = $this->store('OVP', 0);
        $sectorStore = $this->store('S101', 1);
        $inactiveCtStore = $this->store('OLD CT', 0, false);

        $this->ticket('HD-CT1', $firstCtStore, $ctUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-CT2', $firstCtStore, $ctUser, 'open', '2026-05-20 10:00:00');
        $this->ticket('HD-CT3', $secondCtStore, $ctUser, 'open', '2026-05-20 11:00:00');
        $this->ticket('HD-CT4', $sectorStore, $ctUser, 'open', '2026-05-20 12:00:00');
        $this->ticket('HD-CT5', $inactiveCtStore, $ctUser, 'open', '2026-05-20 13:00:00');
        $this->ticket('HD-CT6', $firstCtStore, $otherUser, 'open', '2026-05-20 14:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'department_node_id' => $ctNode->id,
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $this->assertTrue($data['summary']['is_ct_mode']);
        $this->assertSame([], $data['summary']['north']);
        $this->assertSame([], $data['summary']['south']);

        $ctSummary = collect($data['summary']['ct'])->keyBy('store_code');
        $this->assertSame(['CFE I', 'OVP'], $ctSummary->keys()->all());
        $this->assertSame(2, $ctSummary['CFE I']['total_tickets']);
        $this->assertSame(1, $ctSummary['OVP']['total_tickets']);
        $this->assertSame(2, $ctSummary['CFE I']['health_counts']['green']);

        $this->assertSame(['CFE I', 'OVP'], collect($data['reportData'])->pluck('name')->all());
        $this->assertSame([['CFE I'], ['OVP']], collect($data['reportData'])
            ->map(fn (array $group) => collect($group['stores'])->pluck('code')->all())
            ->all());
    }

    public function test_corporate_technology_store_ticket_endpoint_allows_sector_zero(): void
    {
        $department = Department::create(['name' => 'IT']);
        $ctNode = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Corporate Technology',
            'code' => 'CT',
        ]);
        $ctUser = User::factory()->create([
            'department_id' => $department->id,
            'department_node_id' => $ctNode->id,
        ]);
        $ctStore = $this->store('OVP', 0);
        $included = $this->ticket('HD-CT7', $ctStore, $ctUser, 'open', '2026-05-20 09:00:00');

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.store_health']);
        $ctUser->givePermissionTo('reports.store_health');

        $response = $this->actingAs($ctUser)->getJson(route('reports.store-health.tickets', [
            'store' => $ctStore->id,
            'as_of_date' => '2026-05-20',
            'department_node_id' => $ctNode->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('store_name', 'Store OVP')
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $included->id);
    }

    private function store(string $code, int $sector, bool $active = true): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => $sector,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'class' => 'Regular',
            'is_active' => $active,
        ]);
    }

    private function ticket(string $key, Store $store, ?User $assignee, string $status, string $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'ticket_key' => $key,
            'title' => "Ticket {$key}",
            'description' => 'Test ticket',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'store_id' => $store->id,
            'assignee_id' => $assignee?->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('tickets')
            ->where('id', $ticket->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

        return $ticket->refresh();
    }
}
