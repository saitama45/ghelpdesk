<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'schedule_id',
        'type',
        'latitude',
        'longitude',
        'photo_path',
        'log_time',
        'device_info',
        'ip_address',
    ];

    protected $casts = [
        'log_time' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Schedule::class);
    }
}
