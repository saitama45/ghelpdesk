<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_checklist_id',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
