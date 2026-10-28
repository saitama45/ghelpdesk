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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Posting is one-shot. A transfer posted twice (TRF-20260820-KMPN1Z, 8s apart) deducted its
 * origin twice and opened a second receiving, so SKU100075 read 0 at CFE I-WAREHOUSE after a
 * +1 stock-in. Covers the post guards and the migration that voids such past double posts.
 */
class StockPostingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['stock_ins.view', 'stock_ins.create', 'stock_ins.post', 'stock_transfers.view',
            'stock_transfers.create', 'stock_transfers.post', 'stock_receivings.post'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions);

        $category = Category::create(['name' => 'Power', 'is_active' => true]);
        $this->asset = Asset::create([
            'item_code' => 'SKU100075', 'category_id' => $category->id, 'type' => 'Fixed', 'is_active' => true,
        ]);

        $piece = fn (string $code) => [
            'asset_id' => $this->asset->id, 'serial_no' => "SN-{$code}", 'barcode' => $code, 'qrcode' => "QR {$code}",
            'asset_type' => 'New', 'is_allocation' => false, 'warranty_months' => 0, 'eol_months' => 0,
            'cost' => 0, 'price' => 0, 'destination_location' => 'WH',
        ];
        $this->actingAs($this->user)->post('/stock-ins', [
            'receive_date' => '2026-08-20', 'dr_no' => 'TAS-046', 'status' => 'For Posting', 'quantity' => 2,
            'entries' => [$piece('P1'), $piece('P2')],
        ])->assertSessionHasNoErrors();
    }

    protected function soh(string $location): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger()
            ->where('inventory_transactions.asset_id', $this->asset->id)
            ->where('inventory_transactions.location', $location)
            ->sum('inventory_transactions.quantity');
    }

    protected function postStockIn(): void
    {
        $this->actingAs($this->user)->post('/stock-ins/'.StockIn::min('id').'/post')->assertSessionHasNoErrors();
    }

    /** Both pieces WH -> ST1, posted once. */
    protected function postTransfer(): StockTransfer
    {
        $this->postStockIn();
        $entry = fn (StockIn $unit) => [
            'source_stock_in_id' => $unit->id, 'serial_no' => $unit->serial_no, 'barcode' => $unit->barcode,
            'qrcode' => $unit->qrcode, 'asset_type' => 'New', 'is_allocation' => false,
            'warranty_months' => 0, 'eol_months' => 0, 'cost' => 0, 'price' => 0,
        ];
        $this->actingAs($this->user)->post('/stock-transfers', [
            'transfer_date' => '2026-08-20', 'transfer_no' => 'TRF-1', 'origin_location' => 'WH',
            'destination_location' => 'ST1', 'status' => 'For Posting',
            'asset_transfers' => [[
                'asset_id' => $this->asset->id, 'quantity' => 2,
                'entries' => StockIn::orderBy('id')->get()->map($entry)->all(),
            ]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::orderBy('id')->first();
        $this->actingAs($this->user)->post("/stock-transfers/{$transfer->id}/post")->assertSessionHas('success');

        return $transfer;
    }

    /** What the unguarded post() wrote on a second post: another Transfer Out and receiving per piece. */
    protected function replayOldSecondPost(string $receivingStatus): void
    {
        foreach (StockTransfer::orderBy('id')->get() as $item) {
            InventoryTransaction::create([
                'asset_id' => $item->asset_id, 'reference_type' => StockTransfer::class, 'reference_id' => $item->id,
                'location' => 'WH', 'transaction_type' => 'Transfer Out', 'quantity' => -1,
            ]);
            $receiving = StockReceiving::create([
                'stock_transfer_id' => $item->id, 'receiving_no' => 'RCV-DUP', 'receiving_date' => '2026-08-20',
                'origin_location' => 'WH', 'destination_location' => 'ST1', 'asset_id' => $item->asset_id,
                'source_stock_in_id' => $item->source_stock_in_id, 'barcode' => $item->barcode, 'qrcode' => $item->qrcode,
                'asset_type' => 'New', 'is_allocation' => false, 'warranty_months' => 0, 'eol_months' => 0,
                'cost' => 0, 'price' => 0, 'transferred_quantity' => 1, 'received_quantity' => 1,
                'condition' => 'Good', 'status' => $receivingStatus,
            ]);
            if ($receivingStatus === 'Received') {
                InventoryTransaction::create([
                    'asset_id' => $item->asset_id, 'reference_type' => StockReceiving::class, 'reference_id' => $receiving->id,
                    'location' => 'ST1', 'transaction_type' => 'Transfer In', 'quantity' => 1,
                ]);
            }
        }
    }

    protected function runRepair(): void
    {
        (require database_path('migrations/2026_10_28_000001_void_duplicate_stock_transfer_posts.php'))->up();
    }

    public function test_posting_a_transfer_twice_deducts_the_origin_once(): void
    {
        $transfer = $this->postTransfer();

        $this->actingAs($this->user)->post("/stock-transfers/{$transfer->id}/post")->assertSessionHas('error');

        $this->assertSame(0, $this->soh('WH'));
        $this->assertSame(2, InventoryTransaction::where('transaction_type', 'Transfer Out')->count());
        $this->assertSame(2, StockReceiving::count());
    }

    public function test_posting_a_stock_in_twice_records_the_ledger_once(): void
    {
        $this->postStockIn();

        $this->actingAs($this->user)->post('/stock-ins/'.StockIn::min('id').'/post')->assertSessionHas('error');

        $this->assertSame(2, InventoryTransaction::where('transaction_type', 'Stock In')->count());
        $this->assertSame(2, $this->soh('WH'));
    }

    public function test_repair_voids_a_received_duplicate_post_without_deleting_it(): void
    {
        $this->postTransfer();
        $this->actingAs($this->user)->post('/stock-receivings/'.StockReceiving::min('id').'/post')->assertSessionHasNoErrors();
        $this->replayOldSecondPost('Received');
        $this->assertSame([-2, 4], [$this->soh('WH'), $this->soh('ST1')]);
        $ledgerRows = InventoryTransaction::count();

        $this->runRepair();

        $this->assertSame([0, 2], [$this->soh('WH'), $this->soh('ST1')]);
        $this->assertSame($ledgerRows, InventoryTransaction::count());
        // The duplicate stays Received with its quantity: zero would read as "never arrived".
        $duplicate = StockReceiving::where('receiving_no', 'RCV-DUP')->firstOrFail();
        $this->assertSame(['Received', 1], [$duplicate->status, (int) $duplicate->received_quantity]);
        $this->assertStringStartsWith('Voided: duplicate of RCV-', $duplicate->remarks);
        $this->assertSame(['Received'], StockTransfer::pluck('status')->unique()->values()->all());

        $this->runRepair();
        $this->assertSame([0, 2], [$this->soh('WH'), $this->soh('ST1')]);
    }

    public function test_repair_closes_a_pending_duplicate_receiving(): void
    {
        $this->postTransfer();
        $this->actingAs($this->user)->post('/stock-receivings/'.StockReceiving::min('id').'/post')->assertSessionHasNoErrors();
        $this->replayOldSecondPost('For Receiving');

        $this->runRepair();

        $this->assertSame([0, 2], [$this->soh('WH'), $this->soh('ST1')]);
        $this->assertSame(['Declined'], StockReceiving::where('receiving_no', 'RCV-DUP')->pluck('status')->unique()->values()->all());
    }
}
