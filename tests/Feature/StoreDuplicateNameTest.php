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
 * /stores create + edit: the code identifies a store and stays globally
 * unique; the name may repeat, even within one entity.
 */
class StoreDuplicateNameTest extends TestCase
{
    use RefreshDatabase;

    private Company $nono;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->nono = Company::create(['name' => "NONO'S", 'code' => 'NN', 'type' => 'Entity', 'is_active' => true]);

        foreach (['stores.view', 'stores.create', 'stores.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::create(['name' => 'Store Keeper', 'guard_name' => 'web']);
        $role->givePermissionTo(['stores.view', 'stores.create', 'stores.edit']);
        $role->companies()->sync([$this->nono->id]);

        $this->user = User::factory()->create(['company_id' => $this->nono->id]);
        $this->user->assignRole($role);
    }

    public function test_a_store_can_be_created_with_a_name_already_used_in_the_same_entity(): void
    {
        $this->makeStore('NN NT1', 'NN Naia Terminal 1');

        $this->send('post', route('stores.store'), $this->payload('NN NT1B', 'NN Naia Terminal 1'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Store::where('name', 'NN Naia Terminal 1')->where('company_id', $this->nono->id)->count());
    }

    public function test_changing_the_code_of_a_store_whose_name_is_shared_saves(): void
    {
        $this->makeStore('NN NT1-OLD', 'NN Naia Terminal 1');
        $store = $this->makeStore('NN NT1-TMP', 'NN Naia Terminal 1');

        $this->send('put', route('stores.update', $store), $this->payload('NN NT1', 'NN Naia Terminal 1'))
            ->assertSessionHasNoErrors();

        $this->assertSame('NN NT1', $store->fresh()->code);
    }

    public function test_a_code_already_in_use_is_still_rejected(): void
    {
        $this->makeStore('NN NT1', 'NN Naia Terminal 1');
        $store = $this->makeStore('NN NT2', 'NN Naia Terminal 2');

        $this->send('post', route('stores.store'), $this->payload('NN NT1', 'Another Name'))
            ->assertSessionHasErrors('code');
        $this->send('put', route('stores.update', $store), $this->payload('NN NT1', 'NN Naia Terminal 2'))
            ->assertSessionHasErrors('code');

        $this->assertSame('NN NT2', $store->fresh()->code);
    }

    private function payload(string $code, string $name): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'sector' => 7,
            'area' => 'South',
            'company_id' => $this->nono->id,
            'class' => 'Kitchen Only',
            'is_active' => true,
        ];
    }

    private function makeStore(string $code, string $name): Store
    {
        return Store::create([
            'code' => $code,
            'name' => $name,
            'sector' => 7,
            'area' => 'South',
            'brand' => $this->nono->code,
            'class' => 'Kitchen Only',
            'is_active' => true,
            'company_id' => $this->nono->id,
        ]);
    }

    private function send(string $method, string $url, array $data)
    {
        CompanyContext::flushMemo();

        return $this->actingAs($this->user)
            ->withSession([CompanyContext::SESSION_KEY => $this->nono->id])
            ->from(route('stores.index'))
            ->{$method}($url, $data);
    }
}
