<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasUuids, SoftDeletes;

    // Removed custom timestamps constants as we are now using standard created_at/updated_at

    protected $fillable = [
        'ticket_key',
        'title',
        'description',
        'type',
        'status',
        'priority',
        'severity',
        'reporter_id',
        'assignee_id',
        'project_id',
        'milestone_id',
        'company_id',
    ];

    public function comments()
    {
        return $this->hasMany(TicketComment::class);
    }

    public function histories()
    {
        return $this->hasMany(TicketHistory::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
