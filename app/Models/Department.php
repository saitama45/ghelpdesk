<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(DepartmentNode::class)->whereNull('parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function allNodes(): HasMany
    {
        return $this->hasMany(DepartmentNode::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
