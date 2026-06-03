<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryReportController;
use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class InventoryReportLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_report_only_counts_received_transfer_ins_at_destination(): void
    {
        $receivedAsset = $this->createAsset('SKU-RECEIVED');
        $pendingAsset = $this->createAsset('SKU-PENDING');

        $receivedStock = $this->createPostedStockIn($receivedAsset, 'CFE I-WAREHOUSE', 'RECEIVED-SN');
        $pendingStock = $this->createPostedStockIn($pendingAsset, 'CFE I-WAREHOUSE', 'PENDING-SN');

        $receivedTransfer = $this->createTransfer($receivedAsset, $receivedStock, 'TRF-RECEIVED', 'Received');
        $pendingTransfer = $this->createTransfer($pendingAsset, $pendingStock, 'TRF-PENDING', 'Posted');

        $receivedReceiving = $this->createReceiving($receivedTransfer, $receivedAsset, $receivedStock, 'RCV-RECEIVED', 'Received', 1);
        $pendingReceiving = $this->createReceiving($pendingTransfer, $pendingAsset, $pendingStock, 'RCV-PENDING', 'For Receiving', 1);

        InventoryTransaction::create([
            'asset_id' => $receivedAsset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer In',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $receivedReceiving->id,
        ]);
        InventoryTransaction::create([
            'asset_id' => $pendingAsset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Transfer In',
            'quantity' => 1,
            'reference_type' => StockReceiving::class,
            'reference_id' => $pendingReceiving->id,
        ]);

        $rows = $this->inventoryRowsForLocation('CFE I');

        $this->assertSame(1, (int) $rows->firstWhere('asset_id', $receivedAsset->id)->soh);
        $this->assertNull($rows->firstWhere('asset_id', $pendingAsset->id));
    }

    private function inventoryRowsForLocation(string $location)
    {
        $controller = app(InventoryReportController::class);
        $method = new ReflectionMethod($controller, 'inventoryRowsQuery');
        $method->setAccessible(true);
        $request = Request::create('/reports/inventory', 'GET', [
            'location' => $location,
            'stock_status' => 'in_stock',
        ]);

        return $method->invoke($controller, $request)->get();
    }

    private function createAsset(string $itemCode): Asset
    {
        $category = Category::firstOrCreate([
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

    private function createTransfer(Asset $asset, StockIn $sourceStock, string $transferNo, string $status): StockTransfer
    {
        return StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => $transferNo,
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => $status,
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'quantity' => 1,
            'serial_no' => $sourceStock->serial_no,
            'barcode' => $sourceStock->barcode,
            'qrcode' => $sourceStock->qrcode,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
    }

    private function createReceiving(
        StockTransfer $transfer,
        Asset $asset,
        StockIn $sourceStock,
        string $receivingNo,
        string $status,
        int $receivedQuantity
    ): StockReceiving {
        return StockReceiving::create([
            'stock_transfer_id' => $transfer->id,
            'receiving_no' => $receivingNo,
            'receiving_date' => '2026-05-15',
            'origin_location' => 'CFE I-WAREHOUSE',
            'destination_location' => 'CFE I',
            'status' => $status,
            'asset_id' => $asset->id,
            'source_stock_in_id' => $sourceStock->id,
            'transferred_quantity' => 1,
            'received_quantity' => $receivedQuantity,
            'condition' => 'Good',
            'serial_no' => $sourceStock->serial_no,
            'barcode' => $sourceStock->barcode,
            'qrcode' => $sourceStock->qrcode,
            'asset_type' => 'New',
            'is_allocation' => false,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
    }
}
