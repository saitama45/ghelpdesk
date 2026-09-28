<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which items of one ambient bell reminder a user has acknowledged — see
 * `NotificationController::reminders()` and the migration's docblock.
 */
class NotificationReminderRead extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'items',
        'read_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'items' => 'array',
        'read_at' => 'datetime',
    ];
}
