<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'asset_id',
        'stock_in_id',
        'serial_no',
        'barcode',
        'transaction_type',
        'condition',
        'purchase_required',
        'procurement_status',
        'quantity',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'stock_in_id' => 'integer',
        'purchase_required' => 'boolean',
        'quantity' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Serialize dates in Asia/Manila so the frontend shows local time
     * (matches the Ticket model; app stores wall-clock in Asia/Manila).
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function stockIn()
    {
        return $this->belongsTo(StockIn::class);
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
