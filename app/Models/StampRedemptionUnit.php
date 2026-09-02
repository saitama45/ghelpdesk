<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StampRedemptionUnit extends Model
{
    protected $fillable = [
        'stamp_redemption_id',
        'stock_in_id',
        'serial_no',
        'barcode',
        'qrcode',
    ];

    protected $casts = [
        'stamp_redemption_id' => 'integer',
        'stock_in_id' => 'integer',
    ];

    public function redemption()
    {
        return $this->belongsTo(StampRedemption::class, 'stamp_redemption_id');
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class);
    }
}
