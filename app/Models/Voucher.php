<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = ['voucher_batch_id', 'code', 'status', 'voided_at', 'voided_by', 'void_reason'];
    protected $casts = ['voided_at' => 'datetime'];

    public function batch() { return $this->belongsTo(VoucherBatch::class, 'voucher_batch_id'); }
    public function redemptions() { return $this->hasMany(VoucherRedemption::class); }
    public function activeRedemption() { return $this->hasOne(VoucherRedemption::class)->whereNull('voided_at')->latestOfMany(); }
}
