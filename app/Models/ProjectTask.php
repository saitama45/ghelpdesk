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
        'original_end_date',
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
        'original_end_date' => 'date:Y-m-d',
        'dependencies' => 'array',
        'progress' => 'integer',
        'order' => 'float',
        'milestone_weight' => 'decimal:2',
        'activity_weight' => 'decimal:2',
        'sub_task_weight' => 'decimal:2',
    ];

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
