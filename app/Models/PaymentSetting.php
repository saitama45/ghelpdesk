<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'cc_role_id',
        'global_bcc',
        'default_currency',
        'approval_levels',
        'approver_user_ids',
        'reminders_enabled',
        'updated_by',
    ];

    protected $casts = [
        'cc_role_id' => 'integer',
        'approval_levels' => 'integer',
        'approver_user_ids' => 'array',
        'reminders_enabled' => 'boolean',
        'updated_by' => 'integer',
    ];

    public static function current(): self
    {
        return self::firstOrCreate([], [
            'default_currency' => 'PHP',
            'approval_levels' => 2,
            'reminders_enabled' => true,
        ]);
    }
}
