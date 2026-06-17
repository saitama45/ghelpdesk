<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WigsTrackValue extends Model
{
    protected $table = 'wigs_track_values';

    protected $fillable = ['name', 'track_question', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function guideQuestions(): HasMany
    {
        return $this->hasMany(WigsTrackGuideQuestion::class, 'track_value_id')->orderBy('sort_order');
    }
}
