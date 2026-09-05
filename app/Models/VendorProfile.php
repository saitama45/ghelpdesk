<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The company profile a vendor maintains in the portal (/vendor/profile):
 * legal identity, tax details, address and payment defaults.
 *
 * Maker-checker by design — a vendor's edits are staged in `pending_changes`
 * with `approval_status = pending` and only become the live columns when an
 * approver accepts them. The back office reads this table and records that
 * decision; the portal owns everything else about it.
 */
class VendorProfile extends Model
{
    protected $table = 'portal_vendor_profiles';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * The live columns a vendor may change, in the order the portal's form
     * shows them, with the labels it uses.
     */
    public const FIELDS = [
        'legal_name' => 'Legal Name',
        'trade_name' => 'Trade Name',
        'tin' => 'TIN',
        'rdo_code' => 'RDO Code',
        'business_type' => 'Business Type',
        'vat_type' => 'VAT Type',
        'address' => 'Address',
        'city' => 'City',
        'province' => 'Province',
        'zip_code' => 'ZIP Code',
        'country' => 'Country',
        'website' => 'Website',
        'payment_terms' => 'Default Payment Terms',
        'currency' => 'Currency',
        // Cheque details — how a cheque payment is made out and released.
        'cheque_payee_name' => 'Pay to the Order of',
        'cheque_delivery_method' => 'Cheque Release',
        'cheque_is_crossed' => 'Crossed Cheque',
        'cheque_remarks' => 'Cheque Remarks',
    ];

    /** The portal's fixed cheque-release choices, as it labels them. */
    public const CHEQUE_DELIVERY_METHODS = [
        'pickup' => 'Pick-up',
        'courier' => 'Courier',
        'bank_deposit' => 'Bank Deposit',
    ];

    /** Fields that are yes/no rather than free text. */
    private const BOOLEAN_FIELDS = ['cheque_is_crossed'];

    /**
     * The portal shows cheque instructions as their own section, and they read
     * as one thing — who the cheque is made out to and how it is released — so
     * they stay grouped here too.
     */
    public static function groupOf(string $field): string
    {
        return str_starts_with($field, 'cheque_') ? 'cheque' : 'profile';
    }

    protected $casts = [
        'pending_changes' => 'array',
        'reviewed_at' => 'datetime',
        'cheque_is_crossed' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hasPendingChanges(): bool
    {
        return $this->approval_status === self::STATUS_PENDING && ! empty($this->pending_changes);
    }

    /**
     * Only the fields the vendor actually changed, as label => [from, to].
     * A staged value equal to the live one is not a change worth reviewing.
     */
    public function pendingDiff(): array
    {
        $diff = [];

        foreach ((array) $this->pending_changes as $field => $value) {
            if (! array_key_exists($field, self::FIELDS)) {
                continue;
            }

            $current = $this->{$field};

            if ($this->comparable($field, $current) === $this->comparable($field, $value)) {
                continue;
            }

            $diff[] = [
                'field' => $field,
                'label' => self::FIELDS[$field],
                'from' => $this->displayValue($field, $current),
                'to' => $this->displayValue($field, $value),
            ];
        }

        return $diff;
    }

    /**
     * A stable form for equality. A checkbox arrives as true/false/1/0/"1"
     * depending on how it was submitted, and "" and "0" must not read as a
     * change from each other.
     */
    private function comparable(string $field, $value): string
    {
        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * What an approver should read: "Yes" rather than 1, "Bank Deposit" rather
     * than bank_deposit.
     */
    public function displayValue(string $field, $value = null): ?string
    {
        $value = func_num_args() > 1 ? $value : $this->{$field};

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            // Never blank: "No" is a real answer for a crossed cheque.
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        if ($field === 'cheque_delivery_method' && $value) {
            return self::CHEQUE_DELIVERY_METHODS[$value] ?? Str::headline((string) $value);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
