<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'date',
        'is_recurring',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_recurring' => 'boolean',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public const TYPE_REGULAR = 'regular';
    public const TYPE_SPECIAL_NON_WORKING = 'special_non_working';
    public const TYPE_SPECIAL_WORKING = 'special_working';
    public const TYPE_CUSTOM = 'custom';

    /**
     * A special *working* day is a holiday in name only — offices stay open, so
     * it never comes out of a project's working-day count.
     */
    public const NON_WORKING_TYPES = [
        self::TYPE_REGULAR,
        self::TYPE_SPECIAL_NON_WORKING,
        self::TYPE_CUSTOM,
    ];

    public static function types(): array
    {
        return [
            self::TYPE_REGULAR => 'Regular Holiday',
            self::TYPE_SPECIAL_NON_WORKING => 'Special (Non-Working)',
            self::TYPE_SPECIAL_WORKING => 'Special (Working)',
            self::TYPE_CUSTOM => 'Custom / Announced',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Only the holidays that actually take a day out of the calendar. */
    public function scopeNonWorking($query)
    {
        return $query->whereIn('type', self::NON_WORKING_TYPES);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }
}
