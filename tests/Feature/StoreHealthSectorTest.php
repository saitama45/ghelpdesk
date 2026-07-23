<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Setting;
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

    public function test_custom_thresholds_drive_legend_store_buckets_and_both_area_count_units(): void
    {
        $settings = [
            'threshold_green_min' => 0, 'threshold_green_max' => 1, 'threshold_green_label' => 'Good',
            'threshold_yellow_min' => 2, 'threshold_yellow_max' => 3, 'threshold_yellow_label' => 'Watch',
            'threshold_orange_min' => 4, 'threshold_orange_max' => 5, 'threshold_orange_label' => 'Act',
            'threshold_red_min' => 6, 'threshold_red_label' => 'Urgent',
        ];
        foreach ($settings as $key => $value) {
            Setting::set($key, $value, 'thresholds');
        }

        $user = User::factory()->create();
        $this->store('ZERO', 1);
        $watchStore = $this->store('WATCH', 1);
        $actStore = $this->store('ACT', 1);

        for ($i = 1; $i <= 2; $i++) {
            $this->ticket("HD-W{$i}", $watchStore, $user, 'open', '2026-05-20 09:00:00');
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->ticket("HD-A{$i}", $actStore, $user, 'open', '2026-05-20 10:00:00');
        }

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $this->assertSame(['Good', 'Watch', 'Act', 'Urgent'], collect($data['thresholdBands'])->pluck('label')->all());
        $this->assertSame(0, $data['thresholdBands'][0]['min']);

        $sector = collect($data['summary']['north'])->firstWhere('sector', 1);
        $this->assertSame(1, $sector['health_store_counts']['yellow']);
        $this->assertSame(2, $sector['health_ticket_counts']['yellow']);
        $this->assertSame(1, $sector['health_store_counts']['orange']);
        $this->assertSame(4, $sector['health_ticket_counts']['orange']);
        $this->assertSame($sector['health_ticket_counts'], $sector['health_counts']);

        $storeBuckets = collect($data['reportData'])
            ->flatMap(fn (array $group) => $group['stores'])
            ->pluck('health_bucket', 'code');
        $this->assertSame('yellow', $storeBuckets['WATCH']);
        $this->assertSame('orange', $storeBuckets['ACT']);

        $entity = collect($data['entityHealth'])->first();
        $this->assertSame(1, $entity['counts']['green']);
        $this->assertSame(1, $entity['counts']['yellow']);
        $this->assertSame(1, $entity['counts']['orange']);
        $this->assertSame(3, $entity['total_stores']);
        $this->assertSame(6, $entity['open_tickets']);
    }

    public function test_corporate_office_cards_use_the_same_dynamic_threshold_buckets(): void
    {
        $user = User::factory()->create();
        $healthyOffice = $this->store('OFF-ZERO', 9);
        $healthyOffice->update(['class' => 'Office']);
        $criticalOffice = $this->store('OFF-RED', 9);
        $criticalOffice->update(['class' => 'Office']);

        for ($i = 1; $i <= 5; $i++) {
            $this->ticket("HD-O{$i}", $criticalOffice, $user, 'open', '2026-05-20 09:00:00');
        }

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
            'split_office' => true,
        ]);

        $cards = collect($data['office']['summary']['office'])->keyBy('store_code');
        $this->assertSame('green', $cards['OFF-ZERO']['health_bucket']);
        $this->assertSame(0, $cards['OFF-ZERO']['total_tickets']);
        $this->assertSame('red', $cards['OFF-RED']['health_bucket']);
        $this->assertSame(5, $cards['OFF-RED']['total_tickets']);

        $officeEntity = collect($data['office']['entityHealth'])->first();
        $this->assertSame(1, $officeEntity['counts']['green']);
        $this->assertSame(1, $officeEntity['counts']['red']);
    }

    public function test_corporate_office_percent_health_is_a_per_location_resolution_rate(): void
    {
        $user = User::factory()->create();
        $busyOffice = $this->store('OFF-BUSY', 9);
        $busyOffice->update(['class' => 'Office']);
        $quietOffice = $this->store('OFF-QUIET', 9);
        $quietOffice->update(['class' => 'Office']);

        // 8 tickets raised on the busy office, 6 already cleared → 75% health.
        for ($i = 1; $i <= 6; $i++) {
            $this->ticket("HD-C{$i}", $busyOffice, $user, $i % 2 === 0 ? 'closed' : 'resolved', '2026-05-19 09:00:00');
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->ticket("HD-O{$i}", $busyOffice, $user, 'open', '2026-05-20 09:00:00');
        }
        // Raised after the as-of date — must not count either way.
        $this->ticket('HD-LATE', $busyOffice, $user, 'open', '2026-05-25 09:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
            'split_office' => true,
        ]);

        $cards = collect($data['office']['summary']['office'])->keyBy('store_code');
        $this->assertSame(8, $cards['OFF-BUSY']['all_tickets']);
        $this->assertSame(6, $cards['OFF-BUSY']['closed_tickets']);
        $this->assertSame(2, $cards['OFF-BUSY']['total_tickets']);
        $this->assertSame(75.0, $cards['OFF-BUSY']['healthy_pct']);

        // A location that never raised a ticket has nothing outstanding.
        $this->assertSame(0, $cards['OFF-QUIET']['all_tickets']);
        $this->assertSame(100.0, $cards['OFF-QUIET']['healthy_pct']);

        // The block rollup stays a share of offices sitting in the healthy band.
        $this->assertSame(2, $data['office']['summary']['office_totals']['total_stores']);
        $this->assertSame(2, $data['office']['summary']['office_totals']['healthy_stores']);
    }

    public function test_percent_healthy_counts_every_active_store_and_splits_offices(): void
    {
        $user = User::factory()->create();

        // Sector 1: 4 stores — 1 quiet, 1 in the green band, 1 warning, 1 critical.
        $this->store('S1-QUIET', 1);
        $greenStore = $this->store('S1-GREEN', 1);
        $warnStore = $this->store('S1-WARN', 1);
        $criticalStore = $this->store('S1-RED', 1);
        // An inactive store must not dilute the denominator.
        $this->store('S1-DEAD', 1, false);

        $this->ticket('HD-G1', $greenStore, $user, 'open', '2026-05-20 09:00:00');
        for ($i = 1; $i <= 3; $i++) {
            $this->ticket("HD-W{$i}", $warnStore, $user, 'open', '2026-05-20 09:00:00');
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->ticket("HD-R{$i}", $criticalStore, $user, 'open', '2026-05-20 09:00:00');
        }

        $office = $this->store('OFF-A', 1);
        $office->update(['class' => 'Office']);

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
            'split_office' => true,
        ]);

        // Offices are carved out, so the sector denominator is the 4 active sector stores.
        $sector = collect($data['summary']['north'])->firstWhere('sector', 1);
        $this->assertSame(4, $sector['total_stores']);
        $this->assertSame(2, $sector['healthy_stores']);
        $this->assertSame(50.0, $sector['healthy_pct']);
        $this->assertSame(3, $sector['store_count']);

        // A sector with no stores at all reports no percentage rather than 0%.
        $this->assertNull(collect($data['summary']['north'])->firstWhere('sector', 2)['healthy_pct']);

        $this->assertSame(
            ['total_stores' => 1, 'healthy_stores' => 1, 'healthy_pct' => 100.0],
            $data['office']['summary']['office_totals']
        );
    }

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

        // The card lists the whole sector: the 3 stores with tickets plus the 30 quiet
        // ones at 0, so a store that raised nothing is still visible.
        $sectorSixStores = collect($sectorSixReport['stores'])->pluck('ticket_count', 'code');
        $this->assertCount(33, $sectorSixStores);
        $this->assertSame([1, 1, 1], [$sectorSixStores['NN3CN'], $sectorSixStores['CBTLMAX'], $sectorSixStores['CBTLPHL']]);
        $this->assertSame(0, $sectorSixStores['S601']);
        $this->assertSame(30, $sectorSixStores->filter(fn (int $count) => $count === 0)->count());
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

    public function test_report_table_and_boxes_count_stores_by_store_sector_regardless_of_assignee(): void
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

        // A sector-6 store ticket handled by a sector-6 tech...
        $sectorSixOwn = $this->ticket('HD-601', $sectorSixStore, $sectorSixUser, 'open', '2026-05-20 09:00:00');
        // ...a sector-2 store ticket handled by a sector-6 tech (counts toward sector 2)...
        $this->ticket('HD-201', $sectorTwoStore, $sectorSixUser, 'open', '2026-05-20 10:00:00');
        // ...and a sector-6 store ticket handled by a sector-2 tech (still counts toward sector 6).
        $sectorSixCrossAssignee = $this->ticket('HD-602', $sectorSixStore, $sectorTwoUser, 'open', '2026-05-20 11:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');
        $south = collect($data['summary']['south'])->keyBy('sector');

        // Pure store-sector: tickets land in the sector of their store, not their assignee.
        $this->assertSame(1, $north[2]['total_tickets']);
        $this->assertSame(2, $south[6]['total_tickets']);

        $reportStores = collect($data['reportData'])
            ->flatMap(fn (array $userData) => $userData['stores'])
            ->pluck('code')
            ->all();

        $this->assertContains('S601', $reportStores);
        $this->assertContains('S201', $reportStores);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.store_health']);
        $sectorSixUser->givePermissionTo('reports.store_health');

        $sectorResponse = $this->actingAs($sectorSixUser)->getJson(route('reports.store-health.sector-tickets', [
            'sector' => 6,
            'as_of_date' => '2026-05-20',
        ]));

        $sectorResponse->assertOk()
            ->assertJsonCount(2, 'tickets')
            ->assertJsonFragment(['id' => $sectorSixOwn->id])
            ->assertJsonFragment(['id' => $sectorSixCrossAssignee->id]);

        $storeResponse = $this->actingAs($sectorSixUser)->getJson(route('reports.store-health.tickets', [
            'store' => $sectorSixStore->id,
            'as_of_date' => '2026-05-20',
        ]));

        $storeResponse->assertOk()
            ->assertJsonCount(2, 'tickets')
            ->assertJsonFragment(['id' => $sectorSixOwn->id])
            ->assertJsonFragment(['id' => $sectorSixCrossAssignee->id]);
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

    public function test_sector_ticket_endpoint_scopes_by_entity_store_ownership(): void
    {
        $owner = Company::create(['name' => 'Owner Co', 'code' => 'OWN'.uniqid(), 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'code' => 'OTH'.uniqid(), 'is_active' => true]);

        $ownerStore = $this->ownedStore('EOS1', 1, $owner->id);
        $otherStore = $this->ownedStore('EOS2', 1, $other->id);

        $ownerParent = $this->companyTicket('E-1', $ownerStore, $owner->id, '2026-05-20 09:00:00');
        // Child on the owner's store — excluded (parent-only), matching the card count.
        $child = $this->companyTicket('E-2', $ownerStore, $owner->id, '2026-05-20 09:30:00');
        $child->forceFill(['parent_id' => $ownerParent->id])->save();
        // Same sector but on ANOTHER entity's store — excluded by the entity_ids scope.
        $this->companyTicket('E-3', $otherStore, $other->id, '2026-05-20 10:00:00');

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('reports.store-health.sector-tickets', [
                'sector' => 1,
                'as_of_date' => '2026-05-20',
                'entity_ids' => [$owner->id],
            ]));

        $response->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ownerParent->id);
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

    public function test_entity_filter_scopes_by_store_ownership_not_ticket_company(): void
    {
        $owner = Company::create(['name' => 'Owner Co', 'code' => 'OWN'.uniqid(), 'is_active' => true]);
        $other = Company::create(['name' => 'Other Co', 'code' => 'OTH'.uniqid(), 'is_active' => true]);

        // A store OWNED by $other in sector 1, and a store OWNED by $owner in sector 2.
        $otherStore = $this->ownedStore('OS1', 1, $other->id);
        $ownerStore = $this->ownedStore('OW1', 2, $owner->id);

        // Ticket stamped to $owner but sitting on $other's store — must NOT count for $owner.
        $this->companyTicket('X-1', $otherStore, $owner->id, '2026-05-20 09:00:00');
        // Ticket on $owner's own store — must count.
        $this->companyTicket('X-2', $ownerStore, $owner->id, '2026-05-20 09:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
            'company_ids' => [$owner->id],
        ]);

        // Sector cards: the other-owned store (sector 1) is excluded even though its
        // ticket is stamped to the owner; the owner's own store (sector 2) is counted.
        $sector1 = collect($data['summary']['north'])->firstWhere('sector', 1);
        $sector2 = collect($data['summary']['north'])->firstWhere('sector', 2);
        $this->assertSame(0, $sector1['total_tickets']);
        $this->assertSame(1, $sector2['total_tickets']);

        // Heatmap tallies to the same universe: the owner entity has exactly its own store.
        $entity = collect($data['entityHealth'])->firstWhere('id', $owner->id);
        $this->assertSame(1, $entity['total_stores']);
        $this->assertSame(1, $entity['open_tickets']);
    }

    public function test_store_health_counts_parent_tickets_only(): void
    {
        $owner = Company::create(['name' => 'Parent Co', 'code' => 'PAR'.uniqid(), 'is_active' => true]);
        $store = $this->ownedStore('PAR1', 1, $owner->id);

        // One parent ticket and one child ticket on the same store — only the parent
        // is counted, so every tab tallies to the same on-store parent universe.
        $parent = $this->companyTicket('P-1', $store, $owner->id, '2026-05-20 09:00:00');
        $child = $this->companyTicket('P-2', $store, $owner->id, '2026-05-20 09:00:00');
        $child->forceFill(['parent_id' => $parent->id])->save();

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'user_id' => 'all',
            'store_id' => 'all',
            'company_ids' => [$owner->id],
        ]);

        $sector1 = collect($data['summary']['north'])->firstWhere('sector', 1);
        $this->assertSame(1, $sector1['total_tickets']);
        $entity = collect($data['entityHealth'])->firstWhere('id', $owner->id);
        $this->assertSame(1, $entity['open_tickets']);
    }

    private function ownedStore(string $code, int $sector, int $companyId): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => $sector,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $companyId,
        ]);
    }

    private function companyTicket(string $key, Store $store, int $companyId, string $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'ticket_key' => $key,
            'title' => "Ticket {$key}",
            'description' => 'Test ticket',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'store_id' => $store->id,
            'company_id' => $companyId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('tickets')->where('id', $ticket->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $ticket->refresh();
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
