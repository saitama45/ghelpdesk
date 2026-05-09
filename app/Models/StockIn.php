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
        'source_stock_in_id',
        'dr_no',
        'dr_date',
        'vendor',
        'origin_location',
        'destination_location',
        'received_by',
        'memo_remarks',
        'posted_by',
        'posted_date',
        'status',
        'asset_id',
        'asset_type',
        'is_allocation',
        'quantity',
        'serial_no',
        'barcode',
        'qrcode',
        'warranty_months',
        'warranty_date',
        'eol_months',
        'eol_date',
        'cost',
        'price',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'is_allocation' => 'boolean',
        'source_stock_in_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'receive_date' => 'date',
        'dr_date' => 'date',
        'posted_date' => 'datetime',
        'warranty_date' => 'date',
        'eol_date' => 'date',
        'warranty_months' => 'integer',
        'eol_months' => 'integer',
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function sourceStockIn()
    {
        return $this->belongsTo(StockIn::class, 'source_stock_in_id');
    }

    public function transferChildren()
    {
        return $this->hasMany(StockIn::class, 'source_stock_in_id');
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
