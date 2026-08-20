<?php

namespace App\Models;

use App\Support\DepartmentContext;
use App\Support\TestCycleAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * An internal quality-assurance test cycle — the pass that runs before the
 * client-facing UAT.
 *
 * Sibling of {@see UatCycle}. The shape is deliberately the same so cases and the
 * workbook layout carry across, but the lifecycle differs: a QAT cycle is
 * submitted for a sign-off by the submitter's immediate manager, and only a
 * signed-off cycle may be promoted into a UAT cycle.
 */
class QatCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'system_name',
        'description',
        'cycle_no',
        'environment',
        'links',
        'company_id',
        'department_id',
        'qa_lead_id',
        'dev_lead_id',
        'status',
        'start_date',
        'target_signoff_date',
        'go_live_date',
        'gate_on_critical_only',
        'approver_user_ids',
        'submitted_by',
        'submitted_at',
        'uat_cycle_id',
        'promoted_uat_cycle_id',
        'promoted_by',
        'promoted_at',
        'created_by',
        'updated_by',
    ];

    // Every foreign key is cast explicitly: the sqlsrv driver hands FKs back as
    // strings while the identity column comes back as an int, so a bare
    // `$case->qat_cycle_id === $cycle->id` compares '1' to 1 and is false.
    protected $casts = [
        'links' => 'array',
        'approver_user_ids' => 'array',
        'cycle_no' => 'integer',
        'start_date' => 'date:Y-m-d',
        'target_signoff_date' => 'date:Y-m-d',
        'go_live_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'promoted_at' => 'datetime',
        'gate_on_critical_only' => 'boolean',
        'company_id' => 'integer',
        'department_id' => 'integer',
        'qa_lead_id' => 'integer',
        'dev_lead_id' => 'integer',
        'submitted_by' => 'integer',
        'uat_cycle_id' => 'integer',
        'promoted_uat_cycle_id' => 'integer',
        'promoted_by' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_TESTING = 'testing';

    public const STATUS_FOR_APPROVAL = 'for_approval';

    public const STATUS_SIGNED_OFF = 'signed_off';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_TESTING => 'Testing',
            self::STATUS_FOR_APPROVAL => 'For Manager Sign-off',
            self::STATUS_SIGNED_OFF => 'Signed Off',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Statuses that still accept verdicts from testers.
     *
     * A cycle awaiting the manager's decision is deliberately frozen: the manager
     * must be deciding on the same evidence they were shown. A returned cycle
     * reopens so the team can act on the remarks.
     */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_TESTING,
        self::STATUS_RETURNED,
    ];

    public static function environments(): array
    {
        return ['Web', 'Mobile', 'Web & Mobile', 'Desktop', 'API', 'Staging', 'Production'];
    }

    /**
     * Sequential per-year code, e.g. QAT-2026-0007. Collisions are possible under
     * concurrency, so the caller retries on the unique-index violation.
     */
    public static function nextCode(): string
    {
        $year = date('Y');
        $last = static::where('code', 'like', "QAT-{$year}-%")
            ->orderByDesc('id')
            ->value('code');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('QAT-%s-%04d', $year, $next);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function qaLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qa_lead_id');
    }

    public function devLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dev_lead_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The UAT cycle this one is associated with, however that link was made. */
    public function uatCycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    /** The UAT cycle this one produced, which is what makes promotion idempotent. */
    public function promotedUatCycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'promoted_uat_cycle_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(QatSection::class)->orderBy('order')->orderBy('id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(QatCase::class)->orderBy('order')->orderBy('id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(QatParticipant::class)->orderBy('order')->orderBy('id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QatCaseResult::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QatFinding::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(QatEvidence::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(QatSignoff::class);
    }

    /** The manager decision currently in force, if one has been recorded. */
    public function managerSignoff(): HasOne
    {
        return $this->hasOne(QatSignoff::class)
            ->whereNull('qat_participant_id')
            ->where('stage', QatSignoff::STAGE_MANAGER)
            ->where('is_current', true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_FOR_APPROVAL;
    }

    public function isSignedOff(): bool
    {
        return $this->status === self::STATUS_SIGNED_OFF;
    }

    /**
     * Whether a given user may decide this cycle.
     *
     * Membership of the snapshot taken at submit is the authority — not a live
     * re-resolution of the org chart, which could silently move the decision to
     * somebody else while the cycle sits waiting. Admins retain an override so a
     * cycle whose only approver has left cannot become permanently stuck.
     */
    public function isAssignedApprover(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole(['Admin', 'Solutions Admin'])) {
            return true;
        }

        return in_array((int) $user->id, array_map('intval', $this->approver_user_ids ?? []), true);
    }

    /**
     * WHO MAY REACH THIS CYCLE — the single definition.
     *
     * UAT states the same rule twice (a query closure in the controller's index,
     * and PHP in the middleware) with a comment begging the two never to disagree.
     * They are one edit apart from disagreeing, and the symptom is ugly: the row
     * lists, then 403s when opened. Here the rule lives once, in two forms that a
     * test pins to each other — scopeVisibleTo() for the listing, isVisibleTo()
     * for the boundary.
     *
     * The clause UAT does not need is the approver one. A QAT cycle is signed off
     * by the submitter's immediate MANAGER, who routinely sits in a different
     * department to the team that ran the tests. Without this the manager is 403'd
     * out of the very cycle they were notified to decide, and the feature is dead.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        // Executive mode and the Dev role are not bound to the department axis
        // at all — see {@see TestCycleAccess}.
        if (! $user || TestCycleAccess::seesAllDepartments($user)) {
            return $query;
        }

        $homeDepartmentId = DepartmentContext::homeDepartmentId($user);
        $userId = (int) $user->id;

        return $query->where(function ($q) use ($homeDepartmentId, $userId) {
            // Unowned cycles are shared across departments.
            $q->whereNull('department_id')
                // Being named on the cycle is itself the grant.
                ->orWhere('dev_lead_id', $userId)
                ->orWhere('qa_lead_id', $userId)
                ->orWhere('submitted_by', $userId)
                ->orWhere('created_by', $userId);

            if ($homeDepartmentId) {
                $q->orWhere('department_id', $homeDepartmentId);
            }

            // The snapshotted approver list.
            //
            // Guarded on NOT NULL because sqlsrv compiles whereJsonContains to
            // OPENJSON, which has no defined behaviour on a NULL or empty column —
            // and this column IS null on every draft and every cycle that was never
            // submitted, which is most of them. ScheduleController gets away without
            // the guard only because it filters to status='pending' first.
            //
            // Both int and string forms are probed: the ids come back from json
            // differently depending on what wrote the row.
            $q->orWhere(function ($approver) use ($userId) {
                $approver->whereNotNull('approver_user_ids')
                    ->where(function ($json) use ($userId) {
                        $json->whereJsonContains('approver_user_ids', $userId)
                            ->orWhereJsonContains('approver_user_ids', (string) $userId);
                    });
            });
        });
    }

    /** The row form of {@see scopeVisibleTo}. The two must always agree. */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (TestCycleAccess::seesAllDepartments($user)) {
            return true;
        }

        if ($this->department_id === null) {
            return true;
        }

        $userId = (int) $user->id;

        foreach (['dev_lead_id', 'qa_lead_id', 'submitted_by', 'created_by'] as $column) {
            if ($this->{$column} && (int) $this->{$column} === $userId) {
                return true;
            }
        }

        if ($this->isAssignedApprover($user)) {
            return true;
        }

        return (int) $this->department_id === (int) DepartmentContext::homeDepartmentId($user);
    }

    /**
     * Children are removed bottom-up. The schema deliberately carries no cascade
     * (SQL Server forbids the multiple paths this shape would need), so deletion
     * order is enforced here instead.
     */
    public function cascadeDelete(): void
    {
        DB::transaction(function () {
            QatEvidence::where('qat_cycle_id', $this->id)->delete();
            QatSignoff::where('qat_cycle_id', $this->id)->delete();
            QatFinding::where('qat_cycle_id', $this->id)->delete();
            QatCaseResult::where('qat_cycle_id', $this->id)->delete();
            QatCase::where('qat_cycle_id', $this->id)->delete();
            QatParticipant::where('qat_cycle_id', $this->id)->delete();
            QatSection::where('qat_cycle_id', $this->id)->delete();

            // A promoted UAT cycle outlives its QAT origin. Nothing forces this —
            // the link carries no foreign key precisely so the two modules cannot
            // block each other's deletes — but clearing it stops the UAT page
            // rendering an upstream-QA banner for a cycle that no longer exists.
            UatCycle::where('qat_cycle_id', $this->id)->update(['qat_cycle_id' => null]);

            $this->delete();
        });
    }
}
