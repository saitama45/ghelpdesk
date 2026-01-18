<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $casts = [
        'is_assignable' => 'boolean',
    ];

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'role_company');
    }
}