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
