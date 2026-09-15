<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Item;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DavidTicketTallyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-david-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.integrations.david.key' => self::KEY]);
    }

    public function test_it_rejects_a_missing_or_wrong_key(): void
    {
        $this->getJson($this->url())->assertStatus(401);
        $this->withHeader('X-Integration-Key', 'nope')->getJson($this->url())
            ->assertStatus(401)
            ->assertJsonPath('helpdesk_key_fingerprint', substr(hash('sha256', self::KEY), 0, 8).' (14 chars)')
            ->assertJsonPath('received_key_fingerprint', substr(hash('sha256', 'nope'), 0, 8).' (4 chars)');
        $this->assertStringNotContainsString(self::KEY, $this->withHeader('X-Integration-Key', 'nope')->getJson($this->url())->getContent());
        // Surrounding whitespace is tolerated: the key check passes (422 = no such entity in this test).
        $this->withHeader('X-Integration-Key', ' '.self::KEY."\n")->getJson($this->url())->assertStatus(422);

        config(['services.integrations.david.key' => '']);
        $this->withHeader('X-Integration-Key', '')->getJson($this->url())->assertStatus(401);
    }

    public function test_it_tallies_incoming_and_closed_per_week_module_and_entity(): void
    {
        $nonos = Company::create(['name' => "NONO'S", 'code' => 'NONOS', 'is_active' => true]);
        $cbtl = Company::create(['name' => 'CBTL', 'code' => 'CBTL', 'is_active' => true]);

        $orders = $this->item('Orders', 'david.order');
        $internet = $this->item('Admin / Technical Concerns - Internet', 'david.admin');
        $laptop = $this->item('Admin / Technical Concerns - Laptop', 'david.admin');
        $unmapped = $this->item('Wastages Concern (No Stock)', null);

        // Week 38 (Mon 2026-09-14).
        $this->ticket($nonos, $orders, 'open', '2026-09-14 08:00:00');
        $this->ticket($nonos, $orders, 'resolved', '2026-09-16 10:00:00');
        $this->ticket($nonos, $orders, 'closed', '2026-09-20 23:30:00');
        $this->ticket($nonos, $internet, 'closed', '2026-09-15 09:00:00');
        $this->ticket($nonos, $laptop, 'in_progress', '2026-09-15 09:00:00');
        // Week 37: counts toward its creation week even though it is closed now.
        $this->ticket($nonos, $orders, 'closed', '2026-09-13 23:59:00');

        // Excluded: other entity, unmapped item, child ticket.
        $this->ticket($cbtl, $orders, 'open', '2026-09-15 09:00:00');
        $this->ticket($nonos, $unmapped, 'open', '2026-09-15 09:00:00');
        $parent = $this->ticket($nonos, $orders, 'open', '2026-09-15 09:00:00');
        $child = $this->ticket($nonos, $orders, 'open', '2026-09-15 09:00:00');
        $child->forceFill(['parent_id' => $parent->id])->save();

        $response = $this->withHeader('X-Integration-Key', self::KEY)
            ->getJson($this->url(['entity' => 'nonos', 'date_from' => '2026-09-09', 'date_to' => '2026-09-17']))
            ->assertOk()
            ->assertJsonPath('entity.code', 'NONOS')
            ->assertJsonPath('date_from', '2026-09-07')
            ->assertJsonPath('date_to', '2026-09-20');

        $weeks = collect($response->json('weeks'))->keyBy('week_start');

        $this->assertSame(['2026-09-07', '2026-09-14'], $weeks->keys()->all());

        $week38 = $weeks['2026-09-14']['modules'];
        // 3 orders + the parent (the child is excluded).
        $this->assertSame(['incoming' => 4, 'closed' => 1], $week38['order']);
        $this->assertSame(['incoming' => 2, 'closed' => 1], $week38['admin']);
        $this->assertSame(['incoming' => 0, 'closed' => 0], $week38['wastage']);

        $this->assertSame(['incoming' => 1, 'closed' => 1], $weeks['2026-09-07']['modules']['order']);
    }

    public function test_it_rejects_an_unknown_entity(): void
    {
        $this->withHeader('X-Integration-Key', self::KEY)
            ->getJson($this->url(['entity' => 'NOPE']))
            ->assertStatus(422);
    }

    private function url(array $params = []): string
    {
        return route('api.integrations.david.ticket-tally', array_merge([
            'entity' => 'NONOS',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ], $params));
    }

    private function item(string $name, ?string $reportKey): Item
    {
        $item = Item::create(['name' => $name, 'priority' => 'Low', 'concern_type' => 'Incident', 'is_active' => true]);
        DB::table('items')->where('id', $item->id)->update(['report_key' => $reportKey]);

        return $item;
    }

    private function ticket(Company $company, Item $item, string $status, string $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'title' => "{$item->name} {$status}",
            'description' => 'David tally fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'item_id' => $item->id,
        ]);

        DB::table('tickets')->where('id', $ticket->id)->update(['created_at' => $createdAt]);

        return $ticket;
    }
}
