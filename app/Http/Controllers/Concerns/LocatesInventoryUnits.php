<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StockIn;
use App\Models\Store;
use Closure;
use Illuminate\Support\Collection;

trait LocatesInventoryUnits
{
    /**
     * Resolve a store code or name down to its canonical store code.
     */
    protected function normalizeStoreCode(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }

        $store = Store::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->first(['code']);

        return $store?->code ?? $value;
    }

    /**
     * Build the set of location strings (code + name) that should be treated as
     * the same physical location when matching against denormalized location columns.
     */
    protected function locationVariants(?string $code): array
    {
        if (! $code) {
            return [];
        }

        $variants = [$code];

        $store = Store::query()
            ->where('code', $code)
            ->orWhere('name', $code)
            ->first(['code', 'name']);

        if ($store) {
            if ($store->code && ! in_array($store->code, $variants)) {
                $variants[] = $store->code;
            }
            if ($store->name && ! in_array($store->name, $variants)) {
                $variants[] = $store->name;
            }
        }

        return $variants;
    }

    /**
     * Return Posted StockIn unit rows whose CURRENT location is within the given variants.
     * Current location = destination of the latest "Received" transfer, else the original
     * StockIn destination_location. Mirrors StockTransferController::availableStock.
     *
     * @param  array  $locationVariants
     * @param  \Closure|null  $stockInQuery  optional modifier applied to the base StockIn query
     * @return \Illuminate\Support\Collection<int, \App\Models\StockIn>
     */
    protected function fixedUnitsCurrentlyAt(array $locationVariants, ?Closure $stockInQuery = null): Collection
    {
        if (empty($locationVariants)) {
            return collect();
        }

        $query = StockIn::query()
            ->where('stock_ins.status', 'Posted')
            ->with(['sourceStockTransfers' => function ($q) {
                $q->whereIn('status', ['For Posting', 'Posted', 'Received'])
                  ->select('id', 'source_stock_in_id', 'transfer_no', 'status', 'destination_location')
                  ->orderByDesc('id');
            }])
            ->orderBy('serial_no');

        if ($stockInQuery) {
            $stockInQuery($query);
        }

        return $query->get()
            ->filter(function (StockIn $unit) use ($locationVariants) {
                $lastReceived = $unit->sourceStockTransfers
                    ->firstWhere('status', 'Received');

                if ($lastReceived) {
                    return in_array($lastReceived->destination_location, $locationVariants, true);
                }

                return in_array($unit->destination_location, $locationVariants, true);
            })
            ->values();
    }
}
