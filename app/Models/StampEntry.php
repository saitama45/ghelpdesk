<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StampEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'stamp_card_id',
        'store_id',
        'quantity',
        'source',
        'purchase_amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'stamp_card_id' => 'integer',
        'store_id' => 'integer',
        'quantity' => 'integer',
        'purchase_amount' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public function card()
    {
        return $this->belongsTo(StampCard::class, 'stamp_card_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
