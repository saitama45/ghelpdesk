<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_code',
        'sap_codes',
        'category_id',
        'sub_category_id',
        'brand',
        'model',
        'description',
        'cost',
        'type',
        'eol_years',
        'is_active',
        'base_uom',
        'bulk_uom',
        'units_per_bulk',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sub_category_id' => 'integer',
        'cost' => 'decimal:2',
        'eol_years' => 'integer',
        'is_active' => 'boolean',
        'units_per_bulk' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class);
    }

    public function ticketAssets()
    {
        return $this->hasMany(TicketAsset::class);
    }
}
