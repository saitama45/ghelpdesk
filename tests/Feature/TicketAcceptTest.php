<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Item;
use App\Models\Role;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketAcceptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_accept_ticket_does_not_require_full_update_title_payload(): void
    {
        [$company, $store, $item] = $this->acceptReferences();
        $agent = $this->assignableUser($company);
        $ticket = $this->ticket($company);

        $this->actingAs($agent)
            ->postJson(route('tickets.accept', $ticket), [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'item_id' => $item->id,
                'department' => 'Support',
            ])
            ->assertOk()
            ->assertJsonPath('ticket.id', $ticket->id)
            ->assertJsonPath('ticket.assignee_id', $agent->id)
            ->assertJsonPath('ticket.assignee.id', $agent->id);

        $ticket->refresh();

        $this->assertSame($agent->id, $ticket->assignee_id);
        $this->assertSame($company->id, $ticket->company_id);
        $this->assertSame($store->id, $ticket->store_id);
        $this->assertSame($item->id, $ticket->item_id);
        $this->assertSame($item->category_id, $ticket->category_id);
        $this->assertSame($item->sub_category_id, $ticket->sub_category_id);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame('Support', $ticket->department);
    }

    public function test_accept_ticket_requires_assign_permission(): void
    {
        [$company, $store, $item] = $this->acceptReferences();
        $user = User::factory()->create(['company_id' => $company->id]);
        $ticket = $this->ticket($company);

        $this->actingAs($user)
            ->postJson(route('tickets.accept', $ticket), [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'item_id' => $item->id,
                'department' => 'Support',
            ])
            ->assertForbidden();
    }

    public function test_accept_ticket_rejects_ticket_already_accepted_by_another_user(): void
    {
        [$company, $store, $item] = $this->acceptReferences();
        $agent = $this->assignableUser($company);
        $otherAgent = $this->assignableUser($company, 'Other Agent');
        $ticket = $this->ticket($company, ['assignee_id' => $otherAgent->id]);

        $this->actingAs($agent)
            ->postJson(route('tickets.accept', $ticket), [
                'company_id' => $company->id,
                'store_id' => $store->id,
                'item_id' => $item->id,
                'department' => 'Support',
            ])
            ->assertStatus(409);

        $this->assertSame($otherAgent->id, $ticket->refresh()->assignee_id);
    }

    private function acceptReferences(): array
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $store = Store::create([
            'company_id' => $company->id,
            'name' => 'Main Store',
            'code' => 'MS',
            'sector' => 1,
            'area' => 'Metro Manila',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Hardware',
            'is_active' => true,
        ]);

        $subCategory = SubCategory::create([
            'category_id' => $category->id,
            'name' => 'POS',
            'is_active' => true,
        ]);

        $item = Item::create([
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'name' => 'Terminal issue',
            'priority' => 'High',
            'is_active' => true,
        ]);

        return [$company, $store, $item];
    }

    private function assignableUser(Company $company, string $name = 'Agent'): User
    {
        Permission::firstOrCreate(['name' => 'tickets.assign', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(
            ['name' => "Assignable {$name}", 'guard_name' => 'web'],
            ['is_assignable' => true]
        );
        $role->givePermissionTo('tickets.assign');

        $user = User::factory()->create([
            'name' => $name,
            'company_id' => $company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function ticket(Company $company, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'Existing ticket title',
            'description' => 'Existing ticket description.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'is_deleted' => false,
        ], $overrides));
    }
}
