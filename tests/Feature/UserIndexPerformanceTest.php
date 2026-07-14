<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserIndexPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_users_page_excludes_heavy_modal_payloads(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create(['is_active' => true, 'is_manager' => true]);
        $user = User::factory()->create(['name' => 'Lightweight User']);
        $store = $this->store('FAST-1', true);
        $user->stores()->attach($store->id);
        $user->managers()->attach($manager->id);

        $response = $this->actingAs($admin)->get(route('users.index'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        foreach (['stores', 'managers', 'permissions', 'companies', 'departmentTree'] as $heavyProp) {
            $this->assertArrayNotHasKey($heavyProp, $props);
        }

        $row = collect($props['users']['data'])->firstWhere('id', $user->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['stores_count']);
        $this->assertSame($manager->id, $row['managers'][0]['id']);
        $this->assertArrayNotHasKey('stores', $row);
        $this->assertArrayNotHasKey('creator', $row);
        $this->assertArrayNotHasKey('updater', $row);
    }

    public function test_user_modal_data_is_loaded_from_small_read_only_endpoints(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create(['is_active' => true, 'is_manager' => true]);
        $inactiveManager = User::factory()->create(['is_active' => false, 'is_manager' => true]);
        $user = User::factory()->create(['created_by' => $admin->id, 'updated_by' => $admin->id]);
        $activeStore = $this->store('FAST-2', true);
        $inactiveStore = $this->store('FAST-X', false);
        $user->stores()->attach($activeStore->id);
        $user->managers()->attach($manager->id);

        $optionsResponse = $this->actingAs($admin)
            ->getJson(route('users.form-options'))
            ->assertOk()
            ->assertJsonPath('stores.0.id', $activeStore->id)
            ->assertJsonFragment(['id' => $manager->id, 'name' => $manager->name]);

        $this->assertSame([$activeStore->id], collect($optionsResponse->json('stores'))->pluck('id')->all());
        $this->assertSame([$manager->id], collect($optionsResponse->json('managers'))->pluck('id')->all());

        $this->actingAs($admin)
            ->getJson(route('users.details', $user))
            ->assertOk()
            ->assertJsonPath('user.store_ids.0', $activeStore->id)
            ->assertJsonPath('user.manager_ids.0', $manager->id)
            ->assertJsonPath('user.creator.id', $admin->id)
            ->assertJsonPath('user.updater.id', $admin->id);
    }

    public function test_role_editor_catalog_is_not_needed_until_requested(): void
    {
        $admin = $this->admin();
        $company = Company::create(['name' => 'Editor Company', 'code' => 'EDITOR', 'is_active' => true]);
        $role = Role::create(['name' => 'Editor Role', 'guard_name' => 'web']);
        $permission = Permission::findOrCreate('users.view', 'web');
        $role->givePermissionTo($permission);
        $role->companies()->attach($company->id);

        $this->actingAs($admin)
            ->getJson(route('roles.editor-data', $role))
            ->assertOk()
            ->assertJsonPath('role.id', $role->id)
            ->assertJsonPath('role.permissions.0.name', 'users.view')
            ->assertJsonPath('role.companies.0.id', $company->id)
            ->assertJsonFragment(['name' => 'users.view']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->assignRole($role);

        return $admin;
    }

    private function store(string $code, bool $active): Store
    {
        return Store::create([
            'code' => $code,
            'name' => 'Store '.$code,
            'sector' => 1,
            'area' => 'Area',
            'brand' => 'Brand',
            'class' => 'Regular',
            'is_active' => $active,
        ]);
    }
}
