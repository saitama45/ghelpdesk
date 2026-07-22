<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_employee_id_is_returned_and_searchable_on_the_users_page(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'name' => 'Employee ID Search Target',
            'employee_id_no' => 'EMP-SEARCH-1001',
        ]);

        $response = $this->actingAs($admin)->get(route('users.index', [
            'search' => 'EMP-SEARCH-1001',
        ]));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['users']['data']);

        $this->assertSame([$user->id], $rows->pluck('id')->all());
        $this->assertSame('EMP-SEARCH-1001', $rows->first()['employee_id_no']);
    }

    public function test_employee_id_can_be_created_and_must_be_unique(): void
    {
        $admin = $this->admin();
        $role = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Employee One',
                'employee_id_no' => 'EMP-CRUD-1001',
                'email' => 'employee.one@example.com',
                'password' => 'password123',
                'role' => $role->name,
                'is_active' => true,
                'is_manager' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'employee_id_no' => 'EMP-CRUD-1001',
            'email' => 'employee.one@example.com',
        ]);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Employee Two',
                'employee_id_no' => 'EMP-CRUD-1001',
                'email' => 'employee.two@example.com',
                'password' => 'password123',
                'role' => $role->name,
                'is_active' => true,
                'is_manager' => false,
            ])
            ->assertSessionHasErrors('employee_id_no');
    }

    public function test_employee_id_can_be_updated_without_conflicting_with_another_user(): void
    {
        $admin = $this->admin();
        $role = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $user = User::factory()->create(['employee_id_no' => 'EMP-OLD']);
        $user->assignRole($role);

        $this->actingAs($admin)
            ->put(route('users.update', $user), [
                'name' => $user->name,
                'employee_id_no' => 'EMP-NEW',
                'email' => $user->email,
                'role' => $role->name,
                'is_active' => true,
                'is_manager' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('EMP-NEW', $user->refresh()->employee_id_no);
    }

    public function test_import_skips_duplicate_employee_ids_within_the_file(): void
    {
        $admin = $this->admin();
        $permission = Permission::findOrCreate('users.create', 'web');
        $admin->givePermissionTo($permission);
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,employee_id_no,email,role,department,position,date_hired,is_manager,is_active,assigned_stores,reports_to\nEmployee One,EMP-IMPORT-1,import.one@example.com,Employee,,,,No,Yes,,\nEmployee Two,EMP-IMPORT-1,import.two@example.com,Employee,,,,No,Yes,,\n"
        );

        $response = $this->actingAs($admin)->post(route('users.import'), ['file' => $file]);

        $response
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('users', ['employee_id_no' => 'EMP-IMPORT-1', 'email' => 'import.one@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'import.two@example.com']);
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
