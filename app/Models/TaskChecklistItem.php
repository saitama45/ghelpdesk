<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_checklist_id',
        'parent_item_id',
        'project_task_id',
        'title',
        'is_complete',
        'assigned_to',
        'due_at',
        'sort_order',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'due_at' => 'datetime:Y-m-d H:i:s',
        'sort_order' => 'integer',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(TaskChecklist::class, 'task_checklist_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TaskChecklistItem::class, 'parent_item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'parent_item_id')
            ->with('assignee:id,name,profile_photo,sub_unit')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
