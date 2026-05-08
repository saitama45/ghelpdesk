<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentUnit extends Model
{
    protected $fillable = [
        'department_section_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'department_section_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(DepartmentSection::class, 'department_section_id');
    }

    public function subUnits(): HasMany
    {
        return $this->hasMany(DepartmentSubUnit::class)->orderBy('name');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
