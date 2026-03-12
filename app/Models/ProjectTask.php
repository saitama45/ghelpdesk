<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'parent_task_id',
        'name',
        'category',
        'assigned_to',
        'support_by',
        'status',
        'progress',
        'start_date',
        'end_date',
        'original_end_date',
        'dependencies',
        'comments',
        'order',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'original_end_date' => 'date:Y-m-d',
        'dependencies' => 'array',
        'progress' => 'integer',
        'order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_task_id');
    }

    public function subTasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_task_id')->orderBy('order');
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
}
