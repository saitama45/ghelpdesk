<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A functional area within a QAT cycle. Sibling of {@see UatSection}.
 */
class QatSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'qat_cycle_id',
        'name',
        'description',
        'is_critical',
        'order',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'order' => 'integer',
        'qat_cycle_id' => 'integer',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(QatCase::class)->orderBy('order')->orderBy('id');
    }
}
