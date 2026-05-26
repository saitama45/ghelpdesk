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
        'client_request_id',
        'schedule_id',
        'schedule_store_id',
        'type',
        'latitude',
        'longitude',
        'location_accuracy',
        'location_captured_at',
        'location_received_at',
        'location_client',
        'location_provider',
        'photo_path',
        'log_time',
        'voided_at',
        'voided_by',
        'void_reason',
        'device_info',
        'ip_address',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'schedule_id' => 'integer',
        'schedule_store_id' => 'integer',
        'location_accuracy' => 'float',
        'location_captured_at' => 'datetime',
        'location_received_at' => 'datetime',
        'log_time' => 'datetime',
        'voided_at' => 'datetime',
        'voided_by' => 'integer',
    ];

    public function scopeNotVoided($query)
    {
        return $query->whereNull('voided_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Schedule::class);
    }

    public function scheduleStore(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ScheduleStore::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
