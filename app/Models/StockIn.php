<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class StockIn extends Model
{
    use HasFactory;

    protected $fillable = [
        'receive_date',
        'asset_id',
        'quantity',
        'serial_no',
        'warranty_months',
        'warranty_date',
        'eol_months',
        'eol_date',
        'cost',
        'price',
        'location',
    ];

    protected $casts = [
        'receive_date' => 'date',
        'warranty_date' => 'date',
        'eol_date' => 'date',
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    protected static function booted()
    {
        static::saving(function ($stockIn) {
            if ($stockIn->receive_date) {
                $receiveDate = Carbon::parse($stockIn->receive_date);
                
                if ($stockIn->warranty_months) {
                    $stockIn->warranty_date = $receiveDate->copy()->addMonths($stockIn->warranty_months);
                }
                
                if ($stockIn->eol_months) {
                    $stockIn->eol_date = $receiveDate->copy()->addMonths($stockIn->eol_months);
                }
            }
        });
    }
}
