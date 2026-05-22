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

    public function test_sector_summary_counts_follow_store_sector_regardless_of_assignee_filters(): void
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
            'department_id' => null,
            'department_node_id' => $otherNode->id,
        ]);

        $sectorOneStore = $this->store('S001', 1);
        $sectorTwoStore = $this->store('S002', 2);
        $inactiveSectorOneStore = $this->store('S003', 1, false);

        $this->ticket('HD-001', $sectorOneStore, $otherUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-002', $sectorOneStore, null, 'in_progress', '2026-05-20 10:00:00');
        $this->ticket('HD-003', $sectorOneStore, $matchingUser, 'resolved', '2026-05-20 11:00:00');
        $this->ticket('HD-004', $sectorTwoStore, $matchingUser, 'open', '2026-05-20 12:00:00');
        $this->ticket('HD-005', $inactiveSectorOneStore, $matchingUser, 'open', '2026-05-20 13:00:00');

        $data = app(StoreReportService::class)->getStoreHealthData([
            'as_of_date' => '2026-05-20',
            'department_node_id' => $matchingNode->id,
            'user_id' => $matchingUser->id,
            'store_id' => 'all',
        ]);

        $north = collect($data['summary']['north'])->keyBy('sector');

        $this->assertSame(2, $north[1]['total_tickets']);
        $this->assertSame(1, $north[2]['total_tickets']);

        $reportStores = collect($data['reportData'])
            ->flatMap(fn (array $userData) => $userData['stores'])
            ->pluck('code')
            ->all();

        $this->assertContains('S002', $reportStores);
        $this->assertContains('S003', $reportStores);
        $this->assertNotContains('S001', $reportStores);
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
    }

    public function test_sector_ticket_endpoint_matches_store_sector_count_rules(): void
    {
        $matchingUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $sectorOneStore = $this->store('S201', 1);
        $sectorTwoStore = $this->store('S202', 2);
        $inactiveSectorOneStore = $this->store('S203', 1, false);

        $included = $this->ticket('HD-201', $sectorOneStore, $otherUser, 'open', '2026-05-20 09:00:00');
        $this->ticket('HD-202', $sectorOneStore, $matchingUser, 'closed', '2026-05-20 10:00:00');
        $this->ticket('HD-203', $sectorTwoStore, $matchingUser, 'open', '2026-05-20 11:00:00');
        $this->ticket('HD-204', $inactiveSectorOneStore, $matchingUser, 'open', '2026-05-20 12:00:00');

        $response = $this->actingAs($matchingUser)->getJson(route('reports.store-health.sector-tickets', [
            'sector' => 1,
            'as_of_date' => '2026-05-20',
            'user_id' => $matchingUser->id,
            'department_id' => 9999,
        ]));

        $response->assertOk()
            ->assertJsonPath('store_name', 'Sector 1')
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
