<?php

namespace App\Console\Commands;

use App\Models\InventoryTransaction;
use App\Models\StockIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInventoryLedger extends Command
{
    protected $signature = 'inventory:backfill';
    protected $description = 'Populate inventory ledger from existing posted stock-in records';

    public function handle()
    {
        $postedStockIns = StockIn::where('status', 'Posted')->get();

        if ($postedStockIns->isEmpty()) {
            $this->info('No posted stock-in records found.');
            return 0;
        }

        $this->info("Found {$postedStockIns->count()} posted records. Backfilling...");

        DB::transaction(function () use ($postedStockIns) {
            foreach ($postedStockIns as $stockIn) {
                // Check if transaction already exists to avoid duplicates
                $exists = InventoryTransaction::where('reference_type', StockIn::class)
                    ->where('reference_id', $stockIn->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $isTransfer = !empty($stockIn->origin_location)
                    && strtoupper(trim((string) $stockIn->origin_location)) !== 'SUPPLIER';

                if ($isTransfer) {
                    // Out from origin
                    InventoryTransaction::create([
                        'asset_id' => $stockIn->asset_id,
                        'location' => $stockIn->origin_location,
                        'transaction_type' => 'Transfer Out',
                        'quantity' => -$stockIn->quantity,
                        'reference_type' => StockIn::class,
                        'reference_id' => $stockIn->id,
                        'created_by' => $stockIn->created_by,
                        'updated_by' => $stockIn->updated_by,
                        'created_at' => $stockIn->posted_date ?? $stockIn->created_at,
                    ]);

                    // In to destination
                    InventoryTransaction::create([
                        'asset_id' => $stockIn->asset_id,
                        'location' => $stockIn->destination_location ?: $stockIn->location,
                        'transaction_type' => 'Transfer In',
                        'quantity' => $stockIn->quantity,
                        'reference_type' => StockIn::class,
                        'reference_id' => $stockIn->id,
                        'created_by' => $stockIn->created_by,
                        'updated_by' => $stockIn->updated_by,
                        'created_at' => $stockIn->posted_date ?? $stockIn->created_at,
                    ]);
                } else {
                    // Standard Stock In
                    InventoryTransaction::create([
                        'asset_id' => $stockIn->asset_id,
                        'location' => $stockIn->destination_location ?: $stockIn->location,
                        'transaction_type' => 'Stock In',
                        'quantity' => $stockIn->quantity,
                        'reference_type' => StockIn::class,
                        'reference_id' => $stockIn->id,
                        'created_by' => $stockIn->created_by,
                        'updated_by' => $stockIn->updated_by,
                        'created_at' => $stockIn->posted_date ?? $stockIn->created_at,
                    ]);
                }
            }
        });

        $this->info('Backfill completed successfully.');
        return 0;
    }
}
