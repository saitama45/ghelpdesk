<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRecord extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'vendor_id',
        'amount',
        'paid_amount',
        'paid_on',
        'reference_no',
        'paid_by',
        'status',
        'current_approval_level',
        'approver_data',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'payable_id' => 'integer',
        'vendor_id' => 'integer',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_on' => 'date:Y-m-d',
        'paid_by' => 'integer',
        'current_approval_level' => 'integer',
        'approver_data' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approvals()
    {
        return $this->hasMany(PaymentRecordApproval::class);
    }

    public function tenders()
    {
        return $this->hasMany(PaymentRecordTender::class);
    }

    public function plannedTenders()
    {
        return $this->hasMany(PaymentRecordTender::class)
            ->where('kind', PaymentRecordTender::KIND_PLANNED);
    }

    public function actualTenders()
    {
        return $this->hasMany(PaymentRecordTender::class)
            ->where('kind', PaymentRecordTender::KIND_ACTUAL);
    }

    /**
     * Still-unpaid portion. Postings may be partial, so this drives both the
     * "can I post more?" guard and the balance shown in the approvals table.
     */
    public function remainingBalance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    public function isFullyPaid(): bool
    {
        // Tolerance absorbs the rounding of percentage-based splits.
        return $this->remainingBalance() <= 0.009;
    }

    public function payable()
    {
        return match ($this->payable_type) {
            'renewal' => $this->belongsTo(PaymentRenewal::class, 'payable_id'),
            'invoice' => $this->belongsTo(PaymentInvoice::class, 'payable_id'),
            'weekly' => $this->belongsTo(PaymentWeeklyPlan::class, 'payable_id'),
            default => null,
        };
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
