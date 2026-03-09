<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'code',
        'name',
        'sector',
        'area',
        'brand',
        'cluster',
        'email',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sector' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function tickets()
    {
        return $this->hasManyThrough(Ticket::class, Schedule::class, 'store_id', 'id', 'id', 'ticket_id');
    }
}
