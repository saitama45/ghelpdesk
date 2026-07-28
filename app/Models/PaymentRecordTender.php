<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One tender line of a payment record — "50% Cheque / 50% Credit Card" is two rows.
 *
 * kind = planned : the split proposed by the requester at submission
 * kind = actual  : money that actually moved, one row per mode per posting
 */
class PaymentRecordTender extends Model
{
    public const KIND_PLANNED = 'planned';
    public const KIND_ACTUAL = 'actual';

    /**
     * Mode-specific detail fields. Payment modes themselves are user-managed
     * reference_options, so a mode is matched to a field group by keyword
     * (see fieldGroupFor) rather than by a fixed enum.
     */
    public const FIELD_GROUPS = [
        'cheque' => [
            'label' => 'Cheque details',
            'fields' => [
                ['key' => 'cheque_no', 'label' => 'Cheque No.', 'type' => 'text', 'required' => true],
                ['key' => 'bank', 'label' => 'Bank', 'type' => 'text', 'required' => false],
                ['key' => 'cheque_date', 'label' => 'Cheque Date', 'type' => 'date', 'required' => false],
            ],
        ],
        'card' => [
            'label' => 'Card details',
            'fields' => [
                // type = user → picked from the users list, stored as the user id
                ['key' => 'card_owner_id', 'label' => 'Card Owner', 'type' => 'user', 'required' => false],
                ['key' => 'card_label', 'label' => 'Card / Bank', 'type' => 'text', 'required' => false],
                ['key' => 'card_last4', 'label' => 'Last 4 Digits', 'type' => 'text', 'required' => false, 'maxlength' => 4],
                ['key' => 'approval_code', 'label' => 'Approval Code', 'type' => 'text', 'required' => true],
            ],
        ],
        'bank' => [
            'label' => 'Transfer details',
            'fields' => [
                ['key' => 'bank', 'label' => 'Bank', 'type' => 'text', 'required' => false],
                ['key' => 'account_no', 'label' => 'Account No.', 'type' => 'text', 'required' => false],
                ['key' => 'transaction_ref', 'label' => 'Transaction Ref.', 'type' => 'text', 'required' => true],
            ],
        ],
        'cash' => [
            'label' => 'Cash / COD details',
            'fields' => [
                ['key' => 'received_by', 'label' => 'Received By', 'type' => 'text', 'required' => true],
                ['key' => 'or_no', 'label' => 'OR / Receipt No.', 'type' => 'text', 'required' => false],
            ],
        ],
        'other' => [
            'label' => 'Details',
            'fields' => [],
        ],
    ];

    protected $fillable = [
        'payment_record_id',
        'kind',
        'mode',
        'amount',
        'share_percent',
        'paid_on',
        'reference_no',
        'details',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'payment_record_id' => 'integer',
        'amount' => 'decimal:2',
        'share_percent' => 'decimal:4',
        'paid_on' => 'date:Y-m-d',
        'details' => 'array',
        'created_by' => 'integer',
    ];

    public function record()
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }

    /**
     * Which detail-field group a (user-editable) mode label belongs to.
     */
    public static function fieldGroupFor(?string $mode): string
    {
        $needle = strtolower(trim((string) $mode));

        if ($needle === '') {
            return 'other';
        }
        if (str_contains($needle, 'cheque') || str_contains($needle, 'check')) {
            return 'cheque';
        }
        if (str_contains($needle, 'card')) {
            return 'card';
        }
        if (str_contains($needle, 'transfer') || str_contains($needle, 'bank')
            || str_contains($needle, 'online') || str_contains($needle, 'wire')
            || str_contains($needle, 'gcash') || str_contains($needle, 'maya')) {
            return 'bank';
        }
        if (str_contains($needle, 'cod') || str_contains($needle, 'cash')) {
            return 'cash';
        }

        return 'other';
    }

    public static function fieldsFor(?string $mode): array
    {
        return self::FIELD_GROUPS[self::fieldGroupFor($mode)]['fields'];
    }

    /**
     * Drop anything the mode doesn't define, and trim what's left.
     */
    public static function sanitizeDetails(?string $mode, $details): ?array
    {
        if (! is_array($details)) {
            return null;
        }

        $allowed = array_column(self::fieldsFor($mode), 'key');
        $clean = [];

        foreach ($allowed as $key) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean ?: null;
    }

    /**
     * "Cheque #12345 · BPI" — a one-line summary for tables and tooltips.
     * User-typed fields hold an id, so resolve them to a name.
     */
    public function getDetailSummaryAttribute(): string
    {
        $parts = [];
        foreach (self::fieldsFor($this->mode) as $field) {
            $value = $this->details[$field['key']] ?? null;
            if (! $value) {
                continue;
            }
            if (($field['type'] ?? 'text') === 'user') {
                $value = User::whereKey($value)->value('name') ?: $value;
            }
            $parts[] = $field['label'].': '.$value;
        }

        return implode(' · ', $parts);
    }
}
