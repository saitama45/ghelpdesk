<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'landing_page',
        'is_assignable',
        'notify_on_ticket_create',
        'notify_on_ticket_assign',
    ];

    protected $casts = [
        'is_assignable' => 'boolean',
        'notify_on_ticket_create' => 'boolean',
        'notify_on_ticket_assign' => 'boolean',
    ];

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'role_company');
    }
}