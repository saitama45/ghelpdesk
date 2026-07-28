<?php

namespace Tests\Feature;

use App\Models\PaymentInvoice;
use App\Models\PaymentRecord;
use App\Models\PaymentRecordTender;
use App\Models\ReferenceOption;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentModeSplitTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        $permissions = [
            'payments.view', 'payments.create', 'payments.edit', 'payments.submit',
            'payments.approve', 'payments.mark_paid', 'payments.manage_settings',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::findOrCreate('Payments Officer', 'web');
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function seedModes(): void
    {
        // The install migration already seeds these; keep it idempotent.
        foreach (['Credit Card', 'Cheque', 'Bank Transfer', 'Cash on Delivery (COD)'] as $index => $mode) {
            ReferenceOption::firstOrCreate(
                ['type' => 'payment_mode', 'value' => $mode],
                ['label' => $mode, 'sort_order' => $index + 1],
            );
        }
    }

    private function invoice(Vendor $vendor, float $amount = 100000): PaymentInvoice
    {
        return PaymentInvoice::create([
            'vendor_id' => $vendor->id,
            'apv_no' => 'APV-1',
            'si_number' => 'SI-1',
            'due_date' => now()->addDays(10)->toDateString(),
            'invoice_amount' => $amount,
            'outstanding_amount' => $amount,
            'status' => 'Pending',
        ]);
    }

    public function test_planned_split_is_stored_and_must_equal_the_amount(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor);

        // A split that doesn't add up is rejected.
        $this->actingAs($user)
            ->post(route('payments.records.submit'), [
                'payable_type' => 'invoice',
                'payable_id' => $invoice->id,
                'amount' => 100000,
                'planned_tenders' => [
                    ['mode' => 'Cheque', 'amount' => 40000],
                    ['mode' => 'Credit Card', 'amount' => 50000],
                ],
            ])
            ->assertSessionHasErrors('planned_tenders');

        $this->assertSame(0, PaymentRecord::count());

        // A balanced 50/50 split is accepted.
        $this->actingAs($user)
            ->post(route('payments.records.submit'), [
                'payable_type' => 'invoice',
                'payable_id' => $invoice->id,
                'amount' => 100000,
                'planned_tenders' => [
                    ['mode' => 'Cheque', 'amount' => 50000],
                    ['mode' => 'Credit Card', 'amount' => 50000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $record = PaymentRecord::firstOrFail();
        $planned = $record->plannedTenders()->orderBy('id')->get();

        $this->assertCount(2, $planned);
        $this->assertEqualsWithDelta(50.0, (float) $planned[0]->share_percent, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $planned[1]->share_percent, 0.001);
    }

    public function test_unknown_payment_mode_is_rejected(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor);

        $this->actingAs($user)
            ->post(route('payments.records.submit'), [
                'payable_type' => 'invoice',
                'payable_id' => $invoice->id,
                'amount' => 1000,
                'planned_tenders' => [['mode' => 'Crypto', 'amount' => 1000]],
            ])
            ->assertSessionHasErrors('tenders.0.mode');
    }

    public function test_partial_postings_accumulate_until_the_record_is_settled(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor, 100000);

        $record = PaymentRecord::create([
            'payable_type' => 'invoice',
            'payable_id' => $invoice->id,
            'vendor_id' => $vendor->id,
            'amount' => 100000,
            'paid_amount' => 0,
            'status' => 'approved',
            'current_approval_level' => 2,
            'approver_data' => ['levels' => 2, 'approvers' => []],
            'created_by' => $user->id,
        ]);

        // First posting: 50% by cheque.
        $this->actingAs($user)
            ->post(route('payments.records.mark-paid', $record), [
                'paid_on' => now()->toDateString(),
                'reference_no' => 'POST-1',
                'tenders' => [[
                    'mode' => 'Cheque',
                    'amount' => 50000,
                    'details' => ['cheque_no' => '12345', 'bank' => 'BPI'],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $invoice->refresh();

        $this->assertSame('partially_paid', $record->status);
        $this->assertEqualsWithDelta(50000, (float) $record->paid_amount, 0.01);
        $this->assertEqualsWithDelta(50000, (float) $invoice->outstanding_amount, 0.01);
        $this->assertNotSame('Paid', $invoice->status);

        // Second posting: the remaining 50% on a credit card.
        // Note: no record-level reference_no here — a posting may omit it entirely.
        $this->actingAs($user)
            ->post(route('payments.records.mark-paid', $record), [
                'paid_on' => now()->toDateString(),
                'tenders' => [[
                    'mode' => 'Credit Card',
                    'amount' => 50000,
                    'details' => [
                        'card_owner_id' => $user->id,
                        'card_label' => 'BDO Corporate',
                        'card_last4' => '4412',
                        'approval_code' => 'A99',
                    ],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $invoice->refresh();

        $this->assertSame('posted', $record->status);
        $this->assertEqualsWithDelta(100000, (float) $record->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->outstanding_amount, 0.01);
        $this->assertSame('Paid', $invoice->status);

        $actual = $record->actualTenders()->orderBy('id')->get();
        $this->assertCount(2, $actual);
        $this->assertSame('12345', $actual[0]->details['cheque_no']);
        $this->assertSame('A99', $actual[1]->details['approval_code']);

        // Card Owner is stored as a user id and resolves back to the user's name.
        $this->assertSame((string) $user->id, $actual[1]->details['card_owner_id']);
        $this->assertStringContainsString("Card Owner: {$user->name}", $actual[1]->detail_summary);
    }

    public function test_posting_cannot_exceed_the_remaining_balance(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor, 1000);

        $record = PaymentRecord::create([
            'payable_type' => 'invoice',
            'payable_id' => $invoice->id,
            'vendor_id' => $vendor->id,
            'amount' => 1000,
            'paid_amount' => 800,
            'status' => 'partially_paid',
            'current_approval_level' => 2,
            'approver_data' => ['levels' => 2, 'approvers' => []],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('payments.records.mark-paid', $record), [
                'paid_on' => now()->toDateString(),
                'tenders' => [['mode' => 'Cash on Delivery (COD)', 'amount' => 500, 'details' => ['received_by' => 'Rider']]],
            ])
            ->assertSessionHasErrors('tenders');

        $this->assertSame(0, PaymentRecordTender::count());
        $this->assertEqualsWithDelta(800, (float) $record->fresh()->paid_amount, 0.01);
    }

    public function test_mode_specific_required_fields_are_enforced(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor, 5000);

        $record = PaymentRecord::create([
            'payable_type' => 'invoice',
            'payable_id' => $invoice->id,
            'vendor_id' => $vendor->id,
            'amount' => 5000,
            'paid_amount' => 0,
            'status' => 'approved',
            'current_approval_level' => 2,
            'approver_data' => ['levels' => 2, 'approvers' => []],
            'created_by' => $user->id,
        ]);

        // Cheque without a cheque number.
        $this->actingAs($user)
            ->post(route('payments.records.mark-paid', $record), [
                'paid_on' => now()->toDateString(),
                'tenders' => [['mode' => 'Cheque', 'amount' => 5000, 'details' => ['bank' => 'BPI']]],
            ])
            ->assertSessionHasErrors('tenders.0.details.cheque_no');

        $this->assertSame(0, PaymentRecordTender::count());
    }

    public function test_vendor_default_split_must_total_one_hundred_percent(): void
    {
        $this->seedModes();
        $user = $this->financeUser();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);

        $this->actingAs($user)
            ->put(route('payments.vendors.payment-defaults', $vendor), [
                'split' => [
                    ['mode' => 'Cheque', 'share_percent' => 60],
                    ['mode' => 'Credit Card', 'share_percent' => 30],
                ],
            ])
            ->assertSessionHasErrors('split');

        $this->actingAs($user)
            ->put(route('payments.vendors.payment-defaults', $vendor), [
                'split' => [
                    ['mode' => 'Cheque', 'share_percent' => 60],
                    ['mode' => 'Credit Card', 'share_percent' => 40],
                ],
            ])
            ->assertSessionHasNoErrors();

        $vendor->refresh();
        $this->assertSame('Cheque', $vendor->default_payment_mode);
        $this->assertCount(2, $vendor->default_payment_split);
    }

    public function test_users_without_mark_paid_permission_cannot_post(): void
    {
        $this->seedModes();
        Permission::findOrCreate('payments.view', 'web');
        Permission::findOrCreate('payments.mark_paid', 'web');

        $viewerRole = Role::findOrCreate('Payments Viewer', 'web');
        $viewerRole->syncPermissions(['payments.view']);
        $viewer = User::factory()->create();
        $viewer->assignRole($viewerRole);

        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Telco One', 'is_active' => true]);
        $invoice = $this->invoice($vendor, 1000);

        $record = PaymentRecord::create([
            'payable_type' => 'invoice',
            'payable_id' => $invoice->id,
            'vendor_id' => $vendor->id,
            'amount' => 1000,
            'paid_amount' => 0,
            'status' => 'approved',
            'current_approval_level' => 2,
            'approver_data' => ['levels' => 2, 'approvers' => []],
        ]);

        $this->actingAs($viewer)
            ->post(route('payments.records.mark-paid', $record), [
                'paid_on' => now()->toDateString(),
                'tenders' => [['mode' => 'Cheque', 'amount' => 1000, 'details' => ['cheque_no' => '1']]],
            ])
            ->assertForbidden();

        $this->assertSame(0, PaymentRecordTender::count());
        $this->assertSame('approved', $record->fresh()->status);
    }
}
