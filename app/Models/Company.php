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
    /**
     * Companies whose items a ticket for this company may use: itself plus every
     * Entity it is tagged to on /companies (entity_brand). A NONOS store can pick
     * NONOS items and the items of the entities NONOS belongs to (e.g. TGI).
     *
     * @return int[]
     */
    public static function itemSourceIds(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('entity_brand')
            ->where('brand_company_id', $companyId)
            ->pluck('entity_company_id')
            ->map(fn ($id) => (int) $id)
            ->prepend($companyId)
            ->unique()
            ->values()
            ->all();
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'entity_brand', 'brand_company_id', 'entity_company_id')
            ->withTimestamps();
    }
}
