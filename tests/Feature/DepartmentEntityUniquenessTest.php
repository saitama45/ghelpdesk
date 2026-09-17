<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Department names are unique per entity; codes are unique across all entities —
 * in validation and in the schema.
 */
class DepartmentEntityUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $entech;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $this->entech = Company::create(['name' => 'ENTECH', 'code' => 'ENTECH', 'is_active' => true]);

        $this->user = User::factory()->create(['company_id' => $this->tgi->id]);
        $role = \App\Models\Role::create(['name' => 'Dept Keeper', 'guard_name' => 'web']);
        $role->companies()->sync([$this->tgi->id, $this->entech->id]);
        $this->user->assignRole($role);
        foreach (['departments.create', 'departments.edit'] as $permission) {
            $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->department('Business Development', 'BD', $this->tgi);
    }

    public function test_the_same_name_can_be_created_in_another_entity(): void
    {
        $this->storeIn($this->entech, ['name' => 'Business Development', 'code' => 'EBD'])
            ->assertSessionHasNoErrors();

        $created = Department::where('company_id', $this->entech->id)->first();
        $this->assertSame(['Business Development', 'EBD'], [$created->name, $created->code]);
    }

    public function test_a_code_is_rejected_even_in_another_entity(): void
    {
        $this->storeIn($this->entech, ['name' => 'Business Development', 'code' => 'BD'])
            ->assertSessionHasErrors(['code' => 'This code is already used by another department.']);

        $this->assertSame(0, Department::where('company_id', $this->entech->id)->count());
    }

    public function test_the_same_name_or_code_is_rejected_within_one_entity(): void
    {
        $this->storeIn($this->tgi, ['name' => 'Business Development', 'code' => 'BDX'])
            ->assertSessionHasErrors(['name' => 'This entity already has a department with this name.']);
        $this->storeIn($this->tgi, ['name' => 'Brand Development', 'code' => 'BD'])
            ->assertSessionHasErrors(['code' => 'This code is already used by another department.']);

        $this->assertSame(1, Department::where('company_id', $this->tgi->id)->count());
    }

    public function test_editing_checks_the_departments_own_entity(): void
    {
        $entechDept = $this->department('Business Support', 'EBS', $this->entech);
        $this->department('Business Support', 'BS', $this->tgi);

        // Renaming to a TGI-only name is fine even while TGI is the active entity.
        $this->updateIn($this->tgi, $entechDept, ['name' => 'Business Development', 'code' => 'EBD'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Business Development', $entechDept->fresh()->name);

        // Saving unchanged values does not collide with itself.
        $this->updateIn($this->entech, $entechDept, ['name' => 'Business Development', 'code' => 'EBD'])
            ->assertSessionHasNoErrors();

        // A name taken inside the department's own entity is rejected...
        $other = $this->department('Marketing', 'EMKTG', $this->entech);
        $this->updateIn($this->entech, $other, ['name' => 'Business Development', 'code' => 'EMKTG'])
            ->assertSessionHasErrors('name');
        // ...and so is a code taken by ANY entity.
        $this->updateIn($this->entech, $other, ['name' => 'Marketing', 'code' => 'BD'])
            ->assertSessionHasErrors('code');
    }

    public function test_the_schema_scopes_names_per_entity_and_codes_globally(): void
    {
        $this->department('Operations', null, $this->tgi);
        $this->department('Admin', null, $this->entech);
        $this->department('Business Development', 'EBD', $this->entech);

        $this->assertSchemaRejects(fn () => $this->department('Business Development', 'X', $this->tgi));
        $this->assertSchemaRejects(fn () => $this->department('Other', 'BD', $this->entech));
    }

    private function assertSchemaRejects(callable $write): void
    {
        try {
            $write();
            $this->fail('The schema accepted a duplicate.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function storeIn(Company $entity, array $data)
    {
        CompanyContext::flushMemo();

        return $this->actingAs($this->user)
            ->withSession([CompanyContext::SESSION_KEY => $entity->id])
            ->post(route('departments.store'), $data + ['is_active' => true]);
    }

    private function updateIn(Company $entity, Department $department, array $data)
    {
        CompanyContext::flushMemo();

        return $this->actingAs($this->user)
            ->withSession([CompanyContext::SESSION_KEY => $entity->id])
            ->put(route('departments.update', $department), $data + ['is_active' => true]);
    }

    private function department(string $name, ?string $code, Company $company): Department
    {
        return Department::create(['name' => $name, 'code' => $code, 'is_active' => true, 'company_id' => $company->id]);
    }
}
