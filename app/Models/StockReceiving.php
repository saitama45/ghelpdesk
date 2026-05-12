<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReceiving extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'receiving_no',
        'receiving_date',
        'origin_location',
        'destination_location',
        'asset_id',
        'source_stock_in_id',
        'serial_no',
        'barcode',
        'qrcode',
        'asset_type',
        'is_allocation',
        'warranty_months',
        'eol_months',
        'cost',
        'price',
        'transferred_quantity',
        'received_quantity',
        'condition',
        'damage_notes',
        'status',
        'received_by',
        'received_at',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stock_transfer_id' => 'integer',
        'asset_id' => 'integer',
        'source_stock_in_id' => 'integer',
        'is_allocation' => 'boolean',
        'transferred_quantity' => 'integer',
        'received_quantity' => 'integer',
        'receiving_date' => 'date:Y-m-d',
        'received_at' => 'datetime',
        'warranty_months' => 'integer',
        'eol_months' => 'integer',
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function sourceStockIn()
    {
        return $this->belongsTo(StockIn::class, 'source_stock_in_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
