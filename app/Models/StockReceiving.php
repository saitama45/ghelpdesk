<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class StockReceiving extends Model
{
    use HasFactory;

    /**
     * A line reported unserved: nothing arrived, so it stays open "in transit" until the
     * source returns it to stock or writes it off. Shared with linkportal, which reports
     * unserved lines with exactly this state.
     */
    public function isInTransit(): bool
    {
        return $this->status === 'For Receiving'
            && $this->condition === 'Missing'
            && (int) $this->received_quantity === 0;
    }

    /**
     * Transfer ids (as keys, for ->has()) whose receiving line at one of the given
     * destinations was closed as Received with nothing received: written off as lost in
     * transit, or posted as zero before unserved lines stayed open. Their transfer reads
     * 'Received', yet the piece never got there, so unit-location lookups must put it on
     * no shelf instead of at the destination.
     *
     * Entity-agnostic on purpose: this answers where a piece physically is, so the listing
     * scope must not hide the receiving line of a transfer the caller can see.
     *
     * @param  array<int, string>  $destinations
     * @return Collection<int, int>
     */
    public static function neverArrivedTransferIds(array $destinations): Collection
    {
        if (empty($destinations)) {
            return collect();
        }

        return static::query()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->whereIn('destination_location', $destinations)
            ->where('status', 'Received')
            ->where('received_quantity', '<', 1)
            ->whereNotNull('stock_transfer_id')
            ->pluck('stock_transfer_id')
            ->flip();
    }

    protected $fillable = [
        'stock_transfer_id',
        'receiving_no',
        'receiving_date',
        'origin_location',
        'destination_location',
        'asset_id',
        'stock_pack_id',
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
        'dr_image_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stock_transfer_id' => 'integer',
        'asset_id' => 'integer',
        'stock_pack_id' => 'integer',
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

    /** The bulk unit (box/pack) this piece was received in, if any. */
    public function pack()
    {
        return $this->belongsTo(StockPack::class, 'stock_pack_id');
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
