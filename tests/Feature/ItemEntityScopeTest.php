<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * /items is managed per active entity: the list, create uniqueness and the
 * edit URL guard all follow the sidebar entity switcher.
 */
class ItemEntityScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $nonos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $this->nonos = Company::create(['name' => "NONO'S", 'code' => 'NONOS', 'is_active' => true]);
    }

    public function test_the_list_follows_the_active_entity(): void
    {
        $this->item('TGI Laptop', $this->tgi);
        $this->item('Nonos Orders', $this->nonos);

        $user = $this->userWithAccessToBoth(['items.view']);

        $this->assertSame(['TGI Laptop'], $this->listedNames($user, $this->tgi));
        $this->assertSame(['Nonos Orders'], $this->listedNames($user, $this->nonos));
    }

    public function test_a_brand_also_lists_its_tagged_entities_items_but_cannot_edit_them(): void
    {
        $gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'is_active' => true]);
        // NONOS is tagged to TGI on /companies, not to GSI.
        $this->nonos->entities()->sync([$this->tgi->id]);

        $tgiItem = $this->item('TGI Laptop', $this->tgi);
        $this->item('Nonos Orders', $this->nonos);
        $this->item('GSI Printer', $gsi);

        $user = $this->userWithAccessToBoth(['items.view', 'items.edit']);

        $listed = $this->listedNames($user, $this->nonos);
        sort($listed);
        $this->assertSame(['Nonos Orders', 'TGI Laptop'], $listed);
        // The entity itself does not inherit its brands' items.
        $this->assertSame(['TGI Laptop'], $this->listedNames($user, $this->tgi));

        // Inherited rows are managed from their own entity.
        CompanyContext::flushMemo();
        $this->actingAs($user)->withSession([CompanyContext::SESSION_KEY => $this->nonos->id])
            ->put(route('items.update', $tgiItem), ['name' => 'Renamed', 'priority' => 'Low', 'concern_type' => 'Incident'])
            ->assertNotFound();
        $this->assertSame('TGI Laptop', $tgiItem->fresh()->name);
    }

    public function test_a_new_item_is_stamped_with_the_active_entity_and_unique_only_within_it(): void
    {
        $this->item('Orders', $this->tgi);
        $user = $this->userWithAccessToBoth(['items.view', 'items.create']);

        $payload = ['name' => 'Orders', 'priority' => 'Low', 'concern_type' => 'Incident', 'is_active' => true];

        // Same name in another entity is allowed and lands in that entity.
        $this->actingAs($user)->withSession([CompanyContext::SESSION_KEY => $this->nonos->id])
            ->post(route('items.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Item::where('name', 'Orders')->where('company_id', $this->nonos->id)->count());

        // A duplicate inside the same entity is still rejected.
        CompanyContext::flushMemo();
        $this->actingAs($user)->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->post(route('items.store'), $payload)
            ->assertSessionHasErrors('name');
    }

    public function test_an_item_from_another_entity_cannot_be_edited_by_url(): void
    {
        $nonosItem = $this->item('Nonos Orders', $this->nonos);
        $user = $this->userWithAccessToBoth(['items.view', 'items.edit']);

        $this->actingAs($user)->withSession([CompanyContext::SESSION_KEY => $this->tgi->id])
            ->put(route('items.update', $nonosItem), [
                'name' => 'Hijacked', 'priority' => 'Low', 'concern_type' => 'Incident',
            ])
            ->assertNotFound();

        $this->assertSame('Nonos Orders', $nonosItem->fresh()->name);
    }

    private function listedNames(User $user, Company $active): array
    {
        CompanyContext::flushMemo();

        $response = $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $active->id])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('items.index'));

        $response->assertOk();
        $this->assertSame($active->id, $response->json('props.activeCompanyId'));

        return collect($response->json('props.items.data'))->pluck('name')->all();
    }

    private function userWithAccessToBoth(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->tgi->id]);

        $role = \App\Models\Role::create(['name' => 'Item Keeper', 'guard_name' => 'web']);
        $role->companies()->sync([$this->tgi->id, $this->nonos->id]);
        $user->assignRole($role);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function item(string $name, Company $company): Item
    {
        $item = Item::create(['name' => $name, 'priority' => 'Low', 'concern_type' => 'Incident', 'is_active' => true]);
        $item->forceFill(['company_id' => $company->id])->save();

        return $item;
    }
}
