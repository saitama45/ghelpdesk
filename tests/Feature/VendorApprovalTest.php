<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The approval matrix on /vendors — the decision that grants or withdraws a
 * vendor's portal access. Covered here rather than in the browser suite: every
 * one of these paths writes, and the developer database holds real registrations.
 */
class VendorApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function approver(array $permissions = ['vendors.view', 'vendors.approve']): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private static int $sequence = 0;

    private function portalVendor(string $status = Vendor::STATUS_PENDING): Vendor
    {
        $n = ++self::$sequence;

        $vendor = Vendor::create([
            'name' => "Portal Vendor {$n}",
            'email' => "portal{$n}@example.com",
            'is_active' => $status === Vendor::STATUS_ACTIVE,
        ]);

        // password is deliberately not fillable — it is what makes a row a login.
        $vendor->forceFill(['password' => Hash::make('password123'), 'status' => $status])->save();

        return $vendor->refresh();
    }

    public function test_approving_activates_the_account_and_records_who_decided(): void
    {
        $vendor = $this->portalVendor();
        $approver = $this->approver();

        $this->actingAs($approver)
            ->put("/vendors/{$vendor->id}/approval", ['action' => 'approve'])
            ->assertRedirect();

        $vendor->refresh();
        $this->assertSame(Vendor::STATUS_ACTIVE, $vendor->status);
        $this->assertTrue($vendor->is_active);
        $this->assertSame($approver->id, $vendor->approved_by);
        $this->assertNotNull($vendor->approved_at);

        $decision = VendorApproval::first();
        $this->assertSame('approved', $decision->action);
        $this->assertSame('pending', $decision->status_before);
        $this->assertSame('active', $decision->status_after);
        $this->assertSame($approver->id, $decision->decided_by);
    }

    /**
     * Regression: the decision label used to be built by appending "d" to the
     * verb, which recorded "rejectd" and "suspendd".
     */
    public function test_every_decision_is_recorded_in_readable_past_tense(): void
    {
        $approver = $this->approver();

        foreach ([
            ['reject', Vendor::STATUS_PENDING, 'rejected', Vendor::STATUS_REJECTED],
            ['suspend', Vendor::STATUS_ACTIVE, 'suspended', Vendor::STATUS_SUSPENDED],
            ['reactivate', Vendor::STATUS_SUSPENDED, 'reactivated', Vendor::STATUS_ACTIVE],
        ] as [$action, $from, $expectedLabel, $expectedStatus]) {
            $vendor = $this->portalVendor($from);

            $response = $this->actingAs($approver)
                ->put("/vendors/{$vendor->id}/approval", ['action' => $action, 'remarks' => 'Because.']);

            $response->assertSessionHas('success', "Vendor {$expectedLabel} successfully");
            $this->assertSame($expectedStatus, $vendor->refresh()->status);
            $this->assertSame($expectedLabel, VendorApproval::where('vendor_id', $vendor->id)->value('action'));
        }
    }

    public function test_a_refusal_or_suspension_must_carry_a_reason(): void
    {
        $vendor = $this->portalVendor();

        $this->actingAs($this->approver())
            ->put("/vendors/{$vendor->id}/approval", ['action' => 'reject'])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(Vendor::STATUS_PENDING, $vendor->refresh()->status);
        $this->assertSame(0, VendorApproval::count());
    }

    public function test_rejecting_and_suspending_switch_the_account_off(): void
    {
        $vendor = $this->portalVendor(Vendor::STATUS_ACTIVE);

        $this->actingAs($this->approver())
            ->put("/vendors/{$vendor->id}/approval", ['action' => 'suspend', 'remarks' => 'Expired permits.']);

        $vendor->refresh();
        $this->assertSame(Vendor::STATUS_SUSPENDED, $vendor->status);
        $this->assertFalse($vendor->is_active);
    }

    public function test_a_reference_vendor_has_no_approval_matrix(): void
    {
        // No password: a back-office record, not a portal login.
        $vendor = Vendor::create(['name' => 'Reference Only', 'is_active' => true]);

        $this->actingAs($this->approver())
            ->put("/vendors/{$vendor->id}/approval", ['action' => 'approve'])
            ->assertNotFound();
    }

    public function test_the_edit_form_cannot_switch_a_portal_account_on_or_off(): void
    {
        $vendor = $this->portalVendor();
        $editor = $this->approver(['vendors.view', 'vendors.edit']);

        $this->actingAs($editor)->put("/vendors/{$vendor->id}", [
            'name' => $vendor->name,
            'vendor_type' => 'Supplier',
            'email' => $vendor->email,
            // A pending account being switched on behind the matrix's back.
            'is_active' => true,
        ])->assertRedirect();

        $vendor->refresh();
        $this->assertFalse($vendor->is_active, 'only an approval decision may activate a portal account');
        $this->assertSame(Vendor::STATUS_PENDING, $vendor->status);
        $this->assertSame(0, VendorApproval::count());
    }

    public function test_a_portal_accounts_sign_in_email_cannot_be_changed_here(): void
    {
        $vendor = $this->portalVendor();

        $this->actingAs($this->approver(['vendors.view', 'vendors.edit']))
            ->put("/vendors/{$vendor->id}", [
                'name' => $vendor->name,
                'vendor_type' => 'Supplier',
                'email' => 'someone-else@example.com',
            ])
            ->assertSessionHas('error');

        $this->assertStringEndsWith('@example.com', $vendor->refresh()->email);
        $this->assertStringStartsWith('portal', $vendor->email);
    }

    public function test_the_decision_is_gated_on_the_approve_permission(): void
    {
        $vendor = $this->portalVendor();

        $this->actingAs($this->approver(['vendors.view', 'vendors.edit']))
            ->put("/vendors/{$vendor->id}/approval", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame(Vendor::STATUS_PENDING, $vendor->refresh()->status);
    }

    public function test_a_password_reset_needs_its_own_permission_and_replaces_the_credential(): void
    {
        $vendor = $this->portalVendor(Vendor::STATUS_ACTIVE);
        $oldHash = $vendor->password;

        $this->actingAs($this->approver(['vendors.view', 'vendors.approve']))
            ->put("/vendors/{$vendor->id}/password", [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertForbidden();

        $this->assertSame($oldHash, $vendor->refresh()->password);

        $this->actingAs($this->approver(['vendors.view', 'vendors.reset_password']))
            ->put("/vendors/{$vendor->id}/password", [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('newpassword123', $vendor->refresh()->password));
    }
}
