<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOption extends Model
{
    protected $fillable = [
        'store_id',
        'type',
        'value',
        'meta',
    ];

    protected $casts = [
        'store_id' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
