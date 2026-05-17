<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRecordApproval extends Model
{
    protected $fillable = [
        'payment_record_id',
        'user_id',
        'level',
        'action',
        'remarks',
    ];

    protected $casts = [
        'payment_record_id' => 'integer',
        'user_id' => 'integer',
        'level' => 'integer',
    ];

    public function paymentRecord()
    {
        return $this->belongsTo(PaymentRecord::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
