<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A functional area within a cycle — the walkthrough checklist's ITEM, CONTACT,
 * SCHEDULER, BILLING headings, or the test script's screen groupings.
 */
class UatSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'uat_cycle_id',
        'name',
        'description',
        'is_critical',
        'order',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'order' => 'integer',
        'uat_cycle_id' => 'integer',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(UatCase::class)->orderBy('order')->orderBy('id');
    }
}
