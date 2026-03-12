<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_class',
        'name',
        'category',
        'default_duration_days',
        'order',
    ];

    protected $casts = [
        'default_duration_days' => 'integer',
        'order' => 'integer',
    ];
}
