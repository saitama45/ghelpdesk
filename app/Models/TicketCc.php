<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCc extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'email',
        'name',
        'created_by',
        'undeliverable_at',
        'undeliverable_reason',
    ];

    protected $casts = [
        'undeliverable_at' => 'datetime',
    ];

    /**
     * CCs mail may still be sent to — everything the mail server has not
     * permanently rejected. In-app notifications deliberately ignore this: a dead
     * mailbox is no reason to stop showing someone their ticket in the bell.
     */
    public function scopeDeliverable($query)
    {
        return $query->whereNull('undeliverable_at');
    }

    public function isUndeliverable(): bool
    {
        return $this->undeliverable_at !== null;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
