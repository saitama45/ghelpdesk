<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_code',
        'category_id',
        'sub_category_id',
        'brand',
        'model',
        'description',
        'cost',
        'type',
        'eol_years',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'eol_years' => 'integer',
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
