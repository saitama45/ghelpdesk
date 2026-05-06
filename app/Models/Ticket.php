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
        'vendor_id',
        'parent_id',
        'sender_email',
        'sender_name',
        'department',
        'message_id',
        'email_body_hash',
        'survey_token',
        'is_deleted',
    ];

    public function parent()
    {
        return $this->belongsTo(Ticket::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Ticket::class, 'parent_id');
    }

    public function scheduleStore()
    {
        return $this->hasOne(ScheduleStore::class);
    }

    public function schedule()
    {
        return $this->hasOneThrough(
            Schedule::class,
            ScheduleStore::class,
            'ticket_id',   // FK on schedule_stores → tickets
            'id',          // PK on schedules
            'id',          // PK on tickets
            'schedule_id'  // FK on schedule_stores → schedules
        );
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
        'reporter_id' => 'integer',
        'assignee_id' => 'integer',
        'company_id' => 'integer',
        'store_id' => 'integer',
        'category_id' => 'integer',
        'sub_category_id' => 'integer',
        'item_id' => 'integer',
        'vendor_id' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'is_deleted' => 'boolean',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
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

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function vendor()
    {
        return $this->belongsTo(\App\Models\Vendor::class);
    }
}
