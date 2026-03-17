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
        'notify_on_urgent_ticket',
    ];

    protected $casts = [
        'is_assignable' => 'boolean',
        'notify_on_ticket_create' => 'boolean',
        'notify_on_ticket_assign' => 'boolean',
        'notify_on_urgent_ticket' => 'boolean',
    ];

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'role_company');
    }
}