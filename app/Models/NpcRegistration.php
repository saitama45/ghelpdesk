<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcRegistration extends Model
{
    protected $fillable = [
        'npc_status_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }
}
