<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Role;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A ticket's item must come from the entity of its store (items.company_id).
 */
class TicketItemEntityTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $nonos;

    private Store $nonosStore;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $this->nonos = Company::create(['name' => "NONO'S", 'code' => 'NONOS', 'is_active' => true]);
        $this->nonosStore = Store::create([
            'company_id' => $this->nonos->id, 'code' => 'NON-1', 'name' => 'Nonos Store', 'sector' => 1,
            'area' => 'A', 'brand' => 'B', 'class' => 'Regular', 'is_active' => true,
        ]);
    }

    public function test_creating_a_ticket_requires_an_item_from_the_store_entity(): void
    {
        $user = $this->agent();
        $tgiItem = $this->item('TGI Laptop', $this->tgi);
        $nonosItem = $this->item('Nonos Orders', $this->nonos);

        $this->actingAs($user)
            ->postJson(route('tickets.store'), $this->payload($tgiItem))
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->actingAs($user)
            ->postJson(route('tickets.store'), $this->payload($nonosItem))
            ->assertCreated();
    }

    public function test_a_brand_store_can_use_items_of_the_entities_it_is_tagged_to(): void
    {
        $user = $this->agent();
        $gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'is_active' => true, 'type' => 'Entity']);
        // NONOS is tagged to TGI on /companies, but not to GSI.
        $this->nonos->entities()->sync([$this->tgi->id]);

        $tgiItem = $this->item('TGI Laptop', $this->tgi);
        $gsiItem = $this->item('GSI Printer', $gsi);

        $this->actingAs($user)
            ->postJson(route('tickets.store'), $this->payload($tgiItem))
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('tickets.store'), $this->payload($gsiItem))
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        // The picker feed says who may use each item.
        $usable = collect($this->actingAs($user)->getJson(route('tickets.data.items'))->assertOk()->json())
            ->keyBy('name')
            ->map(fn ($item) => $item['usable_company_ids']);

        $this->assertEqualsCanonicalizing([$this->tgi->id, $this->nonos->id], $usable['TGI Laptop']);
        $this->assertSame([$gsi->id], $usable['GSI Printer']);
    }

    public function test_a_ticket_partner_must_come_from_the_store_company_or_its_tagged_entities(): void
    {
        $user = $this->agent();
        $gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'is_active' => true, 'type' => 'Entity']);
        $this->nonos->entities()->sync([$this->tgi->id]);
        $item = $this->item('Nonos Orders', $this->nonos);

        $vendor = fn (string $name, ?Company $company) => tap(
            \App\Models\Vendor::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'is_active' => true]),
            fn ($v) => $v->forceFill(['company_id' => $company?->id])->save()
        );

        $this->actingAs($user)
            ->postJson(route('tickets.store'), [...$this->payload($item), 'vendor_id' => $vendor('GSI Partner', $gsi)->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vendor_id');

        $this->actingAs($user)
            ->postJson(route('tickets.store'), [...$this->payload($item), 'vendor_id' => $vendor('TGI Partner', $this->tgi)->id])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('tickets.store'), [...$this->payload($item), 'vendor_id' => $vendor('Shared Partner', null)->id])
            ->assertCreated();
    }

    public function test_the_ticket_store_picker_covers_the_viewed_entity_its_entities_and_its_brands(): void
    {
        $user = $this->agent();
        $gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'is_active' => true, 'type' => 'Entity']);
        $this->nonos->entities()->sync([$this->tgi->id]);
        $store = fn (string $name, Company $company) => Store::create([
            'company_id' => $company->id, 'code' => strtoupper(substr(md5($name), 0, 8)), 'name' => $name,
            'sector' => 1, 'area' => 'A', 'brand' => 'B', 'class' => 'Regular', 'is_active' => true,
        ]);
        $store('TGI Office', $this->tgi);
        $store('GSI Office', $gsi);

        $names = function (Company $active) use ($user) {
            \App\Support\CompanyContext::flushMemo();

            return collect($this->actingAs($user)
                ->withSession([\App\Support\CompanyContext::SESSION_KEY => $active->id])
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
                ])
                ->get(route('tickets.index'))
                ->assertOk()
                ->json('props.stores'))->pluck('name')->sort()->values()->all();
        };

        // Brand: its own stores + its tagged entity's; never an untagged entity's
        // and never a SIBLING brand's.
        $this->assertSame(['Nonos Store', 'TGI Office'], $names($this->nonos));
        // Entity: its own stores + those of every brand tagged to it. An entity
        // operates its brands' locations, so a TGI ticket must be able to name a
        // NONO'S store; GSI is a separate entity and stays out.
        $this->assertSame(['Nonos Store', 'TGI Office'], $names($this->tgi));
    }

    public function test_the_edit_store_picker_follows_the_active_entity_not_the_ticket_company(): void
    {
        $user = $this->agent();
        $this->nonos->entities()->sync([$this->tgi->id]);
        $cbtl = Company::create(['name' => 'CBTL', 'code' => 'CBTL', 'is_active' => true]);
        $cbtl->entities()->sync([$this->tgi->id]);
        Store::create([
            'company_id' => $cbtl->id, 'code' => 'CBTL-1', 'name' => 'CBTL Store', 'sector' => 1,
            'area' => 'A', 'brand' => 'B', 'class' => 'Regular', 'is_active' => true,
        ]);

        // A TGI ticket: its own company must NOT widen the picker, or switching the
        // switcher to NONO'S would still list every TGI brand's stores.
        $ticket = Ticket::create([
            'title' => 'Entity check', 'description' => 'x', 'type' => 'task', 'status' => 'open',
            'priority' => 'medium', 'severity' => 'minor', 'company_id' => $this->tgi->id,
            'store_id' => $this->nonosStore->id,
        ]);

        $names = function (Company $active) use ($user, $ticket) {
            \App\Support\CompanyContext::flushMemo();

            return collect($this->actingAs($user)
                ->withSession([\App\Support\CompanyContext::SESSION_KEY => $active->id])
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
                ])
                ->get(route('tickets.edit', $ticket))
                ->assertOk()
                ->json('props.stores'))->pluck('name')->sort()->values()->all();
        };

        // On NONO'S: its own store only - no sibling CBTL store, despite the ticket
        // belonging to TGI, which owns both brands.
        $this->assertSame(['Nonos Store'], $names($this->nonos));
        // On TGI: the entity operates both brands' locations.
        $this->assertSame(['CBTL Store', 'Nonos Store'], $names($this->tgi));
    }

    public function test_accepting_a_ticket_rejects_an_item_from_another_entity(): void
    {
        $user = $this->agent();
        $ticket = Ticket::create([
            'title' => 'Walk-in', 'description' => 'x', 'type' => 'task', 'status' => 'open',
            'priority' => 'medium', 'severity' => 'minor', 'company_id' => $this->nonos->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('tickets.accept', $ticket), [
                'company_id' => $this->nonos->id,
                'store_id' => $this->nonosStore->id,
                'item_id' => $this->item('TGI Laptop', $this->tgi)->id,
                'department' => 'Support',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');

        $this->assertNull($ticket->fresh()->assignee_id);
    }

    private function payload(Item $item): array
    {
        return [
            'company_id' => $this->nonos->id,
            'store_id' => $this->nonosStore->id,
            'item_id' => $item->id,
            'title' => 'Orders not syncing',
            'description' => 'Entity check.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'is_self_requester' => true,
            'notify_requester' => false,
        ];
    }

    private function agent(): User
    {
        foreach (['tickets.create', 'tickets.view', 'tickets.assign'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::create(['name' => 'Agent', 'guard_name' => 'web', 'is_assignable' => true]);
        $role->givePermissionTo(['tickets.create', 'tickets.view', 'tickets.assign']);
        $role->companies()->sync([$this->tgi->id, $this->nonos->id]);

        $user = User::factory()->create(['company_id' => $this->tgi->id]);
        $user->assignRole($role);

        return $user;
    }

    private function item(string $name, Company $company): Item
    {
        $item = Item::create(['name' => $name, 'priority' => 'High', 'concern_type' => 'Incident', 'is_active' => true]);
        $item->forceFill(['company_id' => $company->id])->save();

        return $item;
    }
}
