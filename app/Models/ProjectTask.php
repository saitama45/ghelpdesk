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
    ];

    protected $casts = [
        'project_id' => 'integer',
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
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
     * The department accountable for this row.
     *
     * The assigned user's department wins, because that reflects who is actually
     * doing the work; the department copied from the activity template is the
     * fallback. That order matters: only a handful of rows carry an assignee,
     * while the template fills `department` on most of them, so relying on
     * either one alone would leave most rows unattributed.
     *
     * Callers that resolve this in bulk should eager-load `assignedUser` — see
     * ProjectOverviewService — or this lazy-loads one query per row.
     */
    public function resolvedDepartment(): ?string
    {
        $fromAssignee = $this->assigned_to ? trim((string) $this->assignedUser?->department) : '';

        if ($fromAssignee !== '') {
            return $fromAssignee;
        }

        $fromRow = trim((string) $this->department);

        return $fromRow !== '' ? $fromRow : null;
    }

    /**
     * Whether $user may edit THIS row.
     *
     * Project managers (see Project::isManagedBy) may edit every row; everyone
     * else may only edit the activity / sub-task assigned to them.
     */
    public function isEditableBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->project && $this->project->isManagedBy($user)) {
            return true;
        }

        return $this->assigned_to !== null && (int) $this->assigned_to === (int) $user->id;
    }
}
