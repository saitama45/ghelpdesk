<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskBoardColumn extends Model
{
    use HasFactory;

    /**
     * The default columns seeded onto every board. The `role` is a stable key the
     * project-sync service maps onto, fully decoupled from the editable display name.
     */
    public const DEFAULTS = [
        ['name' => 'Backlogs', 'color' => '#64748b', 'role' => 'backlog'],
        ['name' => 'In Progress', 'color' => '#2563eb', 'role' => 'in_progress'],
        ['name' => 'For Verification', 'color' => '#d97706', 'role' => 'for_verification'],
        ['name' => 'Done', 'color' => '#059669', 'role' => 'done'],
    ];

    protected $fillable = [
        'task_board_id',
        'name',
        'color',
        'role',
        'sort_order',
    ];

    protected $casts = [
        'task_board_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class, 'task_board_column_id');
    }
}
