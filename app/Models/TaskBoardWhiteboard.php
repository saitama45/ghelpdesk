<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskBoardWhiteboard extends Model
{
    protected $fillable = [
        'task_board_id',
        'scene',
        'revision',
        'updated_by',
    ];

    protected $casts = [
        'task_board_id' => 'integer',
        'revision' => 'integer',
        'updated_by' => 'integer',
        'scene' => 'array',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
