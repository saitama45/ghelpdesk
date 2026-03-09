<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketSurvey extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'rating',
        'feedback',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
