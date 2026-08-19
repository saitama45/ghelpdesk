<?php

namespace App\Models;

use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-off event on a QAT cycle.
 *
 * The one that matters is the MANAGER stage, recorded with a null participant:
 * the decision by the submitter's immediate manager that gates promotion to UAT.
 * The optional REVIEW stage lets a department acknowledge its own column first.
 *
 * Re-deciding appends a new row and flips the previous one's `is_current`, so the
 * ledger keeps the full history — including a rejection that was later overturned
 * — instead of overwriting a date in a cell.
 */
class QatSignoff extends Model
{
    use HasFactory;

    protected $fillable = [
        'qat_cycle_id',
        'qat_participant_id',
        'stage',
        'result',
        'remarks',
        'waived_finding_ids',
        'waiver_reason',
        'resolved_approver_ids',
        'confirmed_at',
        'confirmed_by_user_id',
        'confirmed_name',
        'confirmed_email',
        'signature_path',
        'ip_address',
        'is_current',
    ];

    /** The drawn signature is only ever consumed as a URL by the front end. */
    protected $appends = ['signature_url'];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'is_current' => 'boolean',
        'waived_finding_ids' => 'array',
        'resolved_approver_ids' => 'array',
        'qat_cycle_id' => 'integer',
        'qat_participant_id' => 'integer',
        'confirmed_by_user_id' => 'integer',
    ];

    public const STAGE_REVIEW = 'review';

    public const STAGE_MANAGER = 'manager';

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
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(QatParticipant::class, 'qat_participant_id');
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

    public function hasWaiver(): bool
    {
        return ! empty($this->waived_finding_ids);
    }

    public function getSignatureUrlAttribute(): ?string
    {
        return SignatureImage::url($this->signature_path);
    }
}
