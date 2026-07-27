<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per progress change on a project task — the history the Progress Chart
 * replays to answer "what was this project at, at the end of week W?".
 *
 * Append-only: rows are never updated, only inserted (see ProjectTaskObserver).
 */
class ProjectProgressLog extends Model
{
    protected $fillable = [
        'project_id',
        'project_task_id',
        'progress',
        'recorded_at',
    ];

    protected $casts = [
        'project_id'      => 'integer',
        'project_task_id' => 'integer',
        'progress'        => 'integer',
        'recorded_at'     => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class);
    }
}
