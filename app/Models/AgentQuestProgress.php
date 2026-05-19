<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentQuestProgress extends Model
{
    protected $fillable = [
        'agent_id',
        'quest_id',
        'progress',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'progress' => 'integer',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function quest()
    {
        return $this->belongsTo(Quest::class);
    }
}
