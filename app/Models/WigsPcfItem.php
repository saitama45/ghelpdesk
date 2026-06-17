<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WigsPcfItem extends Model
{
    protected $table = 'wigs_pcf_items';

    protected $fillable = [
        'pcf_id', 'kra', 'wig', 'lead_measures', 'performance_standard',
        'performance_metric', 'metric_benchmark',
        'q1_weight', 'q2_weight', 'q3_weight', 'q4_weight',
        'value_alignment', 'value_remarks', 'sort_order',
    ];

    protected $casts = [
        'pcf_id' => 'integer',
        'q1_weight' => 'decimal:2',
        'q2_weight' => 'decimal:2',
        'q3_weight' => 'decimal:2',
        'q4_weight' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function pcf(): BelongsTo
    {
        return $this->belongsTo(WigsPcf::class, 'pcf_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(WigsPafScore::class, 'pcf_item_id');
    }
}
