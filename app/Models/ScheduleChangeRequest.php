<?php

namespace App\Models;

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

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
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
}
