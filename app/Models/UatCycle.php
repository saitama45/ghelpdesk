<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class UatCycle extends Model
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
        'qat_cycle_id',
        'status',
        'start_date',
        'target_signoff_date',
        'go_live_date',
        'signoff_requires_all',
        'gate_on_critical_only',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'links' => 'array',
        'cycle_no' => 'integer',
        'start_date' => 'date:Y-m-d',
        'target_signoff_date' => 'date:Y-m-d',
        'go_live_date' => 'date:Y-m-d',
        'signoff_requires_all' => 'boolean',
        'gate_on_critical_only' => 'boolean',
        'company_id' => 'integer',
        'department_id' => 'integer',
        'qa_lead_id' => 'integer',
        'dev_lead_id' => 'integer',
        'qat_cycle_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SIGNED_OFF = 'signed_off';
    public const STATUS_CANCELLED = 'cancelled';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_ON_HOLD => 'On Hold',
            self::STATUS_COMPLETED => 'Testing Complete',
            self::STATUS_SIGNED_OFF => 'Signed Off',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /** Statuses that still accept verdicts from testers. */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
    ];

    public static function environments(): array
    {
        return ['Web', 'Mobile', 'Web & Mobile', 'Desktop', 'API', 'Staging', 'Production'];
    }

    /**
     * Sequential per-year code, e.g. UAT-2026-0007. Collisions are possible under
     * concurrency, so the caller retries on the unique-index violation.
     */
    public static function nextCode(): string
    {
        $year = date('Y');
        $last = static::where('code', 'like', "UAT-{$year}-%")
            ->orderByDesc('id')
            ->value('code');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('UAT-%s-%04d', $year, $next);
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

    /**
     * The upstream internal QA cycle this one was promoted from, if any.
     *
     * The link carries no foreign key on purpose — QAT and UAT are independent
     * modules and neither may block the other's deletes — so a dangling id simply
     * resolves to null, which is what the upstream-QA banner checks for.
     */
    public function qatCycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(UatSection::class)->orderBy('order')->orderBy('id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(UatCase::class)->orderBy('order')->orderBy('id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(UatParticipant::class)->orderBy('order')->orderBy('id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(UatCaseResult::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(UatFinding::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(UatEvidence::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(UatSignoff::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Children are removed bottom-up. The schema deliberately carries no cascade
     * (SQL Server forbids the multiple paths this shape would need), so deletion
     * order is enforced here instead.
     */
    public function cascadeDelete(): void
    {
        DB::transaction(function () {
            UatEvidence::where('uat_cycle_id', $this->id)->delete();
            UatSignoff::where('uat_cycle_id', $this->id)->delete();
            UatFinding::where('uat_cycle_id', $this->id)->delete();
            UatCaseResult::where('uat_cycle_id', $this->id)->delete();
            UatCase::where('uat_cycle_id', $this->id)->delete();
            UatParticipant::where('uat_cycle_id', $this->id)->delete();
            UatSection::where('uat_cycle_id', $this->id)->delete();
            $this->delete();
        });
    }
}
