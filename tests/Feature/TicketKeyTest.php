<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class TicketKeyTest extends TestCase
{
    use RefreshDatabase;
    public function test_ticket_key_is_generated_correctly_based_on_company_code()
    {
        $this->withoutMiddleware();
        
        // Setup
        $company = Company::create([
            'name' => 'Mimi Corp',
            'code' => 'MM',
            'description' => 'Test',
            'is_active' => true
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        
        // Ensure user has permission to create tickets if middleware checks it (logic usually in controller or request)
        // For now, let's assume basic auth is enough or assign role if needed.
        // The controller uses `StoreTicketRequest` which authorizes true.
        // But the middleware might block. 
        // We'll actingAs($user).

        $data = [
            'title' => 'First Ticket',
            'description' => 'Testing key generation',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'open', // Add status to validation rules
            'severity' => 'minor',
            'company_id' => $company->id,
            'attachments' => []
        ];

        // Act - Create 1st Ticket
        $this->withoutExceptionHandling();
        $response = $this->actingAs($user)->post(route('tickets.store'), $data);

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tickets', [
            'ticket_key' => 'MM-1',
            'title' => 'First Ticket'
        ]);

        // Act - Create 2nd Ticket
        $response = $this->actingAs($user)->post(route('tickets.store'), array_merge($data, ['title' => 'Second Ticket']));

        // Assert
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tickets', [
            'ticket_key' => 'MM-2',
            'title' => 'Second Ticket'
        ]);
    }

    public function test_ticket_key_increments_separately_for_different_companies()
    {
        $this->withoutMiddleware();

        $company1 = Company::create(['name' => 'Comp A', 'code' => 'CA']);
        $company2 = Company::create(['name' => 'Comp B', 'code' => 'CB']);
        
        $user = User::factory()->create();

        // Ticket for Company A
        $this->actingAs($user)->post(route('tickets.store'), [
            'title' => 'T1', 'type' => 'task', 'priority' => 'medium', 'status' => 'open', 'severity' => 'minor', 'company_id' => $company1->id
        ]);

        // Ticket for Company B
        $this->actingAs($user)->post(route('tickets.store'), [
            'title' => 'T2', 'type' => 'task', 'priority' => 'medium', 'status' => 'open', 'severity' => 'minor', 'company_id' => $company2->id
        ]);
        
        // Another for Company A
        $this->actingAs($user)->post(route('tickets.store'), [
            'title' => 'T3', 'type' => 'task', 'priority' => 'medium', 'status' => 'open', 'severity' => 'minor', 'company_id' => $company1->id
        ]);

        $this->assertDatabaseHas('tickets', ['ticket_key' => 'CA-1']);
        $this->assertDatabaseHas('tickets', ['ticket_key' => 'CB-1']);
        $this->assertDatabaseHas('tickets', ['ticket_key' => 'CA-2']);
    }

    public function test_ticket_key_uses_the_store_owning_company_not_the_ticket_company()
    {
        $ticketCompany = Company::create(['name' => 'Ticket Co', 'code' => 'TC', 'is_active' => true]);
        $storeCompany = Company::create(['name' => 'Store Co', 'code' => 'SC', 'is_active' => true]);
        $store = $this->store($storeCompany);

        // The ticket is stamped to TC but sits on an SC-owned store — key must be SC-*.
        $ticket = Ticket::create([
            'title' => 'On store',
            'description' => 'x',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $ticketCompany->id,
            'store_id' => $store->id,
        ]);

        $this->assertSame('SC-1', $ticket->ticket_key);
    }

    public function test_ticket_key_regenerates_to_store_company_when_store_is_set_later()
    {
        // Mirrors the email-fetch flow: created store-less, then a store is assigned.
        $ticketCompany = Company::create(['name' => 'Ticket Co', 'code' => 'TC', 'is_active' => true]);
        $storeCompany = Company::create(['name' => 'Store Co', 'code' => 'SC', 'is_active' => true]);
        $store = $this->store($storeCompany);

        $ticket = Ticket::create([
            'title' => 'From email',
            'description' => 'x',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $ticketCompany->id,
        ]);
        $this->assertSame('TC-1', $ticket->ticket_key);

        // Auto-assign later resolves the store — key follows the store's company.
        $ticket->update(['store_id' => $store->id]);
        $this->assertSame('SC-1', $ticket->fresh()->ticket_key);
    }

    public function test_renumbering_a_ticket_records_the_old_key_as_an_alias()
    {
        $tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $nono = Company::create(['name' => "Nono's", 'code' => 'NONO', 'is_active' => true]);

        $ticket = Ticket::create([
            'title' => 'CCTV footage request',
            'description' => 'x',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $tgi->id,
        ]);
        $this->assertSame('TGI-1', $ticket->ticket_key);

        // Move it to another company — the key follows the company code.
        $ticket->update(['company_id' => $nono->id]);
        $this->assertSame('NONO-1', $ticket->fresh()->ticket_key);

        // The old key is remembered against the same ticket.
        $this->assertDatabaseHas('ticket_key_aliases', [
            'ticket_key' => 'TGI-1',
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_a_retired_key_number_is_not_reissued_to_a_new_ticket()
    {
        $tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $nono = Company::create(['name' => "Nono's", 'code' => 'NONO', 'is_active' => true]);

        $ticket = Ticket::create([
            'title' => 'First', 'description' => 'x', 'type' => 'task',
            'status' => 'open', 'priority' => 'medium', 'severity' => 'minor',
            'company_id' => $tgi->id,
        ]);
        $this->assertSame('TGI-1', $ticket->ticket_key);

        // Renumber it away from TGI, freeing TGI-1 from the live tickets table.
        $ticket->update(['company_id' => $nono->id]);
        $this->assertSame('NONO-1', $ticket->fresh()->ticket_key);

        // A brand new TGI ticket must skip the retired number, not reuse it.
        $next = Ticket::create([
            'title' => 'Second', 'description' => 'x', 'type' => 'task',
            'status' => 'open', 'priority' => 'medium', 'severity' => 'minor',
            'company_id' => $tgi->id,
        ]);
        $this->assertSame('TGI-2', $next->ticket_key);
    }

    private function store(Company $company): Store
    {
        return Store::create([
            'code' => 'ST-'.uniqid(),
            'name' => 'Store',
            'sector' => 1,
            'area' => 'A',
            'brand' => 'B',
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $company->id,
        ]);
    }
}
