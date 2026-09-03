<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherVerificationAttempt extends Model
{
    protected $fillable = ['voucher_id', 'scanned_code', 'result', 'store_id', 'verified_by', 'verified_at'];
    protected $casts = ['verified_at' => 'datetime'];
}
