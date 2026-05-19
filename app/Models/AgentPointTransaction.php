<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentPointTransaction extends Model
{
    protected $fillable = [
        'agent_id',
        'ticket_id',
        'type',
        'points',
        'awarded_at',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
        'points' => 'integer',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('awarded_at', now()->year)
                     ->whereMonth('awarded_at', now()->month);
    }
}
