<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpcPayment extends Model
{
    public const TRANSACTION_TYPES = ['Registration Fees', 'Renewal'];

    protected $fillable = [
        'npc_status_id',
        'year',
        'reference_no',
        'transaction_no',
        'date_of_payment',
        'transaction_type',
        'amount',
    ];

    protected $casts = [
        'year' => 'integer',
        'date_of_payment' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    public function npcStatus(): BelongsTo
    {
        return $this->belongsTo(NpcStatus::class);
    }
}
