<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A loyalty member (mobile-app sign-up, `customer_id` set) is not staff: /users
 * may save one with no role (how staff take back a role granted by mistake)
 * and no employee ID. A staff login still needs both.
 */
class MemberRoleOptionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function editor(): User
    {
        $editor = User::factory()->create(['is_active' => true]);
        $editor->givePermissionTo(
            Permission::findOrCreate('users.view', 'web'),
            Permission::findOrCreate('users.edit', 'web'),
        );

        return $editor;
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'employee_id_no' => $user->employee_id_no,
            'email' => $user->email,
            'role' => '',
            'is_active' => true,
            'is_manager' => false,
            'store_ids' => [],
            'manager_ids' => [],
        ], $overrides);
    }

    public function test_a_member_can_be_saved_with_no_role_which_clears_a_granted_one(): void
    {
        $customer = Customer::create(['name' => 'Test Member', 'email' => 'member@example.com', 'is_active' => true]);
        $member = User::factory()->create([
            'email' => 'member@example.com',
            'employee_id_no' => '111',
            'customer_id' => $customer->id,
        ]);
        $member->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $this->actingAs($this->editor())
            ->put(route('users.update', $member), $this->payload($member))
            ->assertSessionHasNoErrors();

        $this->assertFalse($member->fresh()->roles()->exists());
    }

    public function test_a_member_saves_without_an_employee_id(): void
    {
        $customer = Customer::create(['name' => 'App Member', 'email' => 'app@example.com', 'is_active' => true]);
        $member = User::factory()->create(['email' => 'app@example.com', 'employee_id_no' => null, 'customer_id' => $customer->id]);
        $other = User::factory()->create(['employee_id_no' => null, 'customer_id' => $customer->id]);

        $this->actingAs($this->editor())
            ->put(route('users.update', $member), $this->payload($member, ['employee_id_no' => '', 'name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $member->fresh()->name);
        $this->assertNull($member->fresh()->employee_id_no);
        $this->assertNull($other->fresh()->employee_id_no);
    }

    public function test_a_staff_login_still_requires_an_employee_id(): void
    {
        $staff = User::factory()->create(['employee_id_no' => 'EMP-2']);
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->actingAs($this->editor())
            ->put(route('users.update', $staff), $this->payload($staff, ['employee_id_no' => '', 'role' => 'Employee']))
            ->assertSessionHasErrors('employee_id_no');

        $this->assertSame('EMP-2', $staff->fresh()->employee_id_no);
    }

    public function test_a_staff_login_still_requires_a_role(): void
    {
        $staff = User::factory()->create(['employee_id_no' => 'EMP-1']);
        $staff->assignRole(Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']));

        $this->actingAs($this->editor())
            ->put(route('users.update', $staff), $this->payload($staff))
            ->assertSessionHasErrors('role');

        $this->assertTrue($staff->fresh()->hasRole('Employee'));
    }
}
