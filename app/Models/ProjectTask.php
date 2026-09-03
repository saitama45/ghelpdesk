<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectTask extends Model
{
    use HasFactory, SoftDeletes;

    /** Reporting cutoff selected by Weekly Timeline for the next progress log. */
    public ?\DateTimeInterface $progressRecordedAt = null;

    /**
     * Set while an activity's actual dates are being rolled up from its
     * sub-tasks, so the auto-stamp below does not overwrite that roll-up with
     * today's date. See App\Services\ProjectScheduler::syncParentRollups().
     */
    public bool $skipActualStamping = false;

    protected $fillable = [
        'project_id',
        'store_id',
        'parent_task_id',
        'depends_on_task_id',
        'can_run_parallel',
        'name',
        'category',
        'milestone_order',
        'asset_item',
        'model_specs',
        'qty',
        'responsible',
        'department',
        'sub_unit',
        'assigned_to',
        'external_assignment',
        'support_by',
        'status',
        'manual_status',
        'progress',
        'start_date',
        'end_date',
        'start_anchor_date',
        'lead_time_days',
        'original_start_date',
        'original_end_date',
        'actual_start_date',
        'actual_end_date',
        'dependencies',
        'comments',
        'order',
        'created_by',
        'updated_by',
        'activity_mode',
        'milestone_weight',
        'activity_weight',
        'sub_task_weight',
        'acceptance_criteria',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'store_id' => 'integer',
        'parent_task_id' => 'integer',
        'depends_on_task_id' => 'integer',
        'can_run_parallel' => 'boolean',
        'milestone_order' => 'integer',
        'assigned_to' => 'integer',
        'support_by' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'start_anchor_date' => 'date:Y-m-d',
        'lead_time_days' => 'integer',
        'original_start_date' => 'date:Y-m-d',
        'original_end_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'dependencies' => 'array',
        'progress' => 'integer',
        'order' => 'float',
        'milestone_weight' => 'decimal:2',
        'activity_weight' => 'decimal:2',
        'sub_task_weight' => 'decimal:2',
    ];

    /**
     * Capture the baseline (planned) schedule the first time a row has dates.
     *
     * start_date/end_date are re-derived from the project's Day 1 Date on every
     * reschedule, so they say where the row sits *now*. The originals are written
     * once and never again, which is what lets the Gantt draw planned vs actual.
     */
    protected static function booted(): void
    {
        $captureBaseline = function (ProjectTask $task): void {
            if (! $task->original_start_date && $task->start_date) {
                $task->original_start_date = $task->start_date;
            }

            if (! $task->original_end_date && $task->end_date) {
                $task->original_end_date = $task->end_date;
            }
        };

        // Actual execution dates. The plan says when a row *should* run; these
        // say when it did, which is what lets the Gantt draw an actual bar that
        // starts earlier or later than the plan instead of a fill pinned to it.
        // Stamped on the first sign of work and on completion, then left alone
        // so a hand-corrected date is never overwritten.
        $stampActuals = function (ProjectTask $task): void {
            if ($task->skipActualStamping) {
                return;
            }

            // Weekly Timeline reports against a past week; stamp the date the
            // progress was reported for, not the day it was typed in.
            $reportedOn = ($task->progressRecordedAt ?? now())->format('Y-m-d');

            $started = (int) $task->progress > 0
                || in_array($task->status, ['Ongoing', 'In Progress', 'Done', 'Completed'], true);

            if ($started && ! $task->actual_start_date) {
                $task->actual_start_date = $reportedOn;
            }

            $finished = (int) $task->progress >= 100
                || in_array($task->status, ['Done', 'Completed'], true);

            if ($finished && ! $task->actual_end_date) {
                $task->actual_end_date = $reportedOn;
            }

            // Reopened work: the row is running again, so it has no finish yet.
            if (! $finished
                && $task->actual_end_date
                && ! $task->isDirty('actual_end_date')
                && $task->isDirty(['progress', 'status'])) {
                $task->actual_end_date = null;
            }
        };

        static::creating($captureBaseline);
        static::updating($captureBaseline);
        static::creating($stampActuals);
        static::updating($stampActuals);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The rollout store represented by a Per Store activity/sub-task. */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_task_id');
    }

    /** The requisite row this one starts after. NULL = follow the previous row. */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'depends_on_task_id');
    }

    public function subTasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_task_id')
            ->orderBy('milestone_order')
            ->orderBy('order')
            ->orderBy('id');
    }

    public function taskCard(): HasOne
    {
        return $this->hasOne(TaskCard::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProjectAsset::class, 'project_task_id');
    }

    /** Support tickets raised for this specific Gantt sub-task. */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'project_task_id')
            ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->latest();
    }

    /**
     * The manual states a task may be put into, on top of the progress-derived
     * Pending / Ongoing / Done. Editable in reference_options; the constant is
     * only a fallback for a table that has not been seeded.
     */
    const MANUAL_STATUSES = ['Blocked', 'For Approval'];

    public static function manualStatuses(): array
    {
        $values = ReferenceOption::valuesOfType('project_task_status');

        return ! empty($values) ? $values : self::MANUAL_STATUSES;
    }

    /**
     * What the workspace screens show for this row.
     *
     * `status` is recomputed from `progress` on every write, so a person's choice
     * cannot live there — `manual_status` holds it and wins when set. Otherwise
     * the derived status is mapped to the workspace's vocabulary.
     */
    public function displayStatus(): string
    {
        if (filled($this->manual_status)) {
            return $this->manual_status;
        }

        return match (trim((string) $this->status)) {
            'Done'    => 'Completed',
            'Ongoing' => 'In Progress',
            default   => 'Not Started',
        };
    }

    /**
     * The department accountable for this row.
     *
     * The department stored on the activity/sub-task is the accountable process
     * department and therefore wins for monitoring. The assigned user's
     * department describes who is executing the work and is only a fallback for
     * manually-created rows that have no accountable department of their own.
     *
     * Callers that resolve this in bulk should eager-load `assignedUser` — see
     * ProjectOverviewService — or this lazy-loads one query per row.
     */
    public function resolvedDepartment(): ?string
    {
        $fromRow = trim((string) $this->department);

        if ($fromRow !== '') {
            return $fromRow;
        }

        $fromAssignee = $this->assigned_to ? trim((string) $this->assignedUser?->department) : '';

        return $fromAssignee !== '' ? $fromAssignee : null;
    }

    /**
     * Whether $user may edit THIS row.
     *
     * Delegates to App\Support\ProjectPlanAccess, the single rule for the plan:
     * project managers edit everything, a milestone owner everything inside their
     * milestone, an activity assignee their activity and its sub-tasks, and a
     * sub-task assignee their own sub-task.
     */
    public function isEditableBy(?User $user): bool
    {
        return \App\Support\ProjectPlanAccess::canEditTask($this, $user);
    }
}
