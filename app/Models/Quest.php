<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    protected $fillable = [
        'title',
        'description',
        'criteria_type',
        'criteria_value',
        'badge_name',
        'bonus_points',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'bonus_points' => 'integer',
        'criteria_value' => 'integer',
    ];

    public function progress()
    {
        return $this->hasMany(AgentQuestProgress::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                     })
                     ->where(function ($q) {
                         $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                     });
    }
}
