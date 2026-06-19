<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MallHookup extends Model
{
    protected $fillable = [
        'store_id',
        'developer',
        'area',
        'deployment_date',
        'deployment_status',
        'hookup_status',
        'shouldered_facility',
        'with_ups',
        'cost_2024',
        'cost_2025',
        'cost_2026',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'deployment_date' => 'date:Y-m-d',
        'with_ups' => 'boolean',
        'cost_2024' => 'decimal:2',
        'cost_2025' => 'decimal:2',
        'cost_2026' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MallHookupLog::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(MallHookupCost::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
