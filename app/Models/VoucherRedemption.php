<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherRedemption extends Model
{
    protected $fillable = [
        'voucher_id', 'customer_id', 'store_id', 'receipt_number', 'sale_date',
        'gross_sale_total', 'applied_amount', 'forfeited_amount', 'redeemed_at',
        'redeemed_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'sale_date' => 'date:Y-m-d', 'gross_sale_total' => 'decimal:2',
        'applied_amount' => 'decimal:2', 'forfeited_amount' => 'decimal:2',
        'redeemed_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function voucher() { return $this->belongsTo(Voucher::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function store() { return $this->belongsTo(Store::class); }
    public function cashier() { return $this->belongsTo(User::class, 'redeemed_by'); }
    public function voider() { return $this->belongsTo(User::class, 'voided_by'); }
    public function saleClaim() { return $this->hasOne(VoucherSaleClaim::class); }
}
