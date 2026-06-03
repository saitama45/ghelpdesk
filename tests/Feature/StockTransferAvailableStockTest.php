<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferAvailableStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_with_stock_includes_assets_received_from_previous_transfers(): void
    {
        $user = User::factory()->create();
        $directAsset = $this->createAsset('SKU-DIRECT');
        $receivedAsset = $this->createAsset('SKU-RECEIVED');
        $outOfStockAsset = $this->createAsset('SKU-EMPTY');

        $this->createPostedStockIn($directAsset, 'CFE I', 'DIRECT-SN');

        $sourceStock = $this->createPostedStockIn($receivedAsset, 'CFE I-WAREHOUSE', 'RECEIVED-SN');
        $receivedTransfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-RECEIVED',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Received',
            'asset_id' => $receivedAsset->id,
            'source_stock_in_id' => $sourceStock->id,
            'quantity' => 1,
            'serial_no' => 'RECEIVED-SN',
            'barcode' => 'BARCODE-RECEIVED-SN',
            'qrcode' => 'QR-RECEIVED-SN',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        $receiving = StockReceiving::create([
            'stock_transfer_id' => $receivedTransfer->id,
            'receiving_no' => 'RCV-RECEIVED',
            'receiving_date' => '2026-05-15',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Received',
            'asset_id' => $receivedAsset->id,
            'source_stock_in_id' => $sourceStock->id,
            'transferred_quantity' => 1,
            'received_quantity' => 1,
            'condition' => 'Good',
            'serial_no' => 'RECEIVED-SN',
            'barcode' => 'BARCODE-RECEIVED-SN',
            'qrcode' => 'QR-RECEIVED-SN',
            'asset_type' => 'New',
            'is_allocation' => false,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        InventoryTransaction::create([
            'asset_id' => $receivedAsset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer In',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $receiving->id,
        ]);

        $emptyStock = $this->createPostedStockIn($outOfStockAsset, 'CFE I', 'EMPTY-SN');
        $emptyTransfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-EMPTY',
            'origin_location' => 'CFE I',
            'destination_location' => 'CFE II',
            'status' => 'Posted',
            'asset_id' => $outOfStockAsset->id,
            'source_stock_in_id' => $emptyStock->id,
            'quantity' => 1,
            'serial_no' => 'EMPTY-SN',
            'barcode' => 'BARCODE-EMPTY-SN',
            'qrcode' => 'QR-EMPTY-SN',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        InventoryTransaction::create([
            'asset_id' => $outOfStockAsset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer Out',
            'quantity' => -1,
            'reference_type' => StockTransfer::class,
            'reference_id' => $emptyTransfer->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('stock-transfers.assets-with-stock', ['location' => 'CFE I']));

        $response->assertOk();
        $assets = collect($response->json());

        $this->assertSame(1, (int) $assets->firstWhere('id', $directAsset->id)['soh']);
        $this->assertSame(1, (int) $assets->firstWhere('id', $receivedAsset->id)['soh']);
        $this->assertNull($assets->firstWhere('id', $outOfStockAsset->id));
    }

    public function test_assets_with_stock_ignores_unreceived_transfer_in_ledger_rows(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset('SKU-PENDING');
        $sourceStock = $this->createPostedStockIn($asset, 'CFE I-WAREHOUSE', 'PENDING-SN');
        $pendingTransfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-PENDING',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Posted',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'quantity' => 1,
            'serial_no' => 'PENDING-SN',
            'barcode' => 'BARCODE-PENDING-SN',
            'qrcode' => 'QR-PENDING-SN',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        $receiving = StockReceiving::create([
            'stock_transfer_id' => $pendingTransfer->id,
            'receiving_no' => 'RCV-PENDING',
            'receiving_date' => '2026-05-15',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'For Receiving',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'transferred_quantity' => 1,
            'received_quantity' => 1,
            'condition' => 'Good',
            'serial_no' => 'PENDING-SN',
            'barcode' => 'BARCODE-PENDING-SN',
            'qrcode' => 'QR-PENDING-SN',
            'asset_type' => 'New',
            'is_allocation' => false,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer In',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $receiving->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('stock-transfers.assets-with-stock', ['location' => 'CFE I']));

        $response->assertOk();
        $assets = collect($response->json());

        $this->assertNull($assets->firstWhere('id', $asset->id));
    }

    public function test_declined_receiving_restores_stock_to_origin_location(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset('SKU-DECLINED');
        $sourceStock = $this->createPostedStockIn($asset, 'CFE I-WAREHOUSE', 'DECLINED-SN');
        $declinedTransfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-DECLINED',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Declined',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'quantity' => 1,
            'serial_no' => 'DECLINED-SN',
            'barcode' => 'BARCODE-DECLINED-SN',
            'qrcode' => 'QR-DECLINED-SN',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        $receiving = StockReceiving::create([
            'stock_transfer_id' => $declinedTransfer->id,
            'receiving_no' => 'RCV-DECLINED',
            'receiving_date' => '2026-05-15',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Declined',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'transferred_quantity' => 1,
            'received_quantity' => 0,
            'condition' => 'Good',
            'serial_no' => 'DECLINED-SN',
            'barcode' => 'BARCODE-DECLINED-SN',
            'qrcode' => 'QR-DECLINED-SN',
            'asset_type' => 'New',
            'is_allocation' => false,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I-WAREHOUSE',
            'transaction_type' => 'Transfer Out',
            'quantity' => -1,
            'reference_type' => StockTransfer::class,
            'reference_id' => $declinedTransfer->id,
        ]);
        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I-WAREHOUSE',
            'transaction_type' => 'Receiving Declined',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $receiving->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('stock-transfers.assets-with-stock', ['location' => 'CFE I-WAREHOUSE']));

        $response->assertOk();
        $assets = collect($response->json());

        $this->assertSame(1, (int) $assets->firstWhere('id', $asset->id)['soh']);
    }

    public function test_available_stock_returns_fixed_unit_received_from_previous_transfer(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset('SKU-RECEIVED');
        $sourceStock = $this->createPostedStockIn($asset, 'CFE I-WAREHOUSE', 'RECEIVED-SN');

        $receivedTransfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-RECEIVED',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Received',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'quantity' => 1,
            'serial_no' => 'RECEIVED-SN',
            'barcode' => 'BARCODE-RECEIVED-SN',
            'qrcode' => 'QR-RECEIVED-SN',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        $receiving = StockReceiving::create([
            'stock_transfer_id' => $receivedTransfer->id,
            'receiving_no' => 'RCV-RECEIVED',
            'receiving_date' => '2026-05-15',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => 'Received',
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'transferred_quantity' => 1,
            'received_quantity' => 1,
            'condition' => 'Good',
            'serial_no' => 'RECEIVED-SN',
            'barcode' => 'BARCODE-RECEIVED-SN',
            'qrcode' => 'QR-RECEIVED-SN',
            'asset_type' => 'New',
            'is_allocation' => false,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer In',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $receiving->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('stock-transfers.available-stock', [
                'asset_id' => $asset->id,
                'origin_location' => 'CFE I',
            ]))
            ->assertOk()
            ->assertJsonPath('soh', 1)
            ->assertJsonPath('available_units.0.id', $sourceStock->id);
    }

    private function createAsset(string $itemCode, ?Category $category = null): Asset
    {
        $category ??= Category::firstOrCreate([
            'name' => 'Equipment',
        ], [
            'is_active' => true,
        ]);

        return Asset::create([
            'item_code' => $itemCode,
            'category_id' => $category->id,
            'brand' => 'Test Brand',
            'model' => 'Test Model',
            'description' => 'Test asset',
            'cost' => 100,
            'type' => 'Fixed',
            'eol_years' => 5,
            'is_active' => true,
        ]);
    }

    private function createPostedStockIn(Asset $asset, string $location, string $serialNo): StockIn
    {
        $stockIn = StockIn::create([
            'receive_date' => '2026-05-13',
            'origin_location' => 'SUPPLIER',
            'destination_location' => $location,
            'received_by' => 'Receiver',
            'status' => 'Posted',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => $serialNo,
            'barcode' => "BARCODE-{$serialNo}",
            'qrcode' => "QR-{$serialNo}",
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);

        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => $location,
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $stockIn->id,
        ]);

        return $stockIn;
    }
}
