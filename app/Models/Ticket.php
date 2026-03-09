<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasUuids, SoftDeletes;

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
        'store_id',
        'category_id',
        'sub_category_id',
        'item_id',
        'parent_id',
        'sender_email',
        'sender_name',
        'message_id',
    ];

    public function parent()
    {
        return $this->belongsTo(Ticket::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Ticket::class, 'parent_id');
    }

    public function schedule()
    {
        return $this->hasOne(Schedule::class);
    }

    public function slaMetric()
    {
        return $this->hasOne(TicketSlaMetric::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

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

    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

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

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
