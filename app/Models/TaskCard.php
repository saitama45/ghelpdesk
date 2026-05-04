<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskCard extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = [
        'Backlogs',
        'In Progress',
        'For Verification',
        'Done',
    ];

    protected $fillable = [
        'task_board_id',
        'title',
        'description',
        'status',
        'sort_order',
        'start_at',
        'due_at',
        'due_reminder_minutes',
        'due_complete',
        'cover_type',
        'cover_value',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'start_at' => 'datetime:Y-m-d H:i:s',
        'due_at' => 'datetime:Y-m-d H:i:s',
        'due_complete' => 'boolean',
        'archived_at' => 'datetime:Y-m-d H:i:s',
        'sort_order' => 'integer',
        'due_reminder_minutes' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_card_assignees')->withTimestamps();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'task_card_label')->withTimestamps();
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_card_watchers')->withTimestamps();
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(TaskChecklist::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskCardComment::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskCardAttachment::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskCardActivity::class)->latest();
    }

    public function getChecklistTotalsAttribute(): array
    {
        $total = 0;
        $complete = 0;

        foreach ($this->checklists as $checklist) {
            $total += $checklist->items->count();
            $complete += $checklist->items->where('is_complete', true)->count();
        }

        return [
            'total' => $total,
            'complete' => $complete,
        ];
    }
}
