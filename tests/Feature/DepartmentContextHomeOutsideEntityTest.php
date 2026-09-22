<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\DepartmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "I belong to" selector rendered blank when the active entity had none of
 * the user's departments: the home department was not among the options.
 */
class DepartmentContextHomeOutsideEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_department_is_shared_when_it_sits_under_another_entity(): void
    {
        $tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $dbs = Company::create(['name' => 'Distinto Beverage Solutions', 'code' => 'DBS', 'is_active' => true]);
        $tas = Department::create(['name' => 'Technology', 'code' => 'TAS', 'company_id' => $tgi->id, 'is_active' => true]);
        Department::create(['name' => 'DBS', 'code' => 'DBS', 'company_id' => $dbs->id, 'is_active' => true]);

        $user = User::factory()->create(['company_id' => $tgi->id, 'department_id' => $tas->id]);
        $role = \App\Models\Role::create(['name' => 'Two Entities', 'guard_name' => 'web']);
        $role->companies()->sync([$tgi->id, $dbs->id]);
        $user->assignRole($role);

        $this->actingAs($user);
        session([CompanyContext::SESSION_KEY => $dbs->id]);
        CompanyContext::flushMemo();

        $shared = DepartmentContext::share($user);

        $this->assertSame($tas->id, $shared['home']);
        $this->assertNotContains($tas->id, array_column($shared['departments'], 'id'));
        $this->assertSame(
            ['id' => $tas->id, 'name' => 'Technology', 'code' => 'TAS', 'inEntity' => false],
            $shared['homeDepartment']
        );

        session([CompanyContext::SESSION_KEY => $tgi->id]);
        CompanyContext::flushMemo();

        $this->assertTrue(DepartmentContext::share($user)['homeDepartment']['inEntity']);
    }
}
