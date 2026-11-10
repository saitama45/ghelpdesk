<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleChangeRequest extends Model
{
    protected $fillable = [
        'schedule_id',
        'requester_id',
        'request_type',
        'assigned_approver_ids',
        'status',
        'original_payload',
        'requested_payload',
        'requester_remarks',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'approver_remarks',
        'attendance_log_id',
    ];

    protected $casts = [
        'schedule_id' => 'integer',
        'requester_id' => 'integer',
        'assigned_approver_ids' => 'array',
        'original_payload' => 'array',
        'requested_payload' => 'array',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'rejected_by' => 'integer',
        'rejected_at' => 'datetime',
    ];

    /**
     * Always the request's own schedule, whatever entity the viewer has active. Approving,
     * emailing and listing a request are id lookups, not entity listings: with the scope on,
     * a leader whose active entity differs from the schedule's got null here and approval 500'd.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class)->withoutGlobalScope(ActiveEntityScope::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** The DTR clock-in (rest day / location mismatch) this request was raised from, if any. */
    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'attendance_log_id');
    }
}
