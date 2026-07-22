<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalSearchEntityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tas_department_searches_all_accessible_entities(): void
    {
        [$viewer, $firstCompany, $secondCompany, $inaccessibleCompany] = $this->makeViewerWithEntities('tas');
        $viewer->givePermissionTo(Permission::findOrCreate('schedules.view', 'web'));

        $this->createSearchableSchedules($firstCompany, $secondCompany, $inaccessibleCompany);

        $response = $this->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $firstCompany->id])
            ->getJson(route('global-search', ['query' => 'CrossEntityNeedle', 'tab' => 'schedules']));

        $response->assertOk()
            ->assertJsonCount(2, 'schedules')
            ->assertJsonPath('schedules.0.company_name', $firstCompany->name)
            ->assertJsonPath('schedules.1.company_name', $secondCompany->name);

        $this->assertEqualsCanonicalizing(
            [$firstCompany->id, $secondCompany->id],
            collect($response->json('schedules'))->pluck('company_id')->all()
        );
        $this->assertNotContains($inaccessibleCompany->id, collect($response->json('schedules'))->pluck('company_id')->all());
    }

    public function test_non_tas_department_remains_limited_to_the_active_entity(): void
    {
        [$viewer, $firstCompany, $secondCompany, $inaccessibleCompany] = $this->makeViewerWithEntities('IT');
        $viewer->givePermissionTo(Permission::findOrCreate('schedules.view', 'web'));

        $this->createSearchableSchedules($firstCompany, $secondCompany, $inaccessibleCompany);

        $this->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $firstCompany->id])
            ->getJson(route('global-search', ['query' => 'CrossEntityNeedle', 'tab' => 'schedules']))
            ->assertOk()
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.company_id', $firstCompany->id);
    }

    public function test_tas_cross_entity_search_still_requires_module_permission(): void
    {
        [$viewer, $firstCompany, $secondCompany, $inaccessibleCompany] = $this->makeViewerWithEntities('TAS');
        $this->createSearchableSchedules($firstCompany, $secondCompany, $inaccessibleCompany);

        $this->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $firstCompany->id])
            ->getJson(route('global-search', ['query' => 'CrossEntityNeedle', 'tab' => 'schedules']))
            ->assertOk()
            ->assertJsonCount(0, 'schedules');
    }

    private function makeViewerWithEntities(string $departmentCode): array
    {
        $firstCompany = Company::create([
            'name' => 'Alpha Entity',
            'code' => 'ALPHA',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $secondCompany = Company::create([
            'name' => 'Beta Entity',
            'code' => 'BETA',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $inaccessibleCompany = Company::create([
            'name' => 'Gamma Entity',
            'code' => 'GAMMA',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $department = Department::create([
            'name' => strtoupper($departmentCode) . ' Department',
            'code' => $departmentCode,
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'company_id' => $firstCompany->id,
            'department_id' => $department->id,
        ]);
        $role = Role::create([
            'name' => 'Entity Searcher ' . strtoupper($departmentCode),
            'guard_name' => 'web',
        ]);
        $role->companies()->attach($secondCompany->id);
        $viewer->assignRole($role);

        return [$viewer, $firstCompany, $secondCompany, $inaccessibleCompany];
    }

    private function createSearchableSchedules(Company ...$companies): void
    {
        $employee = User::factory()->create();

        foreach ($companies as $index => $company) {
            $schedule = Schedule::create([
                'user_id' => $employee->id,
                'status' => 'On-site',
                'start_time' => now()->addDays($index),
                'end_time' => now()->addDays($index)->addHours(8),
                'remarks' => 'CrossEntityNeedle',
            ]);
            $schedule->forceFill(['company_id' => $company->id])->save();
        }
    }
}
