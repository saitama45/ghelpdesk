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
        'sector',
        'area',
        'brand',
        'class',
        'email',
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
        'sector' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'cctv_seal_notice_size' => 'integer',
        'cctv_seal_notice_uploaded_at' => 'datetime',
        'cctv_seal_notice_uploaded_by' => 'integer',
    ];

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

    public function getClusterNameAttribute(): string
    {
        return $this->clusters->pluck('name')->implode(', ');
    }
}
