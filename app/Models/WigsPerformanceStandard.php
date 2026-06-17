<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WigsPerformanceStandard extends Model
{
    protected $table = 'wigs_performance_standards';

    protected $fillable = [
        'general', 'specific', 'rating_4', 'rating_3', 'rating_2', 'rating_1',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
