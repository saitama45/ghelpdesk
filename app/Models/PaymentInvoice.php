<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PaymentInvoice extends Model
{
    protected $fillable = [
        'vendor_id',
        'apv_no',
        'store_code',
        'po_number',
        'si_number',
        'si_date',
        'due_date',
        'invoice_amount',
        'outstanding_amount',
        'currency',
        'status',
        'remarks',
        'assignee_user_id',
        'cc_emails',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'vendor_id' => 'integer',
        'invoice_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'si_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'assignee_user_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    protected $appends = ['aging_days'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function overpayments()
    {
        return $this->hasMany(PaymentOverpayment::class, 'applied_to_invoice_id');
    }

    public function getAgingDaysAttribute(): int
    {
        if (!$this->due_date || in_array($this->status, ['Paid', 'Cancelled'])) {
            return 0;
        }
        $due = Carbon::parse($this->due_date)->startOfDay();
        $now = Carbon::now()->startOfDay();
        return $now->gt($due) ? $due->diffInDays($now) : 0;
    }

    protected static function booted()
    {
        static::saving(function ($invoice) {
            if (!$invoice->exists && $invoice->invoice_amount && !$invoice->outstanding_amount) {
                $invoice->outstanding_amount = $invoice->invoice_amount;
            }
        });
    }
}
