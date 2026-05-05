<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockInCodeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_stock_in_create_requires_barcode_and_qr_code_per_entry(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();

        $this->actingAs($user)
            ->from('/stock-ins')
            ->post(route('stock-ins.store'), $this->stockInPayload($asset, [
                'barcode' => '',
                'qrcode' => '',
            ]))
            ->assertRedirect('/stock-ins')
            ->assertSessionHasErrors([
                'entries.0.barcode',
                'entries.0.qrcode',
            ]);

        $this->assertDatabaseCount('stock_ins', 0);
    }

    public function test_stock_in_update_requires_barcode_and_qr_code_per_entry(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();
        $stockIn = StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'OLD-BARCODE',
            'qrcode' => 'OLD-QR',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);

        $this->actingAs($user)
            ->from('/stock-ins')
            ->put(route('stock-ins.update', $stockIn), $this->stockInPayload($asset, [
                'barcode' => '',
                'qrcode' => '',
            ], [
                'header_mode' => true,
            ]))
            ->assertRedirect('/stock-ins')
            ->assertSessionHasErrors([
                'entries.0.barcode',
                'entries.0.qrcode',
            ]);

        $stockIn->refresh();

        $this->assertSame('OLD-BARCODE', $stockIn->barcode);
        $this->assertSame('OLD-QR', $stockIn->qrcode);
    }

    public function test_stock_in_create_saves_when_generated_codes_are_present(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();

        $this->actingAs($user)
            ->post(route('stock-ins.store'), $this->stockInPayload($asset, [
                'barcode' => 'AST-001-1770000000000-1',
                'qrcode' => "Item Code: AST-001\nBarcode: AST-001-1770000000000-1",
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('stock_ins', [
            'asset_id' => $asset->id,
            'barcode' => 'AST-001-1770000000000-1',
            'qrcode' => "Item Code: AST-001\nBarcode: AST-001-1770000000000-1",
        ]);
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

    protected function stockInPayload(Asset $asset, array $entryOverrides = [], array $overrides = []): array
    {
        return [
            'receive_date' => '2026-05-05',
            'dr_no' => 'DR-001',
            'dr_date' => '2026-05-05',
            'vendor' => 'Test Vendor',
            'origin_location' => null,
            'received_by' => 'Test User',
            'status' => 'For Posting',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'entries' => [
                array_merge([
                    'serial_no' => 'SN-001',
                    'barcode' => 'AST-001-1770000000000-1',
                    'qrcode' => "Item Code: AST-001\nBarcode: AST-001-1770000000000-1",
                    'warranty_months' => 12,
                    'eol_months' => 60,
                    'cost' => 100,
                    'price' => 150,
                    'destination_location' => null,
                ], $entryOverrides),
            ],
            ...$overrides,
        ];
    }
}
