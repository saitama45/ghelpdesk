<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $appends = [
        'cluster_name',
    ];

    protected $fillable = [
        'code',
        'name',
        'company_id',
        'address',
        'legal_company',
        'company_applied_with',
        'monitoring_status',
        'sector',
        'area',
        'brand',
        'class',
        'email',
        'contact_person',
        'contact_details',
        'mall_contacts',
        'opening_date',
        'hookup',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
        'cctv_seal_notice_path',
        'cctv_seal_notice_name',
        'cctv_seal_notice_mime_type',
        'cctv_seal_notice_size',
        'cctv_seal_notice_uploaded_at',
        'cctv_seal_notice_uploaded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'mall_contacts' => 'array',
        'company_id' => 'integer',
        'sector' => 'integer',
        'opening_date' => 'date:Y-m-d',
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'cctv_seal_notice_size' => 'integer',
        'cctv_seal_notice_uploaded_at' => 'datetime',
        'cctv_seal_notice_uploaded_by' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(Cluster::class)->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function connectivityServices(): HasMany
    {
        return $this->hasMany(PaymentConnectivityService::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function npcStatuses(): BelongsToMany
    {
        return $this->belongsToMany(NpcStatus::class, 'npc_status_store')
            ->withPivot('year')
            ->withTimestamps();
    }

    public function options(): HasMany
    {
        return $this->hasMany(StoreOption::class);
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(StoreBlueprint::class)->latest();
    }

    public function getClusterNameAttribute(): string
    {
        return $this->clusters->pluck('name')->implode(', ');
    }
}
