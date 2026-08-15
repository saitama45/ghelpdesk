<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A column of the verdict matrix and a row of the acceptance roster at once.
 * Internal departments and external client stakeholders are the same shape —
 * only `kind` and whether a user account backs them differ.
 */
class UatParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uat_cycle_id',
        'kind',
        'label',
        'department_id',
        'company_id',
        'user_id',
        'contact_name',
        'contact_email',
        'role',
        'access_token',
        'token_expires_at',
        'last_accessed_at',
        'is_active',
        'order',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_active' => 'boolean',
        'order' => 'integer',
        'uat_cycle_id' => 'integer',
        'department_id' => 'integer',
        'company_id' => 'integer',
        'user_id' => 'integer',
    ];

    /** The raw token is a credential — never ship it in a page payload by default. */
    protected $hidden = ['access_token'];

    public const KIND_DEPARTMENT = 'department';
    public const KIND_STAKEHOLDER = 'stakeholder';

    public const ROLE_TESTER = 'tester';
    public const ROLE_APPROVER = 'approver';
    public const ROLE_OBSERVER = 'observer';

    public static function kinds(): array
    {
        return [
            self::KIND_DEPARTMENT => 'Department',
            self::KIND_STAKEHOLDER => 'Client / Stakeholder',
        ];
    }

    public static function roles(): array
    {
        return [
            self::ROLE_TESTER => 'Tester',
            self::ROLE_APPROVER => 'Approver (signs off)',
            self::ROLE_OBSERVER => 'Observer (read-only)',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(UatCaseResult::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(UatSignoff::class);
    }

    public function currentSignoff()
    {
        return $this->hasOne(UatSignoff::class)
            ->where('stage', UatSignoff::STAGE_ACCEPTANCE)
            ->where('is_current', true);
    }

    public function isApprover(): bool
    {
        return $this->role === self::ROLE_APPROVER;
    }

    public function canRecordVerdicts(): bool
    {
        return in_array($this->role, [self::ROLE_TESTER, self::ROLE_APPROVER], true);
    }

    public function tokenIsValid(): bool
    {
        if (!$this->access_token || !$this->is_active) {
            return false;
        }

        return !$this->token_expires_at || $this->token_expires_at->isFuture();
    }

    /** Issues (or re-issues) the no-login access token for this participant. */
    public function issueToken(?int $validDays = 60): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'access_token' => $token,
            'token_expires_at' => $validDays ? now()->addDays($validDays) : null,
        ])->save();

        return $token;
    }

    public function revokeToken(): void
    {
        $this->forceFill([
            'access_token' => null,
            'token_expires_at' => null,
        ])->save();
    }

    /** Best display name: the linked user, the contact, then the column label. */
    public function getDisplayNameAttribute(): string
    {
        return $this->user?->name ?: ($this->contact_name ?: $this->label);
    }

    public function getDisplayEmailAttribute(): ?string
    {
        return $this->user?->email ?: $this->contact_email;
    }
}
