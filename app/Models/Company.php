<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'description',
        'logo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'role_company');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function npcStatuses()
    {
        return $this->hasMany(NpcStatus::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_company');
    }

    /** Brand companies assigned to this entity company. */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'entity_brand', 'entity_company_id', 'brand_company_id')
            ->withTimestamps();
    }

    /** Entity companies to which this brand company is assigned. */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'entity_brand', 'brand_company_id', 'entity_company_id')
            ->withTimestamps();
    }
}
