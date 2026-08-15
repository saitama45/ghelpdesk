<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cell of the verdict matrix: what a single participant found for a single
 * test case.
 */
class UatCaseResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'uat_cycle_id',
        'uat_case_id',
        'uat_participant_id',
        'result',
        'remarks',
        'executed_at',
        'executed_by_user_id',
        'executed_by_name',
        'source',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'uat_cycle_id' => 'integer',
        'uat_case_id' => 'integer',
        'uat_participant_id' => 'integer',
        'executed_by_user_id' => 'integer',
    ];

    public const PENDING = 'pending';
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const BLOCKED = 'blocked';
    public const NOT_APPLICABLE = 'not_applicable';
    public const ONGOING = 'ongoing';

    public static function results(): array
    {
        return [
            self::PENDING => 'Pending',
            self::PASSED => 'Passed',
            self::FAILED => 'Failed',
            self::BLOCKED => 'Blocked',
            self::ONGOING => 'Ongoing',
            self::NOT_APPLICABLE => 'N/A',
        ];
    }

    /**
     * Verdicts that count as executed. The source workbook computed
     * "% executed" as (Passed + Failed) / Total; Blocked joins them here
     * because a blocked case has genuinely been attempted.
     */
    public const EXECUTED_RESULTS = [self::PASSED, self::FAILED, self::BLOCKED];

    /** Verdicts that stop a cycle from being ready for sign-off. */
    public const BLOCKING_RESULTS = [self::FAILED, self::BLOCKED];

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

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(UatEvidence::class);
    }

    public function isExecuted(): bool
    {
        return in_array($this->result, self::EXECUTED_RESULTS, true);
    }
}
