<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCardComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_card_id',
        'user_id',
        'comment_text',
    ];

    protected $casts = [
        'task_card_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class, 'task_card_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
