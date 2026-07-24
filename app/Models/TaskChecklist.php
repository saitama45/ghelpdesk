<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_card_id',
        'title',
        'notes',
        'due_date',
        'weight',
        'sort_order',
    ];

    protected $casts = [
        'task_card_id' => 'integer',
        'weight' => 'float',
        'sort_order' => 'integer',
        'due_date' => 'date:Y-m-d',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class, 'task_card_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)
            ->whereNull('parent_item_id')
            ->with(['assignee:id,name,profile_photo,org_path', 'children.assignee:id,name,profile_photo,org_path'])
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
