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

        $created = 0;
        $skippedExisting = 0;
        $skippedInvalid = 0;

        DB::transaction(function () use ($postedStockIns, &$created, &$skippedExisting, &$skippedInvalid) {
            foreach ($postedStockIns as $stockIn) {
                $ledgerRows = $this->stockInLedgerRows($stockIn);

                if (empty($ledgerRows)) {
                    $skippedInvalid++;

                    continue;
                }

                $hasExistingReference = InventoryTransaction::where('reference_type', StockIn::class)
                    ->where('reference_id', $stockIn->id)
                    ->exists();

                if ($hasExistingReference) {
                    $skippedExisting += count($ledgerRows);

                    continue;
                }

                foreach ($ledgerRows as $ledgerRow) {
                    InventoryTransaction::forceCreate($ledgerRow);
                    $created++;
                }
            }
        });

        $this->info("Backfill completed successfully. Created {$created} ledger row(s); skipped {$skippedExisting} existing row(s); skipped {$skippedInvalid} invalid stock-in row(s).");

        return 0;
    }

    private function stockInLedgerRows(StockIn $stockIn): array
    {
        $timestamp = $stockIn->posted_date ?? $stockIn->created_at ?? now();
        $base = [
            'asset_id' => $stockIn->asset_id,
            'reference_type' => StockIn::class,
            'reference_id' => $stockIn->id,
            'created_by' => $stockIn->created_by,
            'updated_by' => $stockIn->updated_by,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($this->isInternalTransferLocation($stockIn->origin_location)) {
            $originLocation = $this->normalizeLocation($stockIn->origin_location);
            $destinationLocation = $this->normalizeLocation($stockIn->destination_location);

            if (! $originLocation || ! $destinationLocation) {
                $this->warn("Skipped stock-in #{$stockIn->id}: internal transfers require origin and destination locations.");

                return [];
            }

            return [
                [
                    ...$base,
                    'location' => $originLocation,
                    'transaction_type' => 'Transfer Out',
                    'quantity' => -$stockIn->quantity,
                ],
                [
                    ...$base,
                    'location' => $destinationLocation,
                    'transaction_type' => 'Transfer In',
                    'quantity' => $stockIn->quantity,
                ],
            ];
        }

        $destinationLocation = $this->normalizeLocation($stockIn->destination_location);

        if (! $destinationLocation) {
            $this->warn("Skipped stock-in #{$stockIn->id}: destination location is required.");

            return [];
        }

        return [
            [
                ...$base,
                'location' => $destinationLocation,
                'transaction_type' => 'Stock In',
                'quantity' => $stockIn->quantity,
            ],
        ];
    }

    private function isInternalTransferLocation(?string $location): bool
    {
        $location = $this->normalizeLocation($location);

        return $location !== null && strtoupper($location) !== 'SUPPLIER';
    }

    private function normalizeLocation(?string $location): ?string
    {
        $location = trim((string) $location);

        return $location === '' ? null : $location;
    }
}
