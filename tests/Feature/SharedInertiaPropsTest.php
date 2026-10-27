<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * HandleInertiaRequests::share() runs on every web request, including the bell and
 * presence polls. Its per-user lookups are deferred so those JSON polls skip them,
 * while a rendered page must still receive every shared prop exactly as before.
 */
class SharedInertiaPropsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $this->department = Department::create([
            'name' => 'Technology',
            'code' => 'TAS',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ]);
        $role = Role::create(['name' => 'Shared Props Role', 'guard_name' => 'web']);
        $role->companies()->sync([$this->company->id]);
        $role->givePermissionTo(Permission::create(['name' => 'presence.view', 'guard_name' => 'web']));
        $this->user->assignRole($role);
    }

    public function test_a_rendered_page_still_receives_every_shared_prop(): void
    {
        // /presence renders without touching the user's relations, so this payload
        // matches the one the eager share() produced. (A controller that reloads
        // roles itself, like ProfileController::edit, used to drop roles.companies
        // from auth.user; with deferred props every page now carries it.)
        $this->actingAs($this->user)
            ->get(route('presence.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $this->user->id)
                // auth.user is serialized with the relations the lookup loads onto it.
                ->where('auth.user.roles.0.companies.0.id', $this->company->id)
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('presence.view'))
                ->where('activeCompany.id', $this->company->id)
                ->where('availableCompanies.0.id', $this->company->id)
                ->where('departmentContext.home', $this->department->id)
                ->has('sidebarLayout')
                ->has('dynamicForms')
                ->has('ticketStatuses')
                ->has('flash')
                ->etc()
            );
    }

    public function test_a_json_poll_skips_the_shared_prop_lookups(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->user)
            ->postJson(route('presence.update'), ['status' => 'online'])
            ->assertOk();

        foreach (['departments', 'companies', 'form_definitions'] as $table) {
            $this->assertEmpty(
                array_filter($queries, fn ($sql) => str_contains($sql, $table)),
                "A presence poll should not query {$table}."
            );
        }
    }
}
