<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Store;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every ticket must carry an owning entity (company_id): the entity-gated
 * index can never match NULL, so an entity-less ticket is invisible to every
 * user. These tests pin the TicketObserver fallbacks that guarantee it.
 */
class TicketCompanyFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgi = Company::create(['name' => 'The Generics Inc', 'code' => 'TGI', 'is_active' => true]);
        $this->other = Company::create(['name' => 'Other Entity', 'code' => 'OTH', 'is_active' => true]);
    }

    private function makeTicket(array $attributes): Ticket
    {
        return Ticket::create($attributes + [
            'title' => 'Fallback test ticket',
            'description' => 'Ticket company fallback test.',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'type' => 'task',
        ]);
    }

    public function test_companyless_ticket_with_store_inherits_the_stores_owner(): void
    {
        $store = Store::create([
            'code' => 'OTH1',
            'name' => 'Other Store',
            'sector' => 1,
            'area' => 'North',
            'brand' => 'Other',
            'is_active' => true,
            'company_id' => $this->other->id,
        ]);

        $ticket = $this->makeTicket(['store_id' => $store->id]);

        $this->assertSame($this->other->id, $ticket->company_id);
        $this->assertStringStartsWith('OTH-', $ticket->ticket_key);
    }

    public function test_unauthenticated_companyless_ticket_defaults_to_tgi(): void
    {
        // No auth user (email fetcher / scheduler context), no store.
        $ticket = $this->makeTicket([]);

        $this->assertSame($this->tgi->id, $ticket->company_id);
        $this->assertStringStartsWith('TGI-', $ticket->ticket_key);
    }

    public function test_explicitly_provided_company_is_untouched(): void
    {
        $ticket = $this->makeTicket(['company_id' => $this->other->id]);

        $this->assertSame($this->other->id, $ticket->company_id);
        $this->assertStringStartsWith('OTH-', $ticket->ticket_key);
    }

    public function test_updates_cannot_clear_the_owning_company(): void
    {
        $ticket = $this->makeTicket(['company_id' => $this->other->id]);
        $originalKey = $ticket->ticket_key;

        $ticket->update(['company_id' => null]);
        $ticket->refresh();

        $this->assertSame($this->other->id, $ticket->company_id);
        // And the key was not renumbered to EXT-*.
        $this->assertSame($originalKey, $ticket->ticket_key);

        // A NULL arriving alongside other legitimate changes is also ignored.
        $ticket->update(['company_id' => null, 'status' => 'in_progress']);
        $ticket->refresh();

        $this->assertSame($this->other->id, $ticket->company_id);
        $this->assertSame('in_progress', $ticket->status);
    }

    public function test_company_changes_between_entities_still_work(): void
    {
        $ticket = $this->makeTicket(['company_id' => $this->other->id]);

        $ticket->update(['company_id' => $this->tgi->id]);
        $ticket->refresh();

        $this->assertSame($this->tgi->id, $ticket->company_id);
        $this->assertStringStartsWith('TGI-', $ticket->ticket_key);
    }

    public function test_ticket_without_any_companies_in_the_system_stays_null(): void
    {
        Ticket::withoutGlobalScope(ActiveEntityScope::class)->delete();
        Store::query()->delete();
        Company::query()->delete();

        $ticket = $this->makeTicket([]);

        $this->assertNull($ticket->company_id);
        $this->assertStringStartsWith('EXT-', $ticket->ticket_key);
    }
}
