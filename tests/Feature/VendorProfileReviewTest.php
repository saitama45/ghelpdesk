<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBankAccount;
use App\Models\VendorContact;
use App\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The portal stages a vendor's profile edits instead of applying them
 * (maker-checker). This is the back office half: reading the profile and
 * accepting or refusing what the vendor submitted.
 */
class VendorProfileReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions = ['vendors.view', 'vendors.approve']): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function vendorWithProfile(array $profile = [], array $pending = null): Vendor
    {
        $vendor = Vendor::create([
            'name' => 'Gen Supplier',
            'email' => 'gen@example.com',
            'is_active' => true,
        ]);

        VendorProfile::query()->forceCreate(array_merge([
            'vendor_id' => $vendor->id,
            'legal_name' => 'Gen Supplier Trading Inc.',
            'trade_name' => 'Gen Supplier',
            'tin' => '123-456-789-000',
            'city' => 'Quezon City',
            'country' => 'Philippines',
            'currency' => 'PHP',
            'approval_status' => $pending ? VendorProfile::STATUS_PENDING : VendorProfile::STATUS_DRAFT,
            'pending_changes' => $pending,
        ], $profile));

        return $vendor;
    }

    public function test_it_returns_the_portal_profile_fields_in_the_portals_own_order(): void
    {
        $vendor = $this->vendorWithProfile();

        $payload = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->assertOk()
            ->json('profile');

        $labels = array_column($payload['fields'], 'label');
        $this->assertSame(array_values(VendorProfile::FIELDS), $labels);
        $this->assertSame('Gen Supplier Trading Inc.', $payload['fields'][0]['value']);
        $this->assertFalse($payload['has_pending_changes']);
    }

    public function test_it_lists_only_the_fields_the_vendor_actually_changed(): void
    {
        $vendor = $this->vendorWithProfile([], [
            'legal_name' => 'Gen Supplier Trading Inc.',   // unchanged
            'city' => 'Makati City',                        // changed
            'tin' => '999-888-777-000',                     // changed
        ]);

        $profile = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->json('profile');

        $this->assertTrue($profile['has_pending_changes']);
        $this->assertCount(2, $profile['pending_changes']);

        $changed = collect($profile['pending_changes'])->keyBy('field');
        $this->assertSame('Quezon City', $changed['city']['from']);
        $this->assertSame('Makati City', $changed['city']['to']);
        $this->assertSame('TIN', $changed['tin']['label']);
    }

    public function test_approving_copies_the_staged_values_onto_the_live_profile(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City', 'website' => 'https://gen.example']);
        $approver = $this->staff();

        $this->actingAs($approver)
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertOk();

        $profile = VendorProfile::where('vendor_id', $vendor->id)->first();
        $this->assertSame('Makati City', $profile->city);
        $this->assertSame('https://gen.example', $profile->website);
        $this->assertNull($profile->pending_changes);
        $this->assertSame(VendorProfile::STATUS_APPROVED, $profile->approval_status);
        $this->assertSame($approver->id, $profile->reviewed_by);
        $this->assertNotNull($profile->reviewed_at);
    }

    public function test_rejecting_discards_the_changes_and_keeps_the_live_profile(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City']);

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", [
                'action' => 'rejected',
                'remarks' => 'Your TIN does not match the SEC registration.',
            ])
            ->assertOk();

        $profile = VendorProfile::where('vendor_id', $vendor->id)->first();
        $this->assertSame('Quezon City', $profile->city, 'a refused change must not reach the live profile');
        $this->assertNull($profile->pending_changes);
        $this->assertSame(VendorProfile::STATUS_REJECTED, $profile->approval_status);
        $this->assertSame('Your TIN does not match the SEC registration.', $profile->review_remarks);
    }

    public function test_a_refusal_must_say_why(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City']);

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'rejected'])
            ->assertJsonValidationErrors('remarks');

        $this->assertSame(
            VendorProfile::STATUS_PENDING,
            VendorProfile::where('vendor_id', $vendor->id)->value('approval_status')
        );
    }

    public function test_there_is_nothing_to_review_without_a_submission(): void
    {
        $vendor = $this->vendorWithProfile();

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertStatus(409);
    }

    /**
     * The staged payload comes from the portal, but it is still input: only the
     * columns the profile form owns may be written from it.
     */
    public function test_a_staged_key_outside_the_profile_form_is_ignored(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City', 'approval_status' => 'approved', 'vendor_id' => 999]);

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertOk();

        $profile = VendorProfile::where('vendor_id', $vendor->id)->first();
        $this->assertSame('Makati City', $profile->city);
        $this->assertSame($vendor->id, (int) $profile->vendor_id);
        $this->assertSame(VendorProfile::STATUS_APPROVED, $profile->approval_status);
    }

    public function test_reviewing_a_profile_never_touches_the_vendor_account(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City']);
        $vendor->forceFill(['status' => 'pending', 'is_active' => false])->save();

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertOk();

        $vendor->refresh();
        $this->assertSame('pending', $vendor->status);
        $this->assertFalse($vendor->is_active);
    }

    public function test_reading_needs_vendors_view_and_deciding_needs_vendors_approve(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City']);

        $this->actingAs($this->staff([]))
            ->getJson("/vendors/{$vendor->id}/profile")
            ->assertForbidden();

        $this->actingAs($this->staff(['vendors.view', 'vendors.edit']))
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertSame(
            VendorProfile::STATUS_PENDING,
            VendorProfile::where('vendor_id', $vendor->id)->value('approval_status')
        );
    }

    private function contact(Vendor $vendor, array $attributes = []): VendorContact
    {
        return VendorContact::query()->forceCreate(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'John Doe',
            'position' => 'IT',
            'email' => 'jdoe@test.com',
            'phone' => '09123234345',
            'is_primary' => false,
        ], $attributes));
    }

    private function bankAccount(Vendor $vendor, array $attributes = []): VendorBankAccount
    {
        return VendorBankAccount::query()->forceCreate(array_merge([
            'vendor_id' => $vendor->id,
            'bank_name' => 'BDO',
            'branch' => 'Ortigas',
            'account_name' => 'Gen Doe',
            'account_number' => '1323434',
            'currency' => 'PHP',
            'is_default' => true,
            'approval_status' => VendorBankAccount::STATUS_PENDING,
        ], $attributes));
    }

    public function test_every_contact_the_vendor_added_is_listed_with_the_primary_first(): void
    {
        $vendor = $this->vendorWithProfile();
        $this->contact($vendor, ['name' => 'Zoe Cruz']);
        $this->contact($vendor, ['name' => 'Ann Reyes', 'is_primary' => true]);
        $this->contact($vendor, ['name' => 'John Doe']);

        $contacts = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->assertOk()
            ->json('contacts');

        $this->assertCount(3, $contacts);
        $this->assertSame('Ann Reyes', $contacts[0]['name']);
        $this->assertTrue($contacts[0]['is_primary']);
        $this->assertSame('IT', $contacts[1]['position']);
    }

    /** Contacts are directory information — nothing about them is approved. */
    public function test_contacts_carry_no_approval_state(): void
    {
        $vendor = $this->vendorWithProfile();
        $this->contact($vendor);

        $contact = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->json('contacts.0');

        $this->assertSame(
            ['id', 'name', 'position', 'email', 'phone', 'is_primary'],
            array_keys($contact)
        );
    }

    public function test_a_bank_account_is_verified_and_the_decision_is_stamped(): void
    {
        $vendor = $this->vendorWithProfile();
        $account = $this->bankAccount($vendor);
        $verifier = $this->staff(['vendors.view', 'vendors.verify_bank']);

        $response = $this->actingAs($verifier)
            ->putJson("/vendors/{$vendor->id}/bank-accounts/{$account->id}/review", ['action' => 'approved'])
            ->assertOk();

        $account->refresh();
        $this->assertSame(VendorBankAccount::STATUS_APPROVED, $account->approval_status);
        $this->assertSame($verifier->id, $account->reviewed_by);
        $this->assertNotNull($account->reviewed_at);
        $this->assertSame('Approved', $response->json('bank_account.status'));
    }

    public function test_refusing_a_bank_account_must_say_why(): void
    {
        $vendor = $this->vendorWithProfile();
        $account = $this->bankAccount($vendor);

        $this->actingAs($this->staff(['vendors.view', 'vendors.verify_bank']))
            ->putJson("/vendors/{$vendor->id}/bank-accounts/{$account->id}/review", ['action' => 'rejected'])
            ->assertJsonValidationErrors('remarks');

        $this->assertSame(VendorBankAccount::STATUS_PENDING, $account->refresh()->approval_status);
    }

    public function test_a_bank_account_is_verified_only_once(): void
    {
        $vendor = $this->vendorWithProfile();
        $account = $this->bankAccount($vendor, ['approval_status' => VendorBankAccount::STATUS_APPROVED]);

        $this->actingAs($this->staff(['vendors.view', 'vendors.verify_bank']))
            ->putJson("/vendors/{$vendor->id}/bank-accounts/{$account->id}/review", ['action' => 'rejected', 'remarks' => 'No.'])
            ->assertStatus(409);

        $this->assertSame(VendorBankAccount::STATUS_APPROVED, $account->refresh()->approval_status);
    }

    /**
     * Verifying bank details is deliberately NOT part of approving the account
     * or its profile: payments are released against this decision.
     */
    public function test_verifying_needs_its_own_permission(): void
    {
        $vendor = $this->vendorWithProfile();
        $account = $this->bankAccount($vendor);

        $this->actingAs($this->staff(['vendors.view', 'vendors.approve']))
            ->putJson("/vendors/{$vendor->id}/bank-accounts/{$account->id}/review", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertSame(VendorBankAccount::STATUS_PENDING, $account->refresh()->approval_status);
    }

    public function test_the_account_number_is_masked_unless_you_may_verify_it(): void
    {
        $vendor = $this->vendorWithProfile();
        $this->bankAccount($vendor);

        $hidden = $this->actingAs($this->staff(['vendors.view']))
            ->getJson("/vendors/{$vendor->id}/profile")
            ->json('bank_accounts.0');

        $this->assertNull($hidden['account_number']);
        $this->assertSame('••••3434', $hidden['masked_account_number']);
        $this->assertTrue($hidden['is_pending']);

        $visible = $this->actingAs($this->staff(['vendors.view', 'vendors.verify_bank']))
            ->getJson("/vendors/{$vendor->id}/profile")
            ->json('bank_accounts.0');

        $this->assertSame('1323434', $visible['account_number']);
    }

    public function test_a_bank_account_cannot_be_decided_through_another_vendors_url(): void
    {
        $vendor = $this->vendorWithProfile();
        $account = $this->bankAccount($vendor);
        $other = Vendor::create(['name' => 'Unrelated Vendor', 'is_active' => true]);

        $this->actingAs($this->staff(['vendors.view', 'vendors.verify_bank']))
            ->putJson("/vendors/{$other->id}/bank-accounts/{$account->id}/review", ['action' => 'approved'])
            ->assertNotFound();
    }

    public function test_verifying_a_bank_account_never_touches_the_account_or_profile(): void
    {
        $vendor = $this->vendorWithProfile([], ['city' => 'Makati City']);
        $vendor->forceFill(['status' => 'pending', 'is_active' => false])->save();
        $account = $this->bankAccount($vendor);

        $this->actingAs($this->staff(['vendors.view', 'vendors.verify_bank']))
            ->putJson("/vendors/{$vendor->id}/bank-accounts/{$account->id}/review", ['action' => 'approved'])
            ->assertOk();

        $vendor->refresh();
        $this->assertSame('pending', $vendor->status);
        $this->assertFalse($vendor->is_active);
        $this->assertSame(
            VendorProfile::STATUS_PENDING,
            VendorProfile::where('vendor_id', $vendor->id)->value('approval_status')
        );
    }

    public function test_the_cheque_details_are_carried_as_their_own_group(): void
    {
        $vendor = $this->vendorWithProfile([
            'cheque_payee_name' => 'Gen Supplier Trading Corporation',
            'cheque_delivery_method' => 'bank_deposit',
            'cheque_is_crossed' => true,
            'cheque_remarks' => 'Ask for Ann at the front desk.',
        ]);

        $fields = collect(
            $this->actingAs($this->staff())
                ->getJson("/vendors/{$vendor->id}/profile")
                ->json('profile.fields')
        )->keyBy('field');

        $this->assertSame('Pay to the Order of', $fields['cheque_payee_name']['label']);
        $this->assertSame('Gen Supplier Trading Corporation', $fields['cheque_payee_name']['value']);
        // Read as a person would, not as the column stores it.
        $this->assertSame('Bank Deposit', $fields['cheque_delivery_method']['value']);
        $this->assertSame('Yes', $fields['cheque_is_crossed']['value']);
        $this->assertSame('Ask for Ann at the front desk.', $fields['cheque_remarks']['value']);

        // Grouped so the panel can show them as the portal's own section.
        $this->assertSame('cheque', $fields['cheque_payee_name']['group']);
        $this->assertSame('profile', $fields['legal_name']['group']);
    }

    public function test_cheque_changes_are_reviewed_in_readable_form(): void
    {
        $vendor = $this->vendorWithProfile(
            ['cheque_delivery_method' => 'pickup', 'cheque_is_crossed' => false],
            ['cheque_delivery_method' => 'courier', 'cheque_is_crossed' => true],
        );

        $changes = collect(
            $this->actingAs($this->staff())
                ->getJson("/vendors/{$vendor->id}/profile")
                ->json('profile.pending_changes')
        )->keyBy('field');

        $this->assertSame('Pick-up', $changes['cheque_delivery_method']['from']);
        $this->assertSame('Courier', $changes['cheque_delivery_method']['to']);
        $this->assertSame('No', $changes['cheque_is_crossed']['from']);
        $this->assertSame('Yes', $changes['cheque_is_crossed']['to']);
    }

    /**
     * A checkbox arrives as false/0/"" depending on how it was submitted; none
     * of those is a change from an unticked box.
     */
    public function test_an_unchanged_checkbox_is_not_reported_as_a_change(): void
    {
        $vendor = $this->vendorWithProfile(
            ['cheque_is_crossed' => false],
            ['cheque_is_crossed' => false, 'city' => 'Makati City'],
        );

        $changes = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->json('profile.pending_changes');

        $this->assertCount(1, $changes);
        $this->assertSame('city', $changes[0]['field']);
    }

    public function test_approving_publishes_the_cheque_instructions(): void
    {
        $vendor = $this->vendorWithProfile(
            ['cheque_payee_name' => null, 'cheque_is_crossed' => false],
            [
                'cheque_payee_name' => 'Gen Supplier Trading Corporation',
                'cheque_delivery_method' => 'courier',
                'cheque_is_crossed' => true,
                'cheque_remarks' => 'Courier to the Ortigas office.',
            ],
        );

        $this->actingAs($this->staff())
            ->putJson("/vendors/{$vendor->id}/profile/review", ['action' => 'approved'])
            ->assertOk();

        $profile = VendorProfile::where('vendor_id', $vendor->id)->first();
        $this->assertSame('Gen Supplier Trading Corporation', $profile->cheque_payee_name);
        $this->assertSame('courier', $profile->cheque_delivery_method);
        $this->assertTrue($profile->cheque_is_crossed);
        $this->assertSame('Courier to the Ortigas office.', $profile->cheque_remarks);
    }

    public function test_a_vendor_without_a_portal_profile_returns_null(): void
    {
        $vendor = Vendor::create(['name' => 'Reference Only', 'is_active' => true]);

        $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/profile")
            ->assertOk()
            ->assertJsonPath('profile', null);
    }
}
