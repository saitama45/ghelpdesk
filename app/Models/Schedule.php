<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'start_time',
        'end_time',
        'pickup_start',
        'pickup_end',
        'backlogs_start',
        'backlogs_end',
        'remarks',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scheduleStores()
    {
        return $this->hasMany(ScheduleStore::class)->orderBy('start_time');
    }

    /**
     * Get the primary store for this schedule (shortcut for legacy single-store logic).
     */
    public function store()
    {
        return $this->hasOneThrough(
            Store::class,
            ScheduleStore::class,
            'schedule_id', // Foreign key on schedule_stores table
            'id',          // Foreign key on stores table
            'id',          // Local key on schedules table
            'store_id'     // Local key on schedule_stores table
        );
    }
}
