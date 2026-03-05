<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'name',
        'sector',
        'area',
        'brand',
        'cluster',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sector' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
