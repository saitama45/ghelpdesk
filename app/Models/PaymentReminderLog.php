<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReminderLog extends Model
{
    protected $table = 'payment_reminder_log';

    protected $fillable = [
        'payable_type',
        'payable_id',
        'reminder_type',
        'window_date',
        'sent_at',
        'recipients',
    ];

    protected $casts = [
        'payable_id' => 'integer',
        'window_date' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'recipients' => 'array',
    ];
}
