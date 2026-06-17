<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WigsPafScore extends Model
{
    protected $table = 'wigs_paf_scores';

    protected $fillable = [
        'pcf_item_id', 'quarter', 'actual_performance', 'rating',
        'value_pass', 'remarks', 'graded_by', 'graded_at',
    ];

    protected $casts = [
        'pcf_item_id' => 'integer',
        'quarter' => 'integer',
        'rating' => 'integer',
        'value_pass' => 'boolean',
        'graded_by' => 'integer',
        'graded_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WigsPcfItem::class, 'pcf_item_id');
    }
}
