<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentVendor extends Model
{
    protected $fillable = [
        'vendor_id',
        'default_payment_terms',
        'default_currency',
        'billing_email',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
