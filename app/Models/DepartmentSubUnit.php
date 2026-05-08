<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentSubUnit extends Model
{
    protected $fillable = [
        'department_unit_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'department_unit_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DepartmentUnit::class, 'department_unit_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
