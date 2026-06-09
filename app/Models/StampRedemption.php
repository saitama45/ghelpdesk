<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StampRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'stamp_card_id',
        'customer_id',
        'stamp_program_id',
        'asset_id',
        'location',
        'quantity',
        'inventory_transaction_id',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stamp_card_id' => 'integer',
        'customer_id' => 'integer',
        'stamp_program_id' => 'integer',
        'asset_id' => 'integer',
        'quantity' => 'integer',
        'inventory_transaction_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function card()
    {
        return $this->belongsTo(StampCard::class, 'stamp_card_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function program()
    {
        return $this->belongsTo(StampProgram::class, 'stamp_program_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function inventoryTransaction()
    {
        return $this->belongsTo(InventoryTransaction::class);
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
