<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['progress_percentage'];

    /**
     * Fallback project types. The live list is now stored in the shared
     * reference_options table (type = project_type) — use projectTypes().
     * This constant is only used to seed / recover when that table is empty.
     */
    /**
     * How lead times are counted into calendar spans. 'working' skips weekends
     * and non-working PH holidays; 'calendar' counts every day. Applies to every
     * milestone / activity / sub-task in the project.
     */
    const SCHEDULE_DAY_MODES = ['working', 'calendar'];

    const PROJECT_TYPES = [
        'Store Opening',
        'IT Deployment',
        'Internal Initiative',
        'Vendor Project',
        'General',
    ];

    /**
     * The single source of truth for project type options, shared with Project
     * Templates. Returns ordered value strings; falls back to the constant if the
     * reference table has not been seeded yet.
     */
    public static function projectTypes(): array
    {
        $types = ReferenceOption::ofType('project_type')->pluck('value')->all();

        return ! empty($types) ? $types : self::PROJECT_TYPES;
    }

    protected $fillable = [
        'store_id',
        'project_type',
        'subject_type',
        'subject_id',
        'name',
        'status',
        'turn_over_date',
        'training_date',
        'testing_date',
        'mock_service_date',
        'turn_over_to_franchisee_date',
        'target_go_live',
        'day1_date',
        'schedule_day_mode',
        'board_month',
        'board_year',
        'remarks',
        'company_id',
        'brand_company_id',
        'target_store_count',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'store_id'   => 'integer',
        'subject_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'company_id' => 'integer',
        'brand_company_id' => 'integer',
        'target_store_count' => 'integer',
        'turn_over_date' => 'date',
        'training_date'  => 'date',
        'testing_date'   => 'date',
        'mock_service_date'           => 'date',
        'turn_over_to_franchisee_date' => 'date',
        'target_go_live' => 'date',
        'day1_date' => 'date',
        'board_month'    => 'integer',
        'board_year'     => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function entityCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function brandCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'brand_company_id');
    }

    /** The user who created (owns) this project. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The user who last updated this project. */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether $user may manage the WHOLE project — edit project details and every
     * milestone / activity / sub-task, apply templates, add/delete/reorder rows.
     *
     * Grants full access to exactly two parties:
     *  - the project creator (created_by),
     *  - super-admins: the Admin / Solutions Admin roles, which the seeder grants
     *    every permission. (We deliberately do NOT key this off projects.delete —
     *    that permission is handed to many operational roles, so using it would let
     *    non-creators edit every project, defeating the restriction.)
     *
     * Everyone else — including users who hold projects.edit/projects.delete — is
     * limited to the rows assigned to them (see ProjectTask::isEditableBy) and is
     * read-only for the rest. Projects created before ownership tracking are
     * backfilled to their team lead; any that remain ownerless are admin-only.
     */
    public function isManagedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['Admin', 'Solutions Admin'])) {
            return true;
        }

        return $this->created_by !== null && (int) $this->created_by === (int) $user->id;
    }

    /** Polymorphic subject for non-store project types (Vendor, Department, etc.) */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Human-readable subject label for display across all project types. */
    public function getSubjectLabelAttribute(): ?string
    {
        if ($this->project_type === 'Store Opening') {
            return $this->store?->name;
        }
        $subject = $this->subject;
        if (!$subject) return null;
        return $subject->name ?? null;
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)
            ->orderBy('milestone_order')
            ->orderBy('parent_task_id')
            ->orderBy('order')
            ->orderBy('id');
    }

    public function taskBoard(): HasOne
    {
        return $this->hasOne(TaskBoard::class);
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProjectAsset::class);
    }

    /**
     * Recalculate and save the project status based on task progress and target date.
     */
    public function recalculateStatus(): void
    {
        $tasks = $this->tasks;
        $totalTasks = $tasks->count();
        
        if ($totalTasks === 0) {
            $this->update(['status' => 'Pending']);
            return;
        }

        $averageProgress = $this->calculateWeightedProgress($tasks);

        if ($averageProgress >= 100) {
            $newStatus = 'Completed';
        } else {
            // Default to Pending
            $newStatus = 'Pending';
            
            // If any work has started, it's In Progress
            if ($averageProgress > 0) {
                $newStatus = 'In Progress';
            }

            // Check for delays regardless of whether work started or not
            if ($this->target_go_live) {
                $targetDate = \Carbon\Carbon::parse($this->target_go_live)->startOfDay();
                if (\Carbon\Carbon::now()->startOfDay()->gt($targetDate) && $averageProgress < 100) {
                    $newStatus = 'Delayed';
                }
            }
        }

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }

    public function getProgressPercentageAttribute(): int
    {
        return $this->calculateWeightedProgress($this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get());
    }

    private function calculateWeightedProgress($tasks): int
    {
        $roots = $tasks->filter(fn (ProjectTask $task) => ! $task->parent_task_id)
            ->reject(fn (ProjectTask $task) => in_array(trim((string) $task->status), ['N/A', 'Not Applicable'], true));
        if ($roots->isEmpty()) return 0;

        $milestones = $roots->groupBy(fn (ProjectTask $task) => $task->category ?: 'General');
        $milestoneValues = $milestones->map(function ($activities) {
            $explicit = $activities->every(fn (ProjectTask $task) => $task->activity_weight !== null);
            $weightTotal = (float) $activities->sum(fn (ProjectTask $task) => $explicit ? (float) $task->activity_weight : 1);
            return $weightTotal > 0
                ? $activities->sum(fn (ProjectTask $task) => ($explicit ? (float) $task->activity_weight : 1) * (int) $task->progress) / $weightTotal
                : 0;
        });

        $milestoneWeights = $milestones->map(fn ($activities) => (float) ($activities->first()->milestone_weight ?? 0));
        $explicitMilestones = $milestoneWeights->every(fn ($weight) => $weight > 0);
        $total = (float) $milestoneWeights->sum();
        if (! $explicitMilestones || $total <= 0) return (int) round($milestoneValues->avg());

        return (int) round($milestoneValues->sum(fn ($value, $name) => $value * $milestoneWeights[$name]) / $total);
    }
}
