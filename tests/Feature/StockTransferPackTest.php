<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\StockIn;
use App\Models\StockPack;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Boxes downstream of Stock In: a whole box and a split box travel through a transfer and
 * into receiving, each piece keeping the box it was received in (taken from its source unit).
 */
class StockTransferPackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['stock_ins.view', 'stock_ins.create', 'stock_ins.post', 'stock_transfers.view', 'stock_transfers.create', 'stock_transfers.post'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions);

        $category = Category::create(['name' => 'Supplies', 'is_active' => true]);
        $this->asset = Asset::create([
            'item_code' => 'TIS-1', 'category_id' => $category->id, 'type' => 'Consumables',
            'is_active' => true, 'base_uom' => 'PC', 'bulk_uom' => 'BOX', 'units_per_bulk' => 3,
        ]);

        // 2 boxes x 3 pieces, received and posted at WH.
        $piece = fn (string $code, string $key) => [
            'asset_id' => $this->asset->id, 'serial_no' => null, 'barcode' => $code, 'qrcode' => "QR {$code}",
            'asset_type' => 'New', 'is_allocation' => false, 'warranty_months' => 0, 'eol_months' => 0,
            'cost' => 10, 'price' => 0, 'destination_location' => 'WH', 'pack_key' => $key,
        ];
        $this->actingAs($this->user)->post('/stock-ins', [
            'receive_date' => '2026-09-21', 'dr_no' => 'DR-1', 'status' => 'For Posting', 'quantity' => 6,
            'entries' => [$piece('P1', 'a'), $piece('P2', 'a'), $piece('P3', 'a'), $piece('P4', 'b'), $piece('P5', 'b'), $piece('P6', 'b')],
            'packs' => [
                ['key' => 'a', 'barcode' => 'BX-A', 'qrcode' => 'QR A', 'bulk_uom' => 'BOX', 'units_per_pack' => 3],
                ['key' => 'b', 'barcode' => 'BX-B', 'qrcode' => 'QR B', 'bulk_uom' => 'BOX', 'units_per_pack' => 3],
            ],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/stock-ins/'.StockIn::min('id').'/post')->assertSessionHasNoErrors();
    }

    protected function entryFor(string $barcode): array
    {
        $unit = StockIn::where('barcode', $barcode)->firstOrFail();

        return [
            'source_stock_in_id' => $unit->id, 'serial_no' => null, 'barcode' => $unit->barcode, 'qrcode' => $unit->qrcode,
            'asset_type' => 'New', 'is_allocation' => false, 'warranty_months' => 0, 'eol_months' => 0,
            'cost' => 10, 'price' => 0,
            'stock_pack_id' => 999999, // a client-sent box id must be ignored
        ];
    }

    public function test_boxed_consumables_list_units_with_their_box(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/stock-transfers/available-stock?asset_id='.$this->asset->id.'&origin_location=WH')
            ->assertOk();

        $units = collect($response->json('available_units'));
        $this->assertCount(6, $units);
        $this->assertSame(['BX-A', 'BX-B'], $units->pluck('pack.barcode')->unique()->sort()->values()->all());
    }

    public function test_whole_and_split_boxes_keep_their_box_through_transfer_and_receiving(): void
    {
        $this->actingAs($this->user)->post('/stock-transfers', [
            'transfer_date' => '2026-09-22', 'transfer_no' => 'TRF-1', 'origin_location' => 'WH',
            'destination_location' => 'ST1', 'status' => 'For Posting',
            'asset_transfers' => [[
                'asset_id' => $this->asset->id, 'quantity' => 4,
                'entries' => [$this->entryFor('P1'), $this->entryFor('P2'), $this->entryFor('P3'), $this->entryFor('P4')],
            ]],
        ])->assertSessionHasNoErrors();

        $boxA = StockPack::where('barcode', 'BX-A')->value('id');
        $boxB = StockPack::where('barcode', 'BX-B')->value('id');
        $this->assertSame(3, StockTransfer::where('stock_pack_id', $boxA)->count());
        $this->assertSame(1, StockTransfer::where('stock_pack_id', $boxB)->count());

        // DR: box A travels whole (one line), P4 is listed alone as split from box B.
        $first = StockTransfer::min('id');
        $pdf = $this->actingAs($this->user)->get("/stock-transfers/{$first}/print-dr");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->actingAs($this->user)->post("/stock-transfers/{$first}/post")->assertSessionHasNoErrors();

        $this->assertSame(3, StockReceiving::where('stock_pack_id', $boxA)->count());
        $this->assertSame(1, StockReceiving::where('stock_pack_id', $boxB)->count());

        $rows = collect($this->actingAs($this->user)->getJson('/stock-receivings/'.StockReceiving::min('id'))->json());
        $this->assertSame('BX-A', $rows->firstWhere('barcode', 'P1')['pack']['barcode']);
    }
}
