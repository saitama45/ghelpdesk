<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tracked defect. In the source workbook these lived as free text in a
 * "Remarks" column — numbered by hand, assigned to nobody, and closed by nobody.
 * Here each one is a row with a severity, an owner, a status, and an optional
 * link to a real helpdesk ticket.
 */
class UatFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'uat_cycle_id',
        'uat_case_id',
        'uat_participant_id',
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
        'reported_by_user_id',
        'reported_by_name',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'uat_cycle_id' => 'integer',
        'uat_case_id' => 'integer',
        'uat_participant_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'department_id' => 'integer',
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

    /** Findings that still count against go-live readiness. */
    public const UNRESOLVED_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_FOR_RETEST,
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
        $last = static::where('uat_cycle_id', $cycleId)
            ->orderByDesc('id')
            ->value('reference');

        $next = ($last && preg_match('/(\d+)\s*$/', $last, $m)) ? ((int) $m[1]) + 1 : 1;

        return sprintf('F-%03d', $next);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(UatCase::class, 'uat_case_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(UatParticipant::class, 'uat_participant_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Tickets carry an active-entity global scope. That scope is a listing
     * filter, not an authorisation boundary — leaving it on here would make a
     * linked ticket raised under another entity silently vanish from the
     * finding, so it is dropped for this lookup.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)
            ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(UatEvidence::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', self::UNRESOLVED_STATUSES);
    }
}
