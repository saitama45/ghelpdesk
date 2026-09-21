<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bulk unit (BOX of 12 PC, ...) received at Stock In, with its own barcode/QR label.
 * The pieces inside are ordinary stock rows pointing here via stock_pack_id.
 *
 * Deliberately NOT in CompanyContext::SCOPED_MODELS: packs are always reached through
 * piece rows that are already entity-scoped, and filtering direct id lookups again would
 * hide a pack from its own pieces (see the ActiveEntityScope pitfall).
 */
class StockPack extends Model
{
    protected $fillable = [
        'company_id',
        'asset_id',
        'barcode',
        'qrcode',
        'bulk_uom',
        'units_per_pack',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'asset_id' => 'integer',
        'units_per_pack' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'stock_pack_id');
    }
}
