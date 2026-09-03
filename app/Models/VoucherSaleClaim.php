<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherSaleClaim extends Model
{
    protected $primaryKey = 'sale_key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['sale_key', 'voucher_redemption_id'];
    public function redemption() { return $this->belongsTo(VoucherRedemption::class, 'voucher_redemption_id'); }
}
