<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'category_id',
        'sub_category_id',
        'name',
        'description',
        'priority',
        'concern_type',
        'requires_rca_on_resolve',
        'is_active',
    ];

    protected $casts = [
        'requires_rca_on_resolve' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class);
    }
}
