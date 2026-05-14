<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_inventory_report_uses_full_ledger_balances_after_transfer_and_receiving(): void
    {
        foreach (['reports.inventory', 'stock_transfers.post', 'stock_receivings.post'] as $permissionName) {
            Permission::findOrCreate($permissionName);
        }

        $user = User::factory()->create();
        $user->givePermissionTo(['reports.inventory', 'stock_transfers.post', 'stock_receivings.post']);

        $asset = $this->createAsset();
        $stockIn = $this->createPostedStockIn($asset, 'CFE I', 'DR-001');

        $transfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-001',
            'origin_location' => 'CFE I',
            'destination_location' => 'CFE II',
            'status' => 'For Posting',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('stock-transfers.post', $transfer))
            ->assertRedirect();

        $reportAfterTransfer = $this->inventoryReportProps($user);

        $this->assertSame(0, $reportAfterTransfer['summary']['total_soh']);
        $this->assertInventoryRowHasSoh($reportAfterTransfer['assets']['data'], $asset->id, 'CFE I', 0);
        $this->assertLocationSummaryHasSoh($reportAfterTransfer['locationSummaries'], 'CFE I', 0);

        $receiving = StockReceiving::firstOrFail();

        $this->actingAs($user)
            ->post(route('stock-receivings.post', $receiving))
            ->assertRedirect();

        $reportAfterReceiving = $this->inventoryReportProps($user);

        $this->assertSame(1, $reportAfterReceiving['summary']['total_soh']);
        $this->assertInventoryRowHasSoh($reportAfterReceiving['assets']['data'], $asset->id, 'CFE II', 1);
        $this->assertLocationSummaryHasSoh($reportAfterReceiving['locationSummaries'], 'CFE II', 1);

        $stockIn->refresh();
        $this->assertSame('Posted', $stockIn->status);
    }

    public function test_inventory_history_includes_transfer_metadata_for_transfer_out(): void
    {
        Permission::findOrCreate('stock_transfers.post');

        $user = User::factory()->create();
        $user->givePermissionTo('stock_transfers.post');

        $asset = $this->createAsset();
        $this->createPostedStockIn($asset, 'CFE I', 'DR-001');

        $transfer = StockTransfer::create([
            'transfer_date' => '2026-05-14',
            'transfer_no' => 'TRF-001',
            'origin_location' => 'CFE I',
            'destination_location' => 'CFE II',
            'status' => 'For Posting',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('stock-transfers.post', $transfer))
            ->assertRedirect();

        $response = $this->actingAs($user)
            ->getJson(route('reports.inventory.history', $asset->id, false).'?location=CFE%20I');

        $response->assertOk();

        $history = $response->json('history');
        $transferOut = collect($history)->firstWhere('transaction_type', 'Transfer Out');
        $stockIn = collect($history)->firstWhere('transaction_type', 'Stock In');

        $this->assertNotNull($transferOut);
        $this->assertSame('TRF-001', $transferOut['transfer_no']);
        $this->assertSame('CFE I', $transferOut['origin_location']);
        $this->assertSame('CFE II', $transferOut['destination_location']);
        $this->assertNotNull($transferOut['transfer_reference_id']);

        $this->assertNotNull($stockIn);
        $this->assertSame('DR-001', $stockIn['dr_no']);
        $this->assertNotNull($stockIn['stock_in_reference_id']);
    }

    protected function createAsset(): Asset
    {
        $category = Category::create([
            'name' => 'Equipment',
            'is_active' => true,
        ]);

        return Asset::create([
            'item_code' => 'AST-001',
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

    protected function createPostedStockIn(Asset $asset, string $location, string $drNo): StockIn
    {
        $stockIn = StockIn::create([
            'receive_date' => '2026-05-13',
            'dr_no' => $drNo,
            'origin_location' => 'SUPPLIER',
            'destination_location' => $location,
            'received_by' => 'Receiver',
            'status' => 'Posted',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
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

    protected function inventoryReportProps(User $user): array
    {
        $response = $this->actingAs($user)->get(route('reports.inventory'));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    protected function assertInventoryRowHasSoh(array $rows, int $assetId, string $location, int $expectedSoh): void
    {
        $row = collect($rows)->first(fn ($item) => (int) data_get($item, 'asset_id') === $assetId && data_get($item, 'location') === $location);

        $this->assertNotNull($row);
        $this->assertSame($expectedSoh, (int) data_get($row, 'soh'));
    }

    protected function assertLocationSummaryHasSoh(array $summaries, string $location, int $expectedSoh): void
    {
        $summary = collect($summaries)->first(fn ($item) => data_get($item, 'location') === $location);

        $this->assertNotNull($summary);
        $this->assertSame($expectedSoh, (int) data_get($summary, 'total_soh'));
    }
}
