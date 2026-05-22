<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcStatusWorkflowStep extends Model
{
    protected $fillable = [
        'npc_status_id',
        'key',
        'label',
        'sort_order',
        'is_done',
        'completed_at',
        'remarks',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_done' => 'boolean',
        'completed_at' => 'date:Y-m-d',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }
}
