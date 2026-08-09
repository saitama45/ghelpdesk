<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'description',
        'asset_group_id',
        'is_active',
    ];

    protected $casts = [
        'asset_group_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Asset Operational Health group (reference_options, type = asset_group).
     * Null for the many categories that only classify tickets.
     */
    public function assetGroup()
    {
        return $this->belongsTo(ReferenceOption::class, 'asset_group_id');
    }
}
