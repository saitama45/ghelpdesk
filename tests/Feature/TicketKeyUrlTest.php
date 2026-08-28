<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Item;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketKeyAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ticket URLs are addressed by the human key — `/tickets/TGI-4096/edit` — while every
 * older way of addressing the same ticket keeps resolving: the UUID (every link
 * already mailed out carries one) and any key retired by a renumber.
 */
class TicketKeyUrlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->company = Company::create(['name' => 'Test Group Inc', 'code' => 'TGI', 'is_active' => true]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_generated_urls_use_the_ticket_key_not_the_uuid(): void
    {
        $ticket = $this->ticket();

        $this->assertSame($ticket->ticket_key, $ticket->getRouteKey());
        $this->assertStringEndsWith(
            '/tickets/' . $ticket->ticket_key . '/edit',
            route('tickets.edit', $ticket)
        );
        $this->assertStringNotContainsString((string) $ticket->id, route('tickets.edit', $ticket));
    }

    public function test_a_keyless_ticket_still_links_by_uuid(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['ticket_key' => null])->saveQuietly();

        $this->assertSame($ticket->id, $ticket->fresh()->getRouteKey());
    }

    public function test_the_edit_page_opens_on_the_key(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user)
            ->get('/tickets/' . $ticket->ticket_key . '/edit')
            ->assertOk();
    }

    public function test_a_uuid_url_still_works_and_redirects_to_the_key(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user)
            ->get('/tickets/' . $ticket->id . '/edit')
            ->assertRedirect(route('tickets.edit', $ticket));
    }

    public function test_a_uuid_url_keeps_its_query_string_through_the_redirect(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user)
            ->get('/tickets/' . $ticket->id . '/edit?tab=activity')
            ->assertRedirect(route('tickets.edit', [$ticket, 'tab' => 'activity']));
    }

    public function test_a_retired_key_still_resolves_after_a_renumber(): void
    {
        $ticket = $this->ticket();
        $oldKey = $ticket->ticket_key;

        TicketKeyAlias::create(['ticket_id' => $ticket->id, 'ticket_key' => 'OLD-9001']);

        $this->actingAs($this->user)
            ->get('/tickets/OLD-9001/edit')
            ->assertRedirect(route('tickets.edit', $ticket));

        $this->assertSame($oldKey, $ticket->fresh()->ticket_key);
    }

    public function test_the_rewrite_does_not_swallow_flashed_errors(): void
    {
        $ticket = $this->ticket();

        // A validation error flashed onto a UUID URL has to survive the hop to the
        // key URL, or the page that finally renders shows no error at all.
        $this->actingAs($this->user)
            ->withSession(['errors' => new \Illuminate\Support\ViewErrorBag])
            ->followingRedirects()
            ->get('/tickets/' . $ticket->id . '/edit?flash=1')
            ->assertOk();

        $this->actingAs($this->user)
            ->withSession(['success' => 'Kept across the rewrite.'])
            ->get('/tickets/' . $ticket->id . '/edit')
            ->assertRedirect(route('tickets.edit', $ticket))
            ->assertSessionHas('success', 'Kept across the rewrite.');
    }

    public function test_an_unknown_key_is_a_404_not_a_database_error(): void
    {
        $this->ticket();

        $this->actingAs($this->user)
            ->get('/tickets/NOPE-1/edit')
            ->assertNotFound();
    }

    public function test_write_endpoints_still_accept_the_uuid_the_frontend_posts(): void
    {
        $ticket = $this->ticket();

        // The Vue pages still post ticket.id to the non-navigational routes; the
        // binding has to keep accepting that or every save on the page 404s.
        $this->actingAs($this->user)
            ->post(route('tickets.comments.store', $ticket->id), ['comment_text' => 'Via UUID.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'comment_text' => 'Via UUID.',
        ]);
    }

    public function test_write_endpoints_also_accept_the_key(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->user)
            ->post(route('tickets.comments.store', $ticket->ticket_key), ['comment_text' => 'Via key.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'comment_text' => 'Via key.',
        ]);
    }

    private function item(): Item
    {
        $category = Category::firstOrCreate(['name' => 'Hardware'], ['is_active' => true]);

        return Item::firstOrCreate(
            ['name' => 'POS Terminal'],
            [
                'category_id' => $category->id,
                'description' => 'POS device issues',
                'priority' => 'medium',
                'concern_type' => 'Incident',
                'is_active' => true,
            ]
        );
    }

    /**
     * Fully classified so the response gate ({@see TicketResponseClassificationTest})
     * does not stand in for the binding under test.
     */
    private function ticket(array $overrides = []): Ticket
    {
        $store = Store::firstOrCreate(
            ['code' => 'TG1'],
            [
                'name' => 'Test Store',
                'sector' => 1,
                'area' => 'North',
                'brand' => 'Test',
                'is_active' => true,
                'company_id' => $this->company->id,
            ]
        );

        return Ticket::create(array_merge([
            'title' => 'Terminal offline',
            'description' => 'Cannot connect.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $this->company->id,
            'store_id' => $store->id,
            'item_id' => $this->item()->id,
            'assignee_id' => $this->user->id,
            'department' => 'Information Technology',
        ], $overrides));
    }
}
