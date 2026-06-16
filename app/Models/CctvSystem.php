<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CctvSystem extends Model
{
    protected $fillable = [
        'store_id',
        'cctv_type',
        'has_qr_code',
        'setup_completed',
        'dpo_seal_checking',
        'dvr_nvr_count',
        'expected_cameras',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'has_qr_code' => 'boolean',
        'setup_completed' => 'boolean',
        'is_active' => 'boolean',
        'dvr_nvr_count' => 'integer',
        'expected_cameras' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CctvInspection::class)->orderByDesc('inspection_date');
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(CctvInspection::class)->ofMany('inspection_date', 'max');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
