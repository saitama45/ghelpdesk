<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Item;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_board_token_only_returns_tickets_for_its_company(): void
    {
        $companyA = $this->company('ALP');
        $companyB = $this->company('BET');
        $nodeA = $this->childNode($companyA, 'Alpha Root', 'Alpha Child', 'ALP');
        $nodeB = $this->childNode($companyB, 'Beta Root', 'Beta Child', 'BET');
        $assigneeA = User::factory()->create(['department_node_id' => $nodeA->id]);
        $assigneeB = User::factory()->create(['department_node_id' => $nodeB->id]);

        Setting::set("queue_board_token_company_{$companyA->id}", 'board-token-a', 'queue');

        $ticketA = $this->ticket($companyA, [
            'title' => 'Company A ticket',
            'assignee_id' => $assigneeA->id,
        ]);
        $ticketB = $this->ticket($companyB, [
            'title' => 'Company B ticket',
            'assignee_id' => $assigneeB->id,
        ]);

        $response = $this->getJson(route('public.queue.board.data', 'board-token-a'));

        $response->assertOk();
        $keys = collect($response->json('lanes'))
            ->flatMap(fn (array $lane) => $lane['waiting'])
            ->pluck('ticket_key')
            ->values();

        $this->assertContains($ticketA->ticket_key, $keys);
        $this->assertNotContains($ticketB->ticket_key, $keys);
    }

    public function test_kiosk_rejects_records_outside_the_token_company(): void
    {
        $companyA = $this->company('ALP');
        $companyB = $this->company('BET');
        $itemB = $this->item($companyB);

        Setting::set("queue_kiosk_token_company_{$companyA->id}", 'kiosk-token-a', 'queue');

        $response = $this->from(route('public.queue.kiosk', 'kiosk-token-a'))
            ->post(route('public.queue.kiosk.store', 'kiosk-token-a'), [
                'sender_name' => 'Walk In',
                'sender_email' => 'walkin@example.com',
                'item_id' => $itemB->id,
                'title' => 'Need help',
            ]);

        $response->assertRedirect(route('public.queue.kiosk', 'kiosk-token-a'));
        $response->assertSessionHasErrors('item_id');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_kiosk_ticket_uses_token_company_even_when_auto_assignment_rule_has_another_company(): void
    {
        $companyA = $this->company('ALP');
        $companyB = $this->company('BET');
        $agent = User::factory()->create();

        Setting::set("queue_kiosk_token_company_{$companyA->id}", 'kiosk-token-a', 'queue');
        Setting::set('auto_assignee_rules', json_encode([[
            'email' => 'walkin@example.com',
            'assignee_ids' => [$agent->id],
            'company_id' => $companyB->id,
        ]]), 'auto_assignee');

        $response = $this->post(route('public.queue.kiosk.store', 'kiosk-token-a'), [
            'sender_name' => 'Walk In',
            'sender_email' => 'walkin@example.com',
            'title' => 'Need help',
        ]);

        $ticket = Ticket::withoutGlobalScopes()->firstOrFail();

        $response->assertRedirect(route('public.queue.track', $ticket->queue_track_token));
        $this->assertSame($companyA->id, (int) $ticket->company_id);
        $this->assertSame($agent->id, (int) $ticket->assignee_id);
    }

    public function test_unconfigured_department_gets_a_live_lane_and_is_claimable(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Support', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Solutions',
            'code' => 'SOL',
        ]);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Digital Solutions',
            'code' => 'DS',
        ]);
        $currentAssignee = User::factory()->create(['department_node_id' => $node->id]);
        $agent = User::factory()->create();
        $ticket = $this->ticket($company, ['assignee_id' => $currentAssignee->id]);

        Setting::set('queue_lane_nodes', json_encode([]), 'queue');

        $laneKey = "node:{$node->id}";
        $queue = app(QueueService::class);
        $board = $queue->board($company->id);
        $digitalSolutionsLane = collect($board['lanes'])->firstWhere('key', $laneKey);
        $triageLane = collect($board['lanes'])->firstWhere('key', QueueService::TRIAGE_KEY);
        $trackingInfo = $queue->positionInfoFor($ticket, $company->id);

        $this->assertSame('Digital Solutions', $digitalSolutionsLane['name']);
        $this->assertContains($ticket->ticket_key, collect($digitalSolutionsLane['waiting'])->pluck('ticket_key'));
        $this->assertNotContains($ticket->ticket_key, collect($triageLane['waiting'])->pluck('ticket_key'));
        $this->assertSame('Digital Solutions', $trackingInfo['lane']);
        $this->assertSame(1, $trackingInfo['position']);

        $claimed = $queue->claimNextWaitingTicket($laneKey, $agent);
        $updatedBoard = $queue->board($company->id);
        $updatedLane = collect($updatedBoard['lanes'])->firstWhere('key', $laneKey);

        $this->assertNotNull($claimed);
        $this->assertSame($ticket->id, $claimed->id);
        $this->assertSame('in_progress', $claimed->status);
        $this->assertSame($agent->id, (int) $claimed->assignee_id);
        $this->assertSame($laneKey, $claimed->queue_called_lane);
        $this->assertContains($ticket->ticket_key, collect($updatedLane['now_serving'])->pluck('ticket_key'));
    }

    public function test_public_board_and_tracking_use_the_assignees_dynamic_department_lane(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Support', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Solutions',
            'code' => 'SOL',
        ]);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Digital Solutions',
            'code' => 'DS',
        ]);
        $assignee = User::factory()->create(['department_node_id' => $node->id]);
        $ticket = $this->ticket($company, ['assignee_id' => $assignee->id]);
        $token = $ticket->ensureTrackToken();

        Setting::set('queue_lane_nodes', json_encode(['SOL']), 'queue');
        Setting::set("queue_board_token_company_{$company->id}", 'board-token-a', 'queue');

        $boardResponse = $this->getJson(route('public.queue.board.data', 'board-token-a'));
        $trackResponse = $this->getJson(route('public.queue.track.data', $token));

        $boardResponse->assertOk();
        $trackResponse->assertOk()
            ->assertJsonPath('info.lane', 'Digital Solutions')
            ->assertJsonPath('info.position', 1);

        $digitalSolutionsLane = collect($boardResponse->json('lanes'))->firstWhere('key', "node:{$node->id}");
        $this->assertSame('Digital Solutions', $digitalSolutionsLane['name']);
        $this->assertContains($ticket->ticket_key, collect($digitalSolutionsLane['waiting'])->pluck('ticket_key'));
    }

    public function test_assigned_ticket_without_a_department_has_a_separate_lane(): void
    {
        $company = $this->company('ALP');
        $assignee = User::factory()->create(['department_node_id' => null]);
        $ticket = $this->ticket($company, ['assignee_id' => $assignee->id]);

        $board = app(QueueService::class)->board($company->id);
        $assignedLane = collect($board['lanes'])
            ->firstWhere('key', QueueService::ASSIGNED_NO_DEPARTMENT_KEY);
        $triageLane = collect($board['lanes'])->firstWhere('key', QueueService::TRIAGE_KEY);

        $this->assertContains($ticket->ticket_key, collect($assignedLane['waiting'])->pluck('ticket_key'));
        $this->assertNotContains($ticket->ticket_key, collect($triageLane['waiting'])->pluck('ticket_key'));
    }

    public function test_public_board_hides_the_assigned_without_department_lane(): void
    {
        $company = $this->company('ALP');
        $assignee = User::factory()->create(['department_node_id' => null]);
        $this->ticket($company, ['assignee_id' => $assignee->id]);

        Setting::set("queue_board_token_company_{$company->id}", 'board-token-a', 'queue');

        $response = $this->getJson(route('public.queue.board.data', 'board-token-a'));

        $response->assertOk();
        $this->assertNotContains(
            QueueService::ASSIGNED_NO_DEPARTMENT_KEY,
            collect($response->json('lanes'))->pluck('key')
        );
    }

    public function test_public_board_hides_lanes_with_only_on_hold_tickets(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Support', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Business Solutions',
            'code' => 'BS',
        ]);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Process Excellence',
            'code' => 'PE',
        ]);
        $assignee = User::factory()->create(['department_node_id' => $node->id]);
        $this->ticket($company, [
            'assignee_id' => $assignee->id,
            'status' => 'for_schedule',
        ]);

        Setting::set('queue_lane_nodes', json_encode([]), 'queue');
        Setting::set("queue_board_token_company_{$company->id}", 'board-token-a', 'queue');

        $response = $this->getJson(route('public.queue.board.data', 'board-token-a'));

        $response->assertOk();
        $this->assertNotContains(
            "node:{$node->id}",
            collect($response->json('lanes'))->pluck('key')
        );
    }

    public function test_public_board_only_shows_active_exact_non_root_department_lanes(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Technology Department', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Technology',
            'code' => 'TECH',
        ]);
        $child = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Digital Solutions',
            'code' => 'DS',
        ]);
        $rootAssignee = User::factory()->create(['department_node_id' => $root->id]);
        $childAssignee = User::factory()->create(['department_node_id' => $child->id]);
        $rootTicket = $this->ticket($company, ['assignee_id' => $rootAssignee->id]);
        $childTicket = $this->ticket($company, ['assignee_id' => $childAssignee->id]);
        $unassignedTicket = $this->ticket($company);

        Setting::set('queue_lane_nodes', json_encode(['TECH']), 'queue');
        Setting::set("queue_board_token_company_{$company->id}", 'board-token-a', 'queue');

        $response = $this->getJson(route('public.queue.board.data', 'board-token-a'));

        $response->assertOk();
        $lanes = collect($response->json('lanes'));
        $keys = $lanes->pluck('key');
        $visibleTickets = $lanes
            ->flatMap(fn (array $lane) => array_merge($lane['waiting'], $lane['now_serving']))
            ->pluck('ticket_key');

        $this->assertContains("node:{$child->id}", $keys);
        $this->assertNotContains("node:{$root->id}", $keys);
        $this->assertNotContains('TECH', $keys);
        $this->assertNotContains(QueueService::TRIAGE_KEY, $keys);
        $this->assertContains($childTicket->ticket_key, $visibleTickets);
        $this->assertNotContains($rootTicket->ticket_key, $visibleTickets);
        $this->assertNotContains($unassignedTicket->ticket_key, $visibleTickets);
    }

    public function test_direct_department_board_claims_and_preserves_the_exact_assigned_lane(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Technology Department', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Technology',
            'code' => 'TECH',
        ]);
        $child = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Digital Solutions',
            'code' => 'DS',
        ]);
        $currentAssignee = User::factory()->create(['department_node_id' => $child->id]);
        $callingAgent = User::factory()->create(['department_node_id' => null]);
        $ticket = $this->ticket($company, ['assignee_id' => $currentAssignee->id]);

        Setting::set('queue_lane_nodes', json_encode(['TECH']), 'queue');

        $queue = app(QueueService::class);
        $laneKey = "node:{$child->id}";
        $board = $queue->directDepartmentBoard($company->id);
        $childLane = collect($board['lanes'])->firstWhere('key', $laneKey);

        $this->assertContains($ticket->ticket_key, collect($childLane['waiting'])->pluck('ticket_key'));

        $claimed = $queue->claimNextWaitingTicket(
            $laneKey,
            $callingAgent,
            directDepartmentLanes: true
        );
        $updatedBoard = $queue->directDepartmentBoard($company->id);
        $updatedLane = collect($updatedBoard['lanes'])->firstWhere('key', $laneKey);

        $this->assertSame($ticket->id, $claimed?->id);
        $this->assertSame($laneKey, $claimed?->queue_called_lane);
        $this->assertContains($ticket->ticket_key, collect($updatedLane['now_serving'])->pluck('ticket_key'));
    }

    public function test_internal_and_public_boards_use_the_exact_assigned_node_at_every_depth(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Solutions Department', 'company_id' => $company->id]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Solutions',
            'code' => 'SOL',
        ]);
        $intermediate = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => 'Digital Solutions',
            'code' => 'DS',
        ]);
        $leaf = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $intermediate->id,
            'name' => 'Data Governance',
            'code' => 'DG',
        ]);
        $intermediateAssignee = User::factory()->create(['department_node_id' => $intermediate->id]);
        $leafAssignee = User::factory()->create(['department_node_id' => $leaf->id]);
        $intermediateTicket = $this->ticket($company, ['assignee_id' => $intermediateAssignee->id]);
        $leafTicket = $this->ticket($company, ['assignee_id' => $leafAssignee->id]);

        Setting::set('queue_lane_nodes', json_encode(['SOL']), 'queue');
        Setting::set("queue_board_token_company_{$company->id}", 'board-token-a', 'queue');

        $internalBoard = app(QueueService::class)->directDepartmentBoard($company->id);
        $publicResponse = $this->getJson(route('public.queue.board.data', 'board-token-a'));

        $publicResponse->assertOk();

        foreach ([$internalBoard['lanes'], $publicResponse->json('lanes')] as $lanes) {
            $lanes = collect($lanes);
            $digitalSolutions = $lanes->firstWhere('key', "node:{$intermediate->id}");
            $dataGovernance = $lanes->firstWhere('key', "node:{$leaf->id}");

            $this->assertSame('Digital Solutions', $digitalSolutions['name']);
            $this->assertContains(
                $intermediateTicket->ticket_key,
                collect($digitalSolutions['waiting'])->pluck('ticket_key')
            );
            $this->assertSame('Data Governance', $dataGovernance['name']);
            $this->assertContains(
                $leafTicket->ticket_key,
                collect($dataGovernance['waiting'])->pluck('ticket_key')
            );
        }
    }

    public function test_called_ticket_stays_visible_in_the_lane_it_was_called_from(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Support', 'company_id' => $company->id]);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Field',
            'code' => 'FIELD',
        ]);
        $currentAssignee = User::factory()->create(['department_node_id' => $node->id]);
        $agentWithoutLane = User::factory()->create(['department_node_id' => null]);
        $ticket = $this->ticket($company, ['assignee_id' => $currentAssignee->id]);

        Setting::set('queue_lane_nodes', json_encode(['FIELD']), 'queue');

        $claimed = app(QueueService::class)->claimNextWaitingTicket('FIELD', $agentWithoutLane);
        $board = app(QueueService::class)->board($company->id);
        $fieldLane = collect($board['lanes'])->firstWhere('key', 'FIELD');

        $this->assertSame($ticket->id, $claimed?->id);
        $this->assertSame('FIELD', $claimed->queue_called_lane);
        $this->assertContains($ticket->ticket_key, collect($fieldLane['now_serving'])->pluck('ticket_key'));
    }

    private function company(string $code): Company
    {
        return Company::create([
            'name' => "{$code} Company",
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function ticket(Company $company, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'Queue ticket',
            'description' => 'Test ticket',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'created_at' => now('Asia/Manila'),
        ], $overrides));
    }

    private function item(Company $company, array $overrides = []): Item
    {
        $id = DB::table('items')->insertGetId(array_merge([
            'name' => 'POS concern',
            'priority' => 'medium',
            'is_active' => true,
            'company_id' => $company->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Item::findOrFail($id);
    }

    private function childNode(
        Company $company,
        string $rootName,
        string $childName,
        string $codePrefix
    ): DepartmentNode {
        $department = Department::create([
            'name' => "{$rootName} Department",
            'company_id' => $company->id,
        ]);
        $root = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => $rootName,
            'code' => "{$codePrefix}-ROOT",
        ]);

        return DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $root->id,
            'name' => $childName,
            'code' => "{$codePrefix}-CHILD",
        ]);
    }
}
