<?php

namespace App\Models;

use App\Models\Concerns\IsSharedCanvas;
use App\Models\Contracts\SharedCanvas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Standalone whiteboard (/whiteboards). Not the task-board whiteboard, which lives in
 * task_board_whiteboards and follows board membership instead of visibility.
 */
class Whiteboard extends Model implements SharedCanvas
{
    use IsSharedCanvas, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'visibility',
        'department_id',
        'scene',
        'preview',
        'revision',
        'page_count',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'revision' => 'integer',
        'page_count' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'scene' => 'array',
    ];
}
