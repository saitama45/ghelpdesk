<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A retired ticket_key that still points at its ticket after a renumber.
 * See the create_ticket_key_aliases_table migration for the why.
 */
class TicketKeyAlias extends Model
{
    protected $fillable = [
        'ticket_id',
        'ticket_key',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
