<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketComment extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'comment_text',
        'user_id',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class, 'comment_id');
    }
}