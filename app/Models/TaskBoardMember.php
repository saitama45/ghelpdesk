<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskBoardMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_board_id',
        'user_id',
        'role',
        'starred',
        'last_opened_at',
    ];

    protected $casts = [
        'task_board_id' => 'integer',
        'user_id' => 'integer',
        'starred' => 'boolean',
        'last_opened_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'task_board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
