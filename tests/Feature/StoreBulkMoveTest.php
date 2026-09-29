<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * /stores bulk move: the active entity's own stores move to another entity,
 * keeping store names unique per entity and inherited rows read-only.
 */
class StoreBulkMoveTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $cbtl;

    private Company $dbs;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'type' => 'Entity', 'is_active' => true]);
        $this->cbtl = Company::create(['name' => 'Coffee Bean', 'code' => 'CBTL', 'type' => 'Brand', 'is_active' => true]);
        $this->dbs = Company::create(['name' => 'Distinto', 'code' => 'DBS', 'type' => 'Entity', 'is_active' => true]);
        // CBTL inherits TGI's stores read-only.
        $this->cbtl->entities()->sync([$this->tgi->id]);

        Permission::findOrCreate('stores.view', 'web');
        Permission::findOrCreate('stores.edit', 'web');

        $this->role = Role::create(['name' => 'Store Keeper', 'guard_name' => 'web']);
        $this->role->givePermissionTo(['stores.view', 'stores.edit']);
        $this->role->companies()->sync([$this->tgi->id, $this->cbtl->id, $this->dbs->id]);
    }

    public function test_moves_selected_stores_and_their_brand_label_to_the_target_entity(): void
    {
        $user = $this->editor();
        $first = $this->makeStore('Vertis North', $this->cbtl);
        $second = $this->makeStore('SM Aura', $this->cbtl);
        $untouched = $this->makeStore('Greenbelt', $this->cbtl);
        $first->users()->attach($user->id);

        $this->moveAs($user, $this->cbtl, [$first->id, $second->id], $this->dbs)
            ->assertRedirect(route('stores.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Moved 2 stores to Distinto.');

        foreach ([$first, $second] as $store) {
            $store->refresh();
            $this->assertSame($this->dbs->id, $store->company_id);
            $this->assertSame('DBS', $store->brand);
        }
        $this->assertSame($this->cbtl->id, $untouched->fresh()->company_id);
        $this->assertSame([$user->id], $first->users()->pluck('users.id')->all());
    }

    public function test_refuses_a_name_the_target_entity_already_uses(): void
    {
        $user = $this->editor();
        $store = $this->makeStore('Vertis North', $this->cbtl);
        $this->makeStore('Vertis North', $this->dbs);

        $this->moveAs($user, $this->cbtl, [$store->id], $this->dbs)
            ->assertSessionHasErrors(['company_id' => 'Distinto would have more than one store named "Vertis North". Rename the store first, then move it.']);

        $this->assertSame($this->cbtl->id, $store->fresh()->company_id);
    }

    public function test_refuses_two_selected_stores_with_the_same_name(): void
    {
        $user = $this->editor();
        $first = $this->makeStore('Vertis North', $this->cbtl);
        $second = $this->makeStore('Vertis North', $this->cbtl);

        $this->moveAs($user, $this->cbtl, [$first->id, $second->id], $this->dbs)
            ->assertSessionHasErrors('company_id');

        $this->assertSame($this->cbtl->id, $first->fresh()->company_id);
        $this->assertSame($this->cbtl->id, $second->fresh()->company_id);
    }

    public function test_inherited_stores_cannot_be_moved_from_the_brand(): void
    {
        $user = $this->editor();
        $own = $this->makeStore('Own Store', $this->cbtl);
        $inherited = $this->makeStore('TGI Store', $this->tgi);

        $this->moveAs($user, $this->cbtl, [$own->id, $inherited->id], $this->dbs)
            ->assertSessionHasErrors('store_ids');

        $this->assertSame($this->cbtl->id, $own->fresh()->company_id);
        $this->assertSame($this->tgi->id, $inherited->fresh()->company_id);
    }

    public function test_stores_already_in_the_target_are_reported_not_moved(): void
    {
        $user = $this->editor();
        $store = $this->makeStore('Vertis North', $this->cbtl);

        $this->moveAs($user, $this->cbtl, [$store->id], $this->cbtl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'The selected stores already belong to Coffee Bean.');
    }

    public function test_bulk_move_requires_store_edit_permission(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->cbtl->id]);
        $viewer->givePermissionTo('stores.view');
        $store = $this->makeStore('Vertis North', $this->cbtl);

        $this->moveAs($viewer, $this->cbtl, [$store->id], $this->dbs)->assertForbidden();

        $this->assertSame($this->cbtl->id, $store->fresh()->company_id);
    }

    private function editor(): User
    {
        $user = User::factory()->create(['company_id' => $this->cbtl->id]);
        $user->assignRole($this->role);

        return $user;
    }

    private function makeStore(string $name, Company $company): Store
    {
        static $count = 1;

        return Store::create([
            'code' => 'MV'.str_pad((string) $count++, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'sector' => 1,
            'area' => 'North',
            'brand' => $company->code,
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $company->id,
        ]);
    }

    private function moveAs(User $user, Company $active, array $storeIds, Company $target)
    {
        CompanyContext::flushMemo();

        return $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $active->id])
            ->from(route('stores.index'))
            ->post(route('stores.bulk-move'), [
                'store_ids' => $storeIds,
                'company_id' => $target->id,
            ]);
    }
}
