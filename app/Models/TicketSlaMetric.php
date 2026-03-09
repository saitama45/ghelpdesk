<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketSlaMetric extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'response_target_at',
        'resolution_target_at',
        'first_response_at',
        'resolved_at',
        'is_response_breached',
        'is_resolution_breached',
        'paused_at',
        'total_paused_seconds',
    ];

    protected $casts = [
        'response_target_at' => 'datetime',
        'resolution_target_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_response_breached' => 'boolean',
        'is_resolution_breached' => 'boolean',
        'paused_at' => 'datetime',
        'total_paused_seconds' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
