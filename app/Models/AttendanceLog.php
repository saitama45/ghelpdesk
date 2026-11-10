<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasUuids;

    /** Time In on a scheduled rest day — recorded, then a schedule adjustment is requested. */
    public const ADJUSTMENT_REST_DAY = 'rest_day';

    /** Time In outside the assigned store's geofence — recorded, then a location adjustment is requested. */
    public const ADJUSTMENT_LOCATION_MISMATCH = 'location_mismatch';

    public const ADJUSTMENT_REASONS = [self::ADJUSTMENT_REST_DAY, self::ADJUSTMENT_LOCATION_MISMATCH];

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
        'adjustment_reason',
        'detected_store_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'schedule_id' => 'integer',
        'schedule_store_id' => 'integer',
        'detected_store_id' => 'integer',
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

    public function detectedStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'detected_store_id');
    }

    /**
     * One line for approvers (Schedules list, email): what the clock-in broke and where it was.
     * e.g. "Rest-day clock-in on Nov 10, 2026 09:43 AM · detected at CFE I".
     */
    public function adjustmentSummary(): ?string
    {
        if (! in_array($this->adjustment_reason, self::ADJUSTMENT_REASONS, true)) {
            return null;
        }

        $label = $this->adjustment_reason === self::ADJUSTMENT_REST_DAY
            ? 'Rest-day clock-in'
            : 'Clock-in outside the assigned location';
        $when = $this->log_time?->copy()->timezone('Asia/Manila')->format('M d, Y h:i A');
        $where = $this->detectedStore?->name
            ? 'detected at '.$this->detectedStore->name
            : 'not at a known store';

        return trim($label.($when ? ' on '.$when : '').' · '.$where);
    }
}
