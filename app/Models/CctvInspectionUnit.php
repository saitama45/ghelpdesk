<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CctvInspectionUnit extends Pivot
{
    protected $table = 'cctv_inspection_units';

    public $incrementing = true;

    protected $fillable = [
        'cctv_inspection_id',
        'stock_in_id',
        'condition',
        'notes',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(CctvInspection::class, 'cctv_inspection_id');
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class, 'stock_in_id');
    }
}
