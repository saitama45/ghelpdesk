<?php

namespace App\Models;

use App\Support\TicketStatuses;
use Illuminate\Database\Eloquent\Model;

/** One entry of the ticket status catalogue. See App\Support\TicketStatuses. */
class TicketStatus extends Model
{
    protected $fillable = ['key', 'label', 'color', 'behaves_like', 'is_system', 'sort_order', 'created_by', 'updated_by'];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => TicketStatuses::flush());
    }
}
