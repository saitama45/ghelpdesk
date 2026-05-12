<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_date',
        'transfer_no',
        'origin_location',
        'destination_location',
        'requested_by',
        'memo_remarks',
        'posted_by',
        'posted_date',
        'received_by',
        'received_at',
        'status',
        'asset_id',
        'source_stock_in_id',
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
        'source_stock_in_id' => 'integer',
        'is_allocation' => 'boolean',
        'quantity' => 'integer',
        'transfer_date' => 'date:Y-m-d',
        'posted_date' => 'datetime',
        'received_at' => 'datetime',
        'warranty_date' => 'date',
        'eol_date' => 'date',
        'warranty_months' => 'integer',
        'eol_months' => 'integer',
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'created_by' => 'integer',
        'updated_by' => 'integer',
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

    public function receivings()
    {
        return $this->hasMany(StockReceiving::class, 'stock_transfer_id');
    }

    protected static function booted()
    {
        static::saving(function ($transfer) {
            if ($transfer->transfer_date) {
                $baseDate = Carbon::parse($transfer->transfer_date);
                
                if ($transfer->warranty_months) {
                    $transfer->warranty_date = $baseDate->copy()->addMonths($transfer->warranty_months);
                }
                
                if ($transfer->eol_months) {
                    $transfer->eol_date = $baseDate->copy()->addMonths($transfer->eol_months);
                }
            }
        });
    }
}
