<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcDpoProfile extends Model
{
    protected $fillable = [
        'npc_status_id',
        'first_name',
        'middle_initial',
        'last_name',
        'sex',
        'designation',
        'date_designated_dpo',
        'official_dpo_email',
        'mobile_no',
        'telephone_no',
        'role',
    ];

    protected $casts = [
        'date_designated_dpo' => 'date:Y-m-d',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }
}
