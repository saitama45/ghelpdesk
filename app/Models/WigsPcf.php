<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WigsPcf extends Model
{
    protected $table = 'wigs_pcf';

    protected $fillable = [
        'user_id', 'year', 'level_1', 'level_2', 'level_3',
        'status', 'confirmed_by', 'confirmed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'year' => 'integer',
        'confirmed_by' => 'integer',
        'confirmed_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WigsPcfItem::class, 'pcf_id')->orderBy('sort_order')->orderBy('id');
    }
}
