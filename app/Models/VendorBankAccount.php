<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank account the vendor wants paid into. Unlike contacts, these are
 * verified before use: payments are released against them, so a vendor adding
 * one leaves it `pending` until someone checks it against the bank
 * certification and approves it.
 */
class VendorBankAccount extends Model
{
    use SoftDeletes;

    protected $table = 'portal_vendor_bank_accounts';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $casts = [
        'is_default' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::STATUS_PENDING;
    }

    /** Last four digits only — enough to recognise the account, not to use it. */
    public function maskedAccountNumber(): string
    {
        $number = preg_replace('/\s+/', '', (string) $this->account_number);

        if ($number === '') {
            return '—';
        }

        return strlen($number) <= 4
            ? str_repeat('•', strlen($number))
            : str_repeat('•', 4) . substr($number, -4);
    }
}
