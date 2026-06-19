<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MallHookupLog extends Model
{
    protected $fillable = [
        'mall_hookup_id',
        'log_date',
        'status',
        'remark',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'log_date' => 'date:Y-m-d',
    ];

    public function hookup(): BelongsTo
    {
        return $this->belongsTo(MallHookup::class, 'mall_hookup_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
