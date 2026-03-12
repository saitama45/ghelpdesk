<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'status',
        'turn_over_date',
        'training_date',
        'testing_date',
        'mock_service_date',
        'turn_over_to_franchisee_date',
        'target_go_live',
        'remarks',
    ];

    protected $casts = [
        'turn_over_date' => 'date',
        'training_date' => 'date',
        'testing_date' => 'date',
        'mock_service_date' => 'date',
        'turn_over_to_franchisee_date' => 'date',
        'target_go_live' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('order');
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
