<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WigsTrackGuideQuestion extends Model
{
    protected $table = 'wigs_track_guide_questions';

    protected $fillable = ['track_value_id', 'question', 'sort_order'];

    protected $casts = [
        'track_value_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function trackValue(): BelongsTo
    {
        return $this->belongsTo(WigsTrackValue::class, 'track_value_id');
    }
}
