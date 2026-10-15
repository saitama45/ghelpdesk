<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockInCodeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_custom_barcode_and_qr_code_are_saved_as_entered(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();

        $this->actingAs($user)
            ->post(route('stock-ins.store'), $this->stockInPayload($asset, [
                'barcode' => 'TAG-000123',
                'qrcode' => 'https://example.test/assets/TAG-000123',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('stock_ins', [
            'asset_id' => $asset->id,
            'barcode' => 'TAG-000123',
            'qrcode' => 'https://example.test/assets/TAG-000123',
        ]);
    }

    public function test_custom_barcode_cannot_repeat_within_one_stock_in(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();
        $payload = $this->stockInPayload($asset, ['barcode' => 'TAG-1']);
        $payload['entries'][] = [...$payload['entries'][0], 'serial_no' => 'SN-002', 'barcode' => 'tag-1'];
        $payload['quantity'] = 2;

        $this->actingAs($user)
            ->from('/stock-ins')
            ->post(route('stock-ins.store'), $payload)
            ->assertRedirect('/stock-ins')
            ->assertSessionHasErrors(['entries.0.barcode']);

        $this->assertDatabaseCount('stock_ins', 0);
    }

    public function test_custom_barcode_already_on_another_entitys_stock_is_rejected(): void
    {
        $cbtl = Company::create(['name' => 'CBTL Entity', 'code' => 'CBTL', 'type' => 'Entity', 'is_active' => true]);
        $other = Company::create(['name' => 'Other Entity', 'code' => 'OTH', 'type' => 'Entity', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $cbtl->id]);
        $asset = $this->createAsset();
        $asset->forceFill(['company_id' => $cbtl->id])->save();
        $elsewhere = StockIn::create([
            'receive_date' => '2026-04-01',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'barcode' => 'TAG-000123',
            'qrcode' => 'QR-ELSEWHERE',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]);
        $elsewhere->forceFill(['company_id' => $other->id])->save();

        $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $cbtl->id])
            ->from('/stock-ins')
            ->post(route('stock-ins.store'), $this->stockInPayload($asset, ['barcode' => 'TAG-000123']))
            ->assertRedirect('/stock-ins')
            ->assertSessionHasErrors(['entries.0.barcode']);

        $this->assertSame(1, StockIn::withoutGlobalScopes()->count());
    }

    public function test_editing_a_group_keeps_a_barcode_its_rows_already_share(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();
        // Imported rows can already share one code (e.g. the maker's barcode); editing must not block on it.
        $rows = collect(['SN-001', 'SN-002'])->map(fn ($serial) => StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => $serial,
            'barcode' => 'EAN-4800016',
            'qrcode' => 'QR-'.$serial,
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
        ]));
        $payload = $this->stockInPayload($asset, ['barcode' => 'EAN-4800016', 'qrcode' => 'QR-SN-001'], ['header_mode' => true]);
        $payload['entries'][] = [...$payload['entries'][0], 'serial_no' => 'SN-002', 'qrcode' => 'QR-SN-002'];
        $payload['quantity'] = 2;

        $this->actingAs($user)
            ->put(route('stock-ins.update', $rows[0]), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, StockIn::where('barcode', 'EAN-4800016')->count());
    }

    public function test_saved_and_edited_stock_in_prints_barcode_and_qr_graphics(): void
    {
        $cbtl = Company::create([
            'name' => 'CBTL Entity',
            'code' => 'CBTL',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['company_id' => $cbtl->id]);
        $asset = $this->createAsset();
        $asset->forceFill(['company_id' => $cbtl->id])->save();

        $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $cbtl->id])
            ->post(route('stock-ins.store'), $this->stockInPayload($asset))
            ->assertRedirect();

        $stockIn = StockIn::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($cbtl->id, (int) $stockIn->company_id);

        $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $cbtl->id])
            ->put(route('stock-ins.update', $stockIn), $this->stockInPayload($asset, [
                'barcode' => 'EDITED-BARCODE-001',
                'qrcode' => "Item Code: AST-001\nBarcode: EDITED-BARCODE-001",
            ], [
                'header_mode' => true,
            ]))
            ->assertRedirect();

        $stockIn->refresh();

        foreach (['stock-ins.print-barcodes', 'stock-ins.print-qrcodes'] as $routeName) {
            $response = $this->actingAs($user)
                ->withSession([CompanyContext::SESSION_KEY => $cbtl->id])
                ->get(route($routeName, $stockIn));
            $response->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertHeader('cache-control', 'max-age=0, must-revalidate, no-cache, no-store, private');

            $pdf = $response->getContent();
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertMatchesRegularExpression('/\/Subtype\s*\/Image/', $pdf);
            $this->assertStringContainsString('AST-001', $this->decompressedPdfStreams($pdf));
        }
    }

    protected function decompressedPdfStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        return collect($matches[1] ?? [])->map(function (string $stream) {
            $decoded = @gzuncompress($stream);

            return $decoded === false ? '' : $decoded;
        })->implode("\n");
    }

    public function test_posting_stock_in_updates_group_status_and_posting_metadata(): void
    {
        Permission::create(['name' => 'stock_ins.post']);

        Carbon::setTestNow('2026-05-05 14:30:00');

        $user = User::factory()->create();
        $user->givePermissionTo('stock_ins.post');
        $asset = $this->createAsset();
        $groupedRow = StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'posted_by' => null,
            'posted_date' => null,
            'updated_by' => null,
            'status' => 'For Posting',
        ]);
        $sameHeaderRow = StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-002',
            'barcode' => 'BARCODE-002',
            'qrcode' => 'QR-002',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'posted_by' => null,
            'posted_date' => null,
            'updated_by' => null,
            'status' => 'For Posting',
        ]);

        $this->actingAs($user)
            ->post(route('stock-ins.post', $groupedRow))
            ->assertRedirect();

        $groupedRow->refresh();
        $sameHeaderRow->refresh();

        $this->assertSame('Posted', $groupedRow->status);
        $this->assertSame('Posted', $sameHeaderRow->status);
        $this->assertSame($user->name, $groupedRow->posted_by);
        $this->assertSame($user->name, $sameHeaderRow->posted_by);
        $this->assertSame('2026-05-05 14:30:00', $groupedRow->posted_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-05 14:30:00', $sameHeaderRow->posted_date->format('Y-m-d H:i:s'));
        $this->assertNull($groupedRow->updated_by);
        $this->assertNull($sameHeaderRow->updated_by);
        $this->assertDatabaseHas('inventory_transactions', [
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $groupedRow->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $sameHeaderRow->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Carbon::setTestNow();
    }

    public function test_posting_stock_in_requires_destination_location(): void
    {
        Permission::create(['name' => 'stock_ins.post']);

        $user = User::factory()->create();
        $user->givePermissionTo('stock_ins.post');
        $asset = $this->createAsset();
        $stockIn = StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => null,
            'status' => 'For Posting',
        ]);

        $this->actingAs($user)
            ->from('/stock-ins')
            ->post(route('stock-ins.post', $stockIn))
            ->assertRedirect('/stock-ins')
            ->assertSessionHasErrors(['destination_location']);

        $stockIn->refresh();

        $this->assertSame('For Posting', $stockIn->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_inventory_backfill_creates_missing_ledger_rows_without_duplicates(): void
    {
        $asset = $this->createAsset();
        $missingLedgerRow = StockIn::create([
            'receive_date' => '2026-05-04',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-001',
            'barcode' => 'BARCODE-001',
            'qrcode' => 'QR-001',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'status' => 'Posted',
            'posted_date' => '2026-05-06 09:49:00',
        ]);
        $existingLedgerRow = StockIn::create([
            'receive_date' => '2026-05-04',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-002',
            'barcode' => 'BARCODE-002',
            'qrcode' => 'QR-002',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'status' => 'Posted',
            'posted_date' => '2026-05-06 09:50:00',
        ]);

        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $existingLedgerRow->id,
        ]);

        $this->assertSame(0, Artisan::call('inventory:backfill'));
        $this->assertSame(0, Artisan::call('inventory:backfill'));

        $this->assertDatabaseHas('inventory_transactions', [
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $missingLedgerRow->id,
        ]);
        $this->assertSame(1, InventoryTransaction::where('reference_type', StockIn::class)
            ->where('reference_id', $missingLedgerRow->id)
            ->count());
        $this->assertSame(1, InventoryTransaction::where('reference_type', StockIn::class)
            ->where('reference_id', $existingLedgerRow->id)
            ->count());
    }

    public function test_available_stock_can_include_current_transfer_child_when_editing(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();
        $sourceStock = StockIn::create([
            'receive_date' => '2026-05-04',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-SOURCE',
            'barcode' => 'BARCODE-SOURCE',
            'qrcode' => 'QR-SOURCE',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'status' => 'Posted',
        ]);
        $transferChild = StockIn::create([
            'receive_date' => '2026-05-05',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'source_stock_in_id' => $sourceStock->id,
            'serial_no' => 'SN-SOURCE',
            'barcode' => 'BARCODE-SOURCE',
            'qrcode' => 'QR-SOURCE',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'CFE I',
            'destination_location' => 'CFE II',
            'status' => 'For Posting',
        ]);

        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $sourceStock->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('stock-ins.available-stock', [
                'asset_id' => $asset->id,
                'origin_location' => 'CFE I',
            ]))
            ->assertOk()
            ->assertJsonPath('available_units', []);

        $this->actingAs($user)
            ->getJson(route('stock-ins.available-stock', [
                'asset_id' => $asset->id,
                'origin_location' => 'CFE I',
                'exclude_child_ids' => [$transferChild->id],
            ]))
            ->assertOk()
            ->assertJsonPath('available_units.0.id', $sourceStock->id);
    }

    public function test_stock_in_assets_with_stock_returns_assets_available_at_origin(): void
    {
        $user = User::factory()->create();
        $asset = $this->createAsset();
        $postedStock = StockIn::create([
            'receive_date' => '2026-05-04',
            'asset_id' => $asset->id,
            'quantity' => 1,
            'serial_no' => 'SN-SOURCE',
            'barcode' => 'BARCODE-SOURCE',
            'qrcode' => 'QR-SOURCE',
            'warranty_months' => 12,
            'eol_months' => 60,
            'cost' => 100,
            'price' => 150,
            'origin_location' => 'SUPPLIER',
            'destination_location' => 'CFE I',
            'status' => 'Posted',
        ]);

        InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => 'CFE I',
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $postedStock->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('stock-ins.assets-with-stock', ['location' => 'CFE I']))
            ->assertOk()
            ->assertJsonPath('0.id', $asset->id)
            ->assertJsonPath('0.soh', 1);
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
                    'asset_id' => $asset->id,
                    'serial_no' => 'SN-001',
                    'barcode' => 'AST-001-1770000000000-1',
                    'qrcode' => "Item Code: AST-001\nBarcode: AST-001-1770000000000-1",
                    'asset_type' => 'New',
                    'is_allocation' => false,
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
