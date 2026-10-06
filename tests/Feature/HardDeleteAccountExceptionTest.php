<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\User;
use App\Services\AccountArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The delete icon on /users and on /stamps → Customers archives every account
 * except the one named in `HardDeleteAccounts`, which is removed from the
 * database on the spot.
 *
 * No row is archived here. The permanent path is exercised for real; wherever
 * a request would reach the archive, `archiveUser` / `archiveCustomer` is
 * faked so it is only asserted to have been chosen, never run.
 */
class HardDeleteAccountExceptionTest extends TestCase
{
    use RefreshDatabase;

    private const LISTED = 'garudaperez45+review@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_listed_account_is_removed_from_the_database_with_its_customer_record(): void
    {
        [$member, $customer] = $this->member(self::LISTED);
        $program = $this->program();
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 3, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $member))
            ->assertRedirect()
            ->assertSessionHas('success', 'User permanently deleted, together with their loyalty customer record "Review Member".');

        // Gone, not archived: withTrashed() would still find a soft-deleted row.
        $this->assertNull(User::withTrashed()->find($member->id));
        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->assertSame(0, StampCard::where('customer_id', $customer->id)->count());
    }

    public function test_a_listed_staff_login_with_no_customer_record_is_removed_too(): void
    {
        // Stored in a different case than the setting: the match must not care.
        $staff = User::factory()->create(['name' => 'Review Staff', 'email' => 'GarudaPerez45+Review@Gmail.com']);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $staff))
            ->assertRedirect()
            ->assertSessionHas('success', 'User permanently deleted.');

        $this->assertNull(User::withTrashed()->find($staff->id));
    }

    public function test_every_other_account_still_goes_to_the_archive(): void
    {
        $other = User::factory()->create(['email' => 'garudaperez45@gmail.com']);

        $this->expectArchiveInsteadOfPermanentDelete();

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $other))
            ->assertRedirect()
            ->assertSessionHas('success', 'User archived. Restore it from Settings → Account Archive.');

        $this->assertNotNull(User::find($other->id));
    }

    public function test_an_empty_setting_switches_the_exception_off(): void
    {
        config(['services.hard_delete_accounts.email' => '']);
        [$member] = $this->member(self::LISTED);

        $this->expectArchiveInsteadOfPermanentDelete();

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $member))
            ->assertRedirect();

        $this->assertNotNull(User::find($member->id));
    }

    public function test_a_customer_with_financial_records_refuses_the_permanent_delete(): void
    {
        [$member, $customer] = $this->member(self::LISTED);
        $this->redeemARewardFor($customer);

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $member))
            ->assertSessionHasErrors('user');

        // Refused outright: nothing deleted, and nothing quietly archived instead.
        $this->assertNotNull(User::find($member->id));
        $this->assertNotNull(Customer::find($customer->id));
        $this->assertSame(1, StampRedemption::where('customer_id', $customer->id)->count());
    }

    public function test_the_listed_account_cannot_be_deleted_without_users_delete(): void
    {
        [$member, $customer] = $this->member(self::LISTED);

        $this->actingAs($this->admin(['users.view']))
            ->delete(route('users.destroy', $member))
            ->assertForbidden();

        $this->assertNotNull(User::find($member->id));
        $this->assertNotNull(Customer::find($customer->id));
    }

    public function test_the_page_is_told_which_address_is_deleted_permanently(): void
    {
        $this->actingAs($this->admin())
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('users.index'))
            ->assertOk()
            ->assertJsonPath('props.hardDeleteEmails', [self::LISTED]);
    }

    /* ----------------------------------------------------------------------
     | /stamps → Customers
     * ------------------------------------------------------------------- */

    public function test_deleting_the_listed_customer_from_stamps_removes_it_with_its_app_login(): void
    {
        [$member, $customer] = $this->member(self::LISTED);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $this->program()->id,
            'stamps_count' => 3, 'status' => 'active',
        ]);

        $this->actingAs($this->admin(['stamps.view', 'stamps.delete']))
            ->delete(route('stamps.customers.destroy', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', 'Customer permanently deleted, together with their app login "Review Member".');

        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->assertNull(User::withTrashed()->find($member->id));
        $this->assertSame(0, StampCard::where('customer_id', $customer->id)->count());
    }

    public function test_a_listed_walk_in_with_no_app_login_is_removed_from_stamps_too(): void
    {
        $walkIn = Customer::create(['name' => 'Review Walk-in', 'email' => 'GarudaPerez45+Review@Gmail.com', 'is_active' => true]);

        $this->actingAs($this->admin(['stamps.view', 'stamps.delete']))
            ->delete(route('stamps.customers.destroy', $walkIn))
            ->assertRedirect()
            ->assertSessionHas('success', 'Customer permanently deleted.');

        $this->assertNull(Customer::withTrashed()->find($walkIn->id));
    }

    public function test_the_customer_behind_a_listed_login_counts_even_under_another_address(): void
    {
        // Staff changed the address on the Stamps side only; it is still the
        // same account, and /users would delete this very pair for good.
        [$member, $customer] = $this->member(self::LISTED);
        $customer->forceFill(['email' => 'renamed@example.test'])->save();
        $unrelated = Customer::create(['name' => 'Someone Else', 'email' => 'someone@example.test', 'is_active' => true]);

        $this->assertSame([$customer->id], app(AccountArchiveService::class)->hardDeleteCustomerIds());

        $this->actingAs($this->admin(['stamps.view', 'stamps.delete']))
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('stamps.index', ['tab' => 'customers']))
            ->assertOk()
            ->assertJsonPath('props.hardDeleteCustomerIds', [$customer->id]);

        $this->assertNotNull(Customer::find($unrelated->id));
        $this->assertNotNull(User::find($member->id));
    }

    public function test_every_other_customer_still_goes_to_the_archive(): void
    {
        $other = Customer::create(['name' => 'Regular', 'email' => 'garudaperez45@gmail.com', 'is_active' => true]);

        $this->partialMock(AccountArchiveService::class, function (MockInterface $mock) {
            $mock->shouldReceive('archiveCustomer')->once()->andReturn(['customer' => 'Regular', 'user' => null]);
            $mock->shouldNotReceive('deleteCustomerPermanently');
        });

        $this->actingAs($this->admin(['stamps.view', 'stamps.delete']))
            ->delete(route('stamps.customers.destroy', $other))
            ->assertRedirect()
            ->assertSessionHas('success', 'Customer archived. Restore it from Settings → Account Archive.');

        $this->assertNotNull(Customer::find($other->id));
    }

    public function test_financial_records_refuse_the_permanent_delete_from_stamps(): void
    {
        [$member, $customer] = $this->member(self::LISTED);
        $this->redeemARewardFor($customer);

        $this->actingAs($this->admin(['stamps.view', 'stamps.delete']))
            ->delete(route('stamps.customers.destroy', $customer))
            ->assertRedirect()
            ->assertSessionHas('error', 'Review Member cannot be deleted permanently: their customer record "Review Member" has reward redemptions or voucher payments, which are kept as financial records.');

        $this->assertNotNull(Customer::find($customer->id));
        $this->assertNotNull(User::find($member->id));
        $this->assertSame(1, StampRedemption::where('customer_id', $customer->id)->count());
    }

    public function test_the_listed_customer_cannot_be_deleted_without_stamps_delete(): void
    {
        [$member, $customer] = $this->member(self::LISTED);

        $this->actingAs($this->admin(['stamps.view']))
            ->delete(route('stamps.customers.destroy', $customer))
            ->assertForbidden();

        $this->assertNotNull(Customer::find($customer->id));
        $this->assertNotNull(User::find($member->id));
    }

    /** The archive is asserted to be chosen, and faked so no row is soft-deleted. */
    private function expectArchiveInsteadOfPermanentDelete(): void
    {
        $this->partialMock(AccountArchiveService::class, function (MockInterface $mock) {
            $mock->shouldReceive('archiveUser')->once()->andReturn(['user' => 'Someone', 'customer' => null]);
            $mock->shouldNotReceive('deleteUserPermanently');
        });
    }

    /** A reward redemption: the financial record that must outlive the customer. */
    private function redeemARewardFor(Customer $customer): void
    {
        $program = $this->program();
        $category = Category::create(['name' => 'Consumables']);
        $asset = Asset::create([
            'item_code' => 'ASSET-1', 'description' => 'Free Latte', 'type' => 'Consumables',
            'category_id' => $category->id,
        ]);
        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 12, 'status' => 'redeemed',
        ]);
        StampRedemption::create([
            'stamp_card_id' => $card->id, 'customer_id' => $customer->id,
            'stamp_program_id' => $program->id, 'asset_id' => $asset->id,
            'location' => 'CBTL Ayala 30th', 'quantity' => 1,
        ]);
    }

    /** @return array{0: User, 1: Customer} */
    private function member(string $email): array
    {
        $customer = Customer::create(['name' => 'Review Member', 'email' => $email, 'is_active' => true]);
        $member = User::factory()->create(['name' => 'Review Member', 'email' => $email, 'customer_id' => $customer->id]);
        // Api\RegisterController stamps a member as the creator of their own rows.
        $member->forceFill(['created_by' => $member->id, 'updated_by' => $member->id])->save();
        $customer->forceFill(['created_by' => $member->id, 'updated_by' => $member->id])->save();

        return [$member, $customer];
    }

    private function program(): StampProgram
    {
        $company = Company::create(['name' => 'Coffee Bean & Tea Leaf', 'code' => 'CBTL']);

        return StampProgram::create([
            'name' => 'CBTL Campaign', 'year' => 2026, 'stamps_required' => 12,
            'company_id' => $company->id, 'is_active' => true,
        ]);
    }

    private function admin(array $permissions = ['users.view', 'users.delete']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::create(['name' => 'User Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $admin = User::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }
}
