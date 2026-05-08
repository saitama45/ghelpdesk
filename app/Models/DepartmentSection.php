<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentSection extends Model
{
    protected $fillable = [
        'department_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DepartmentUnit::class)->orderBy('name');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
