<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCardActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_board_id',
        'task_card_id',
        'actor_id',
        'action',
        'description',
        'meta',
    ];

    protected $casts = [
        'task_board_id' => 'integer',
        'task_card_id' => 'integer',
        'actor_id' => 'integer',
        'meta' => 'array',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class, 'task_card_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
