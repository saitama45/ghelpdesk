<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorBankAccount;
use App\Models\VendorContact;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the vendor keeps on the portal's /vendor/profile page, shown on
 * /vendors: the company profile, the people to contact, and the bank accounts.
 *
 * Two of the three carry a decision, and they are not the same decision:
 *  - PROFILE changes are staged by the portal (maker-checker) and accepted or
 *    refused here under `vendors.approve` — the permission the portal itself
 *    notifies when a vendor submits changes.
 *  - BANK ACCOUNTS are verified under `vendors.verify_bank`, kept separate
 *    because payments are released against them.
 *  - CONTACTS carry no decision at all: they are directory information.
 */
class VendorProfileController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:vendors.view'),
            new Middleware('can:vendors.approve', only: ['review']),
            // Its own right: payments are released against a verified account.
            new Middleware('can:vendors.verify_bank', only: ['reviewBankAccount']),
        ];
    }

    public function show(Request $request, Vendor $vendor)
    {
        $profile = VendorProfile::with('reviewer:id,name,email')
            ->where('vendor_id', $vendor->id)
            ->first();

        $canVerifyBank = $request->user()->can('vendors.verify_bank');

        $contacts = VendorContact::where('vendor_id', $vendor->id)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $bankAccounts = VendorBankAccount::with('reviewer:id,name,email')
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('is_default')
            ->orderBy('bank_name')
            ->get();

        return response()->json([
            'profile' => $profile ? $this->present($profile) : null,
            // Directory information — the vendor maintains these freely.
            'contacts' => $contacts->map(fn (VendorContact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'position' => $contact->position,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'is_primary' => $contact->is_primary,
            ])->all(),
            'bank_accounts' => $bankAccounts
                ->map(fn (VendorBankAccount $account) => $this->presentBankAccount($account, $canVerifyBank))
                ->all(),
            'can_review' => $request->user()->can('vendors.approve'),
            'can_verify_bank' => $canVerifyBank,
        ]);
    }

    /**
     * Verifies or refuses one bank account. Decided once, on a pending account:
     * changing bank details in the portal creates a new row to verify rather
     * than quietly re-pointing an approved one.
     */
    public function reviewBankAccount(Request $request, Vendor $vendor, VendorBankAccount $bankAccount)
    {
        abort_unless((int) $bankAccount->vendor_id === (int) $vendor->id, 404);

        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:1000|required_if:action,rejected',
        ]);

        if (! $bankAccount->isPending()) {
            return response()->json([
                'message' => 'This bank account is not pending verification.',
            ], 409);
        }

        $bankAccount->forceFill([
            'approval_status' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ])->save();

        $this->notifyVendorOfBankDecision($bankAccount, $validated['action'], $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Bank account ' . $validated['action'] . '.',
            'bank_account' => $this->presentBankAccount($bankAccount->fresh('reviewer'), true),
        ]);
    }

    /**
     * The full account number reaches only those who may verify it — everyone
     * else sees the last four digits, as the portal itself shows them.
     */
    private function presentBankAccount(VendorBankAccount $account, bool $canVerifyBank): array
    {
        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'branch' => $account->branch,
            'account_name' => $account->account_name,
            'account_number' => $canVerifyBank ? $account->account_number : null,
            'masked_account_number' => $account->maskedAccountNumber(),
            'currency' => $account->currency,
            'is_default' => $account->is_default,
            'status' => ucfirst($account->approval_status ?? VendorBankAccount::STATUS_PENDING),
            'is_pending' => $account->isPending(),
            'reviewed_by' => $account->reviewer?->name,
            'reviewed_at' => $account->reviewed_at?->format('M j, Y g:i A'),
            'review_remarks' => $account->review_remarks,
        ];
    }

    private function notifyVendorOfBankDecision(VendorBankAccount $account, string $action, ?string $remarks): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            return;
        }

        DB::table('portal_notifications')->insert([
            'notifiable_type' => 'vendor',
            'notifiable_id' => $account->vendor_id,
            'type' => 'bank_account_' . $action,
            'title' => 'Bank account ' . $action,
            'message' => "Your bank account ({$account->bank_name}) was {$action}" . ($remarks ? ': ' . $remarks : '.'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Accepts or refuses the vendor's staged profile changes. Approving copies
     * `pending_changes` onto the live columns, which is what makes them visible
     * to the portal and to everyone here.
     */
    public function review(Request $request, Vendor $vendor)
    {
        $profile = VendorProfile::where('vendor_id', $vendor->id)->firstOrFail();

        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            // A refusal has to say why — the vendor is shown this and has to
            // know what to correct before resubmitting.
            'remarks' => 'nullable|string|max:1000|required_if:action,rejected',
        ]);

        if (! $profile->hasPendingChanges()) {
            return response()->json([
                'message' => 'This vendor has no pending profile changes to review.',
            ], 409);
        }

        $changes = [];

        // Only the fields the portal's form owns can be written from a staged
        // payload — a crafted key must never reach an unrelated column.
        foreach ((array) $profile->pending_changes as $field => $value) {
            if (array_key_exists($field, VendorProfile::FIELDS)) {
                $changes[$field] = $value;
            }
        }

        DB::transaction(function () use ($profile, $validated, $changes, $request) {
            $profile->forceFill(
                ($validated['action'] === VendorProfile::STATUS_APPROVED ? $changes : [])
                + [
                    'pending_changes' => null,
                    'approval_status' => $validated['action'],
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $validated['remarks'] ?? null,
                ]
            )->save();
        });

        $this->notifyVendor($vendor, $validated['action'], $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Profile changes ' . $validated['action'] . '.',
            'profile' => $this->present($profile->fresh('reviewer')),
        ]);
    }

    /** Mirrors the portal's own reviewer notification. In-app only, no mail. */
    private function notifyVendor(Vendor $vendor, string $action, ?string $remarks): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            return;
        }

        DB::table('portal_notifications')->insert([
            'notifiable_type' => 'vendor',
            'notifiable_id' => $vendor->id,
            'type' => 'profile_' . $action,
            'title' => 'Profile changes ' . $action,
            'message' => 'Your profile changes were ' . $action . ($remarks ? ': ' . $remarks : '.'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The live values plus, when the vendor has submitted changes, only the
     * fields that actually differ — an approver should read a decision, not
     * compare two full forms.
     */
    private function present(VendorProfile $profile): array
    {
        $fields = [];

        foreach (VendorProfile::FIELDS as $field => $label) {
            $fields[] = [
                'field' => $field,
                'label' => $label,
                'group' => VendorProfile::groupOf($field),
                // Formatted for reading: "Yes"/"No", "Bank Deposit".
                'value' => $profile->displayValue($field),
            ];
        }

        return [
            'id' => $profile->id,
            'status' => ucfirst($profile->approval_status ?? VendorProfile::STATUS_DRAFT),
            'fields' => $fields,
            'has_pending_changes' => $profile->hasPendingChanges(),
            'pending_changes' => $profile->hasPendingChanges() ? $profile->pendingDiff() : [],
            'reviewed_by' => $profile->reviewer?->name,
            'reviewed_at' => $profile->reviewed_at?->format('M j, Y g:i A'),
            'review_remarks' => $profile->review_remarks,
            'updated_at' => $profile->updated_at?->format('M j, Y g:i A'),
        ];
    }
}
