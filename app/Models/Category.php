<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
        'response_time_hours',
        'resolution_time_hours',
        'business_start_time',
        'business_end_time',
        'working_days',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_time_hours' => 'integer',
        'resolution_time_hours' => 'integer',
        'working_days' => 'array',
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
