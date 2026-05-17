<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOverpayment extends Model
{
    protected $fillable = [
        'vendor_id',
        'collection_date',
        'check_details',
        'amount',
        'remarks',
        'applied_to_invoice_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'vendor_id' => 'integer',
        'amount' => 'decimal:2',
        'collection_date' => 'date:Y-m-d',
        'applied_to_invoice_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice()
    {
        return $this->belongsTo(PaymentInvoice::class, 'applied_to_invoice_id');
    }
}
