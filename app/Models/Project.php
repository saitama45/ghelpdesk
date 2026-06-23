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

    const PROJECT_TYPES = [
        'Store Opening',
        'IT Deployment',
        'Internal Initiative',
        'Vendor Project',
        'General',
    ];

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
        'board_month',
        'board_year',
        'remarks',
    ];

    protected $casts = [
        'store_id'   => 'integer',
        'subject_id' => 'integer',
        'turn_over_date' => 'date',
        'training_date'  => 'date',
        'testing_date'   => 'date',
        'mock_service_date'           => 'date',
        'turn_over_to_franchisee_date' => 'date',
        'target_go_live' => 'date',
        'board_month'    => 'integer',
        'board_year'     => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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

        // Calculate average progress across all tasks
        $totalProgressSum = $tasks->sum('progress');
        $averageProgress = $totalProgressSum / $totalTasks;

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
}
