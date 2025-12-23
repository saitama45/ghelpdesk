<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    use HasUuids;

    const CREATED_AT = 'uploaded_date';
    const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'comment_id',
        'file_name',
        'file_storage_path',
        'file_size_bytes',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function comment()
    {
        return $this->belongsTo(TicketComment::class);
    }
}