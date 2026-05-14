<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NpcStatus extends Model
{
    public const STATUSES = [
        'Active',
        'Inactive',
        'Approved',
        'Pending',
        'For Payment',
    ];

    protected $fillable = [
        'company_id',
        'year',
        'validity_from',
        'validity_to',
        'status',
        'dpo_seal_path',
        'dpo_seal_name',
        'dpo_seal_mime_type',
        'dpo_seal_size',
        'dpo_registration_path',
        'dpo_registration_name',
        'dpo_registration_mime_type',
        'dpo_registration_size',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'validity_from' => 'date:Y-m-d',
        'validity_to' => 'date:Y-m-d',
        'dpo_seal_size' => 'integer',
        'dpo_registration_size' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'npc_status_store')
            ->withPivot('year')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
