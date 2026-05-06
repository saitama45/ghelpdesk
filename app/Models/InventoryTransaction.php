<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'location',
        'transaction_type',
        'quantity',
        'reference_type',
        'reference_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'quantity' => 'integer',
        'reference_id' => 'integer',
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

    public function reference()
    {
        return $this->morphTo();
    }
}
