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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'response_time_hours' => 'integer',
        'resolution_time_hours' => 'integer',
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
