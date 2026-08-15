<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An acceptance event. The source pack had two tables — per-department "User
 * Acceptance" and a management "Final Sign-off" — which are the two stages here.
 *
 * Re-signing appends a new row and flips the previous one's `is_current`, so the
 * ledger keeps the full history instead of overwriting a date in a cell.
 */
class UatSignoff extends Model
{
    use HasFactory;

    protected $fillable = [
        'uat_cycle_id',
        'uat_participant_id',
        'stage',
        'result',
        'remarks',
        'confirmed_at',
        'confirmed_by_user_id',
        'confirmed_name',
        'confirmed_email',
        'ip_address',
        'is_current',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'is_current' => 'boolean',
        'uat_cycle_id' => 'integer',
        'uat_participant_id' => 'integer',
        'confirmed_by_user_id' => 'integer',
    ];

    public const STAGE_ACCEPTANCE = 'acceptance';
    public const STAGE_FINAL = 'final';

    public const RESULT_PASSED = 'passed';
    public const RESULT_PASSED_WITH_RESERVATION = 'passed_with_reservation';
    public const RESULT_NOT_ACCEPTED = 'not_accepted';

    public static function results(): array
    {
        return [
            self::RESULT_PASSED => 'Passed',
            self::RESULT_PASSED_WITH_RESERVATION => 'Passed with reservation',
            self::RESULT_NOT_ACCEPTED => 'Not accepted',
        ];
    }

    /** Results that let the cycle progress. A reservation still accepts. */
    public const ACCEPTING_RESULTS = [
        self::RESULT_PASSED,
        self::RESULT_PASSED_WITH_RESERVATION,
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(UatCycle::class, 'uat_cycle_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(UatParticipant::class, 'uat_participant_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function isAccepting(): bool
    {
        return in_array($this->result, self::ACCEPTING_RESULTS, true);
    }
}
