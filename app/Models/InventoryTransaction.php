<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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

    public function scopeValidInventoryLedger(
        Builder $query,
        string $table = 'inventory_transactions',
        string $aliasPrefix = 'valid_inventory'
    ): Builder {
        $stockIns = "{$aliasPrefix}_stock_ins";
        $stockReceivings = "{$aliasPrefix}_stock_receivings";
        $stockTransfers = "{$aliasPrefix}_stock_transfers";

        return $query
            ->leftJoin("stock_ins as {$stockIns}", function ($join) use ($table, $stockIns) {
                $join->on("{$table}.reference_id", '=', "{$stockIns}.id")
                    ->where("{$table}.reference_type", '=', StockIn::class);
            })
            ->leftJoin("stock_receivings as {$stockReceivings}", function ($join) use ($table, $stockReceivings) {
                $join->on("{$table}.reference_id", '=', "{$stockReceivings}.id")
                    ->where("{$table}.reference_type", '=', StockReceiving::class);
            })
            ->leftJoin("stock_transfers as {$stockTransfers}", function ($join) use ($table, $stockTransfers) {
                $join->on("{$table}.reference_id", '=', "{$stockTransfers}.id")
                    ->where("{$table}.reference_type", '=', StockTransfer::class);
            })
            ->where(function ($query) use ($table, $stockIns, $stockReceivings, $stockTransfers) {
                $query->where(function ($query) use ($table, $stockIns) {
                    $query->where("{$table}.reference_type", StockIn::class)
                        ->where("{$stockIns}.status", 'Posted');
                })->orWhere(function ($query) use ($table, $stockReceivings) {
                    $query->where("{$table}.reference_type", StockReceiving::class)
                        ->where("{$table}.transaction_type", 'Transfer In')
                        ->where("{$stockReceivings}.status", 'Received');
                })->orWhere(function ($query) use ($table, $stockReceivings) {
                    $query->where("{$table}.reference_type", StockReceiving::class)
                        ->where("{$table}.transaction_type", 'Receiving Declined')
                        ->where("{$stockReceivings}.status", 'Declined');
                })->orWhere(function ($query) use ($table, $stockTransfers) {
                    $query->where("{$table}.reference_type", StockTransfer::class)
                        ->where("{$table}.transaction_type", 'Transfer Out')
                        ->whereIn("{$stockTransfers}.status", ['Posted', 'Received', 'Declined']);
                })->orWhere(function ($query) use ($table) {
                    // Loyalty stamp redemptions are always final (no posting workflow),
                    // so they count unconditionally. Matched purely on the two columns —
                    // no join needed.
                    $query->where("{$table}.reference_type", StampRedemption::class)
                        ->where("{$table}.transaction_type", 'Stamp Redemption');
                });
            });
    }
}
