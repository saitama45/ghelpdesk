<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class TicketKeyTest extends TestCase
{
    use DatabaseTransactions;
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
}
