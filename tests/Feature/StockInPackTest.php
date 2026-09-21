<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stock In with boxes: a box is a stock_packs row with its own label; its pieces stay
 * one-per-row stock records pointing at it. Stock is still counted in pieces.
 */
class StockInPackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stock_ins.view', 'stock_ins.create', 'stock_ins.edit', 'stock_ins.post'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['stock_ins.view', 'stock_ins.create', 'stock_ins.edit', 'stock_ins.post']);

        $category = Category::create(['name' => 'Supplies', 'is_active' => true]);
        $this->asset = Asset::create([
            'item_code' => 'TIS-1', 'category_id' => $category->id, 'type' => 'Consumables',
            'is_active' => true, 'base_uom' => 'PC', 'bulk_uom' => 'BOX', 'units_per_bulk' => 3,
        ]);
    }

    private function piece(string $code, ?string $packKey = null): array
    {
        return [
            'asset_id' => $this->asset->id, 'serial_no' => null, 'barcode' => $code, 'qrcode' => "QR {$code}",
            'asset_type' => 'New', 'is_allocation' => false, 'warranty_months' => 0, 'eol_months' => 0,
            'cost' => 10, 'price' => 0, 'destination_location' => 'WH', 'pack_key' => $packKey,
        ];
    }

    private function pack(string $key, string $code, int $units = 3): array
    {
        return ['key' => $key, 'barcode' => $code, 'qrcode' => "QR {$code}", 'bulk_uom' => 'BOX', 'units_per_pack' => $units];
    }

    /** 2 boxes x 3 pieces + 2 loose pieces. */
    protected function payload(array $overrides = []): array
    {
        $entries = [
            $this->piece('P1', 'a'), $this->piece('P2', 'a'), $this->piece('P3', 'a'),
            $this->piece('P4', 'b'), $this->piece('P5', 'b'), $this->piece('P6', 'b'),
            $this->piece('L1'), $this->piece('L2'),
        ];

        return array_merge([
            'receive_date' => '2026-09-21', 'dr_no' => 'DR-9', 'vendor' => 'ACME', 'status' => 'For Posting',
            'quantity' => count($entries), 'entries' => $entries,
            'packs' => [$this->pack('a', 'BX-A'), $this->pack('b', 'BX-B')],
        ], $overrides);
    }

    public function test_boxes_and_loose_pieces_are_saved_as_pieces_linked_to_packs(): void
    {
        $this->actingAs($this->user)->post('/stock-ins', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(8, StockIn::count());
        $this->assertSame(2, StockPack::count());
        $boxA = StockPack::where('barcode', 'BX-A')->firstOrFail();
        $this->assertSame(['P1', 'P2', 'P3'], StockIn::where('stock_pack_id', $boxA->id)->orderBy('id')->pluck('barcode')->all());
        $this->assertSame(2, StockIn::whereNull('stock_pack_id')->count());
        $this->assertSame([3, 'BOX', $this->asset->id], [$boxA->units_per_pack, $boxA->bulk_uom, $boxA->asset_id]);
    }

    public function test_posting_counts_pieces_in_the_ledger(): void
    {
        $this->actingAs($this->user)->post('/stock-ins', $this->payload());
        $first = StockIn::orderBy('id')->firstOrFail();

        $this->actingAs($this->user)->post("/stock-ins/{$first->id}/post")->assertSessionHasNoErrors();

        $this->assertSame(8, (int) InventoryTransaction::where('asset_id', $this->asset->id)->sum('quantity'));
    }

    public function test_a_box_must_hold_its_declared_count_and_unique_code(): void
    {
        $short = $this->payload(['packs' => [$this->pack('a', 'BX-A', 4), $this->pack('b', 'BX-B')]]);
        $this->actingAs($this->user)->post('/stock-ins', $short)->assertSessionHasErrors('packs');

        $this->actingAs($this->user)->post('/stock-ins', $this->payload());
        $again = $this->payload();
        foreach ($again['entries'] as $i => $entry) {
            $again['entries'][$i]['barcode'] = 'N'.$entry['barcode'];
        }
        $this->actingAs($this->user)->post('/stock-ins', $again)->assertSessionHasErrors('packs');

        $this->assertSame(2, StockPack::count());
    }

    public function test_editing_keeps_boxes_and_can_turn_a_box_into_loose_pieces(): void
    {
        $this->actingAs($this->user)->post('/stock-ins', $this->payload());
        $first = StockIn::orderBy('id')->firstOrFail();
        $boxA = StockPack::where('barcode', 'BX-A')->firstOrFail();

        $edit = $this->payload(['header_mode' => true]);
        $edit['packs'] = [array_merge($this->pack('a', 'BX-A'), ['id' => $boxA->id])];
        foreach ([3, 4, 5] as $i) {
            $edit['entries'][$i]['pack_key'] = null; // box B opened: its pieces become loose
        }

        $this->actingAs($this->user)->put("/stock-ins/{$first->id}", $edit)->assertSessionHasNoErrors();

        $this->assertSame(3, StockIn::where('stock_pack_id', $boxA->id)->count());
        $this->assertSame(5, StockIn::whereNull('stock_pack_id')->count());
        $this->assertSame(8, StockIn::count());
    }

    public function test_import_groups_rows_with_the_same_box_no_into_one_box(): void
    {
        $plain = Asset::create([
            'item_code' => 'PEN-1', 'category_id' => $this->asset->category_id, 'type' => 'Consumables', 'is_active' => true,
        ]);
        $head = 'receive_date,dr_no,dr_date,vendor,origin_location,received_by,item_code,serial_no,barcode,qrcode,warranty_months,eol_months,cost,price,destination_location';
        $row = fn (string $item, string $code, string $box) => "2026-09-21,DR-7,2026-09-21,ACME,,Tester,{$item},,{$code},QR,0,0,10,0,WH,{$box}";

        $csv = implode("\n", [
            $head.',box_no',
            $row('TIS-1', 'I1', 'A'), $row('TIS-1', 'I2', 'A'), $row('TIS-1', 'I3', 'B'),
            $row('TIS-1', 'I4', ''),
            $row('PEN-1', 'I5', 'A'), // no bulk UOM: rejected
        ]);
        $this->actingAs($this->user)
            ->post('/stock-ins/import', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('in.csv', $csv)])
            ->assertOk()->assertJson(['imported' => 4])->assertJsonCount(1, 'errors');

        $this->assertSame(2, StockPack::count());
        $boxA = StockIn::where('barcode', 'I1')->value('stock_pack_id');
        $this->assertSame($boxA, StockIn::where('barcode', 'I2')->value('stock_pack_id'));
        $this->assertSame(2, StockPack::find($boxA)->units_per_pack);
        $this->assertNotSame($boxA, StockIn::where('barcode', 'I3')->value('stock_pack_id'));
        $this->assertNull(StockIn::where('barcode', 'I4')->value('stock_pack_id'));
        $this->assertFalse(StockIn::where('barcode', 'I5')->exists());
        $this->assertNull($plain->fresh()->bulk_uom);

        // A file made before box_no existed still imports as loose pieces.
        $old = $head."\n".substr($row('TIS-1', 'I6', ''), 0, -1);
        $this->actingAs($this->user)
            ->post('/stock-ins/import', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('old.csv', $old)])
            ->assertOk()->assertJson(['imported' => 1, 'errors' => []]);
    }

    public function test_box_labels_print_and_need_view_permission(): void
    {
        $this->actingAs($this->user)->post('/stock-ins', $this->payload());
        $first = StockIn::orderBy('id')->firstOrFail();

        foreach (['print-pack-barcodes', 'print-pack-qrcodes', 'print-barcodes'] as $action) {
            $response = $this->actingAs($this->user)->get("/stock-ins/{$first->id}/{$action}");
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'), $action);
        }

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get("/stock-ins/{$first->id}/print-pack-barcodes")->assertForbidden();
    }
}
