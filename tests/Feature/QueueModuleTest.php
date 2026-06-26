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

        Setting::set("queue_board_token_company_{$companyA->id}", 'board-token-a', 'queue');

        $ticketA = $this->ticket($companyA, ['title' => 'Company A ticket']);
        $ticketB = $this->ticket($companyB, ['title' => 'Company B ticket']);

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

    public function test_empty_lane_configuration_keeps_assigned_tickets_claimable_from_triage(): void
    {
        $company = $this->company('ALP');
        $department = Department::create(['name' => 'Support', 'company_id' => $company->id]);
        $node = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Field',
            'code' => 'FIELD',
        ]);
        $currentAssignee = User::factory()->create(['department_node_id' => $node->id]);
        $agent = User::factory()->create();
        $ticket = $this->ticket($company, ['assignee_id' => $currentAssignee->id]);

        Setting::set('queue_lane_nodes', json_encode([]), 'queue');

        $claimed = app(QueueService::class)->claimNextWaitingTicket(QueueService::TRIAGE_KEY, $agent);

        $this->assertNotNull($claimed);
        $this->assertSame($ticket->id, $claimed->id);
        $this->assertSame('in_progress', $claimed->status);
        $this->assertSame($agent->id, (int) $claimed->assignee_id);
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
}
