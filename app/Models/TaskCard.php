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
        'task_board_column_id',
        'project_id',
        'project_task_id',
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
        'weight_basis',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'task_board_id' => 'integer',
        'task_board_column_id' => 'integer',
        'project_id' => 'integer',
        'project_task_id' => 'integer',
        'created_by' => 'integer',
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

    public function column(): BelongsTo
    {
        return $this->belongsTo(TaskBoardColumn::class, 'task_board_column_id');
    }

    /**
     * Column name is the canonical status. When the column relation is loaded we use
     * its (possibly renamed) name; otherwise we fall back to the legacy status string,
     * which is kept in sync on every write — so this never triggers a lazy query.
     */
    public function getStatusAttribute($value): ?string
    {
        if ($this->relationLoaded('column') && $this->column) {
            return $this->column->name;
        }

        return $value;
    }

    public function getColumnRoleAttribute(): ?string
    {
        return $this->column?->role;
    }

    /**
     * Resolve a column on the given board by display name and point this card at it,
     * keeping the legacy status string in sync. Returns the resolved column (if any).
     */
    public function setStatusByName(TaskBoard $board, string $name): ?TaskBoardColumn
    {
        $column = $board->columnForName($name);

        $this->task_board_column_id = $column?->id;
        $this->attributes['status'] = $column?->name ?? $name;

        if ($column) {
            $this->setRelation('column', $column);
        }

        return $column;
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
            foreach ($checklist->items as $item) {
                $total++;
                $complete += $item->is_complete ? 1 : 0;

                $children = $item->children ?? collect();
                $total += $children->count();
                $complete += $children->where('is_complete', true)->count();
            }
        }

        return [
            'total' => $total,
            'complete' => $complete,
        ];
    }

    /**
     * Leaf checklist items for a checklist: subtasks if an item has any, otherwise the item itself.
     */
    private function checklistLeafItems(TaskChecklist $checklist)
    {
        return $checklist->items->flatMap(function ($item) {
            $children = $item->children ?? collect();
            return $children->isNotEmpty() ? $children : collect([$item]);
        });
    }

    /**
     * Total of the weights encoded at the card's chosen weighting level (should equal 100).
     */
    public function getWeightTotalAttribute(): ?float
    {
        $basis = $this->weight_basis;
        if (!$basis || $basis === 'none') {
            return null;
        }

        $sum = 0.0;

        if ($basis === 'checklist') {
            foreach ($this->checklists as $checklist) {
                $sum += (float) ($checklist->weight ?? 0);
            }
        } elseif ($basis === 'item') {
            foreach ($this->checklists as $checklist) {
                foreach ($checklist->items as $item) {
                    $sum += (float) ($item->weight ?? 0);
                }
            }
        } elseif ($basis === 'subtask') {
            foreach ($this->checklists as $checklist) {
                foreach ($checklist->items as $item) {
                    foreach (($item->children ?? collect()) as $child) {
                        $sum += (float) ($child->weight ?? 0);
                    }
                }
            }
        }

        return round($sum, 2);
    }

    /**
     * Weighted completion 0-100: sum of the weights of completed units at the chosen level.
     * A unit counts as complete via its done checkbox; a checklist is complete when all of
     * its leaf items are complete. Returns null when weighting is off (legacy binary mode).
     */
    public function getWeightedCompletionAttribute(): ?int
    {
        $basis = $this->weight_basis;
        if (!$basis || $basis === 'none') {
            return null;
        }

        $done = 0.0;

        if ($basis === 'checklist') {
            foreach ($this->checklists as $checklist) {
                $leaves = $this->checklistLeafItems($checklist);
                if ($leaves->isNotEmpty() && $leaves->every(fn ($i) => (bool) $i->is_complete)) {
                    $done += (float) ($checklist->weight ?? 0);
                }
            }
        } elseif ($basis === 'item') {
            foreach ($this->checklists as $checklist) {
                foreach ($checklist->items as $item) {
                    if ($item->is_complete) {
                        $done += (float) ($item->weight ?? 0);
                    }
                }
            }
        } elseif ($basis === 'subtask') {
            foreach ($this->checklists as $checklist) {
                foreach ($checklist->items as $item) {
                    foreach (($item->children ?? collect()) as $child) {
                        if ($child->is_complete) {
                            $done += (float) ($child->weight ?? 0);
                        }
                    }
                }
            }
        }

        return (int) round(max(0, min(100, $done)));
    }
}
