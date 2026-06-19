<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MallHookupCost extends Model
{
    protected $fillable = [
        'mall_hookup_id',
        'year',
        'amount',
    ];

    protected $casts = [
        'year' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function hookup(): BelongsTo
    {
        return $this->belongsTo(MallHookup::class, 'mall_hookup_id');
    }
}
