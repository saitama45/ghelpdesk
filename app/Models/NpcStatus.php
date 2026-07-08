<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NpcStatus extends Model
{
    public const STATUSES = [
        'No Record',
        'Active',
        'Renewal Window',
        'Critical Renewal',
        'Due Today',
        'Overdue',
    ];

    public const WORKFLOW_STEPS = [
        ['key' => 'account_registration', 'label' => 'Account Registration', 'sort_order' => 1],
        ['key' => 'dpo_profile', 'label' => 'DPO Profile Information', 'sort_order' => 2],
        ['key' => 'dpo_registration', 'label' => 'DPO Registration', 'sort_order' => 3],
        ['key' => 'npc_approval', 'label' => 'NPC Approval', 'sort_order' => 4],
        ['key' => 'store_receiving', 'label' => 'Store/Office Receiving', 'sort_order' => 5],
        ['key' => 'store_downloads', 'label' => 'Store/Office Downloads & Confirmation', 'sort_order' => 6],
    ];

    protected $fillable = [
        'company_id',
        'year',
        'entry_type',
        'validity_from',
        'validity_to',
        'status',
        'approval_status',
        'register_email',
        'register_password',
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
        // Reversible encryption (not a hash) so the Step 1 password can be
        // revealed to users holding npc_status.reveal_password.
        'register_password' => 'encrypted',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(NpcStatusAttachment::class);
    }

    public function dpoProfile(): HasOne
    {
        return $this->hasOne(NpcDpoProfile::class);
    }

    public function backupCodes(): HasMany
    {
        return $this->hasMany(NpcBackupCode::class)->orderBy('sort_order');
    }

    public function registration(): HasOne
    {
        return $this->hasOne(NpcRegistration::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(NpcDocument::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(NpcPayment::class);
    }

    public function workflowSteps(): HasMany
    {
        return $this->hasMany(NpcStatusWorkflowStep::class)->orderBy('sort_order');
    }

    public function sealReceipts(): HasMany
    {
        return $this->hasMany(NpcSealReceipt::class);
    }

    public function storeProofs(): HasMany
    {
        return $this->hasMany(NpcStoreProof::class);
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
