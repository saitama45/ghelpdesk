<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketView extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'viewed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'viewed_at' => 'datetime',
    ];

    /**
     * Serialize timestamps in Manila local time, matching the other ticket models.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
