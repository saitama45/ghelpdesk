<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column of the QAT verdict matrix. Sibling of {@see UatParticipant}, minus the
 * tokenised access: QAT is internal, so every participant is a staff member with
 * an account and there is no no-login portal to issue credentials for.
 *
 * A reviewer outranks a tester within the same column, mirroring UAT's approver
 * precedence. That is a TESTING role only — the manager sign-off that gates
 * promotion to UAT is not a participant role at all, it lives on the cycle.
 */
class QatParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'qat_cycle_id',
        'kind',
        'label',
        'department_id',
        'company_id',
        'user_id',
        'contact_name',
        'contact_email',
        'role',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'qat_cycle_id' => 'integer',
        'department_id' => 'integer',
        'company_id' => 'integer',
        'user_id' => 'integer',
    ];

    public const KIND_DEPARTMENT = 'department';

    public const KIND_USER = 'user';

    public const ROLE_TESTER = 'tester';

    public const ROLE_REVIEWER = 'reviewer';

    public const ROLE_OBSERVER = 'observer';

    public static function kinds(): array
    {
        return [
            self::KIND_DEPARTMENT => 'Department',
            self::KIND_USER => 'Individual',
        ];
    }

    public static function roles(): array
    {
        return [
            self::ROLE_TESTER => 'Tester',
            self::ROLE_REVIEWER => 'Reviewer (answer wins)',
            self::ROLE_OBSERVER => 'Observer (read-only)',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
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
        return $this->hasMany(QatCaseResult::class);
    }

    public function isReviewer(): bool
    {
        return $this->role === self::ROLE_REVIEWER;
    }

    public function canRecordVerdicts(): bool
    {
        return in_array($this->role, [self::ROLE_TESTER, self::ROLE_REVIEWER], true);
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
