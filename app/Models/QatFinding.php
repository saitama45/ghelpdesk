<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A defect found during internal QA. Sibling of {@see UatFinding}, plus the
 * waiver: an unresolved blocker or major finding gates the manager sign-off, and
 * the only way past it is for the manager to name the finding and write down why
 * they are accepting the cycle anyway.
 */
class QatFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'qat_cycle_id',
        'qat_case_id',
        'qat_participant_id',
        'reference',
        'title',
        'details',
        'severity',
        'status',
        'assigned_to_user_id',
        'department_id',
        'ticket_id',
        'resolution_notes',
        'resolved_at',
        'waived_at',
        'waived_by_user_id',
        'waiver_reason',
        'reported_by_user_id',
        'reported_by_name',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'waived_at' => 'datetime',
        'qat_cycle_id' => 'integer',
        'qat_case_id' => 'integer',
        'qat_participant_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'department_id' => 'integer',
        'waived_by_user_id' => 'integer',
        'reported_by_user_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        // ticket_id is deliberately absent — tickets are keyed by UUID.
    ];

    public const SEVERITY_COSMETIC = 'cosmetic';

    public const SEVERITY_MINOR = 'minor';

    public const SEVERITY_MAJOR = 'major';

    public const SEVERITY_BLOCKER = 'blocker';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FOR_RETEST = 'for_retest';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_DEFERRED = 'deferred';

    public static function severities(): array
    {
        return [
            self::SEVERITY_BLOCKER => 'Blocker',
            self::SEVERITY_MAJOR => 'Major',
            self::SEVERITY_MINOR => 'Minor',
            self::SEVERITY_COSMETIC => 'Cosmetic',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_FOR_RETEST => 'For Retest',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_DEFERRED => 'Deferred',
        ];
    }

    /** Findings that still count against readiness. */
    public const UNRESOLVED_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_FOR_RETEST,
    ];

    /** The severities that gate the manager sign-off. */
    public const BLOCKING_SEVERITIES = [
        self::SEVERITY_BLOCKER,
        self::SEVERITY_MAJOR,
    ];

    /** Severity that maps onto a raised ticket's priority. */
    public const SEVERITY_TO_PRIORITY = [
        self::SEVERITY_BLOCKER => 'urgent',
        self::SEVERITY_MAJOR => 'high',
        self::SEVERITY_MINOR => 'medium',
        self::SEVERITY_COSMETIC => 'low',
    ];

    public static function nextReference(int $cycleId): string
    {
        $last = static::where('qat_cycle_id', $cycleId)
            ->orderByDesc('id')
            ->value('reference');

        $next = ($last && preg_match('/(\d+)\s*$/', $last, $m)) ? ((int) $m[1]) + 1 : 1;

        return sprintf('F-%03d', $next);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(QatCase::class, 'qat_case_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(QatParticipant::class, 'qat_participant_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }

    /**
     * Tickets carry an active-entity global scope. That scope is a listing filter,
     * not an authorisation boundary — leaving it on here would make a linked ticket
     * raised under another entity silently vanish from the finding, so it is
     * dropped for this lookup.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)
            ->withoutGlobalScope(ActiveEntityScope::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(QatEvidence::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', self::UNRESOLVED_STATUSES);
    }

    /**
     * The findings that actually stand in the way of a sign-off: unresolved,
     * severe enough to matter, and not already waived by a manager.
     */
    public function scopeBlocking($query)
    {
        return $query->whereIn('status', self::UNRESOLVED_STATUSES)
            ->whereIn('severity', self::BLOCKING_SEVERITIES)
            ->whereNull('waived_at');
    }

    public function isWaived(): bool
    {
        return $this->waived_at !== null;
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::UNRESOLVED_STATUSES, true)
            && in_array($this->severity, self::BLOCKING_SEVERITIES, true)
            && ! $this->isWaived();
    }
}
