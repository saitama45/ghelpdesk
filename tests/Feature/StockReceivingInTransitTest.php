<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Unserved = still in transit": a line received as 0 is not closed on post. It stays open
 * with a 'Receiving Unserved' history row and its transfer Posted, until the source either
 * returns it to stock (origin credited) or writes it off (origin stays deducted, the piece is
 * on no shelf). Contract shared with linkportal.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class StockReceivingInTransitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = ['stock_transfers.post', 'stock_receivings.post', 'reports.inventory'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user = User::factory()->create(['name' => 'Hub Receiver']);
        $this->user->givePermissionTo($permissions);

        $category = Category::create(['name' => 'Equipment', 'is_active' => true]);
        $this->asset = Asset::create([
            'item_code' => 'AST-001', 'category_id' => $category->id, 'type' => 'Fixed',
            'cost' => 100, 'is_active' => true,
        ]);

        // Three posted units at WH, transferred to ST1 and posted (origin deducted).
        foreach (['U1', 'U2', 'U3'] as $barcode) {
            $unit = StockIn::create([
                'receive_date' => '2026-10-01', 'dr_no' => 'DR-1', 'origin_location' => 'SUPPLIER',
                'destination_location' => 'WH', 'status' => 'Posted', 'asset_id' => $this->asset->id,
                'quantity' => 1, 'barcode' => $barcode, 'qrcode' => "QR-{$barcode}", 'asset_type' => 'New',
                'cost' => 100, 'price' => 100,
            ]);
            InventoryTransaction::create([
                'asset_id' => $this->asset->id, 'location' => 'WH', 'transaction_type' => 'Stock In',
                'quantity' => 1, 'reference_type' => StockIn::class, 'reference_id' => $unit->id,
            ]);
            StockTransfer::create([
                'transfer_date' => '2026-10-02', 'transfer_no' => 'TRF-1', 'origin_location' => 'WH',
                'destination_location' => 'ST1', 'status' => 'For Posting', 'asset_id' => $this->asset->id,
                'source_stock_in_id' => $unit->id, 'quantity' => 1, 'barcode' => $barcode,
                'qrcode' => "QR-{$barcode}", 'asset_type' => 'New', 'cost' => 100, 'price' => 100,
            ]);
        }

        $this->actingAs($this->user)
            ->post(route('stock-transfers.post', StockTransfer::min('id')))
            ->assertSessionHasNoErrors();
    }

    private function line(string $barcode): StockReceiving
    {
        return StockReceiving::where('barcode', $barcode)->firstOrFail();
    }

    private function transferOf(string $barcode): StockTransfer
    {
        return StockTransfer::findOrFail($this->line($barcode)->stock_transfer_id);
    }

    /** Receive U1 only (U2, U3 unscanned, as the edit modal sends them) and post. */
    private function postWithUnserved(): void
    {
        $entries = collect(['U1', 'U2', 'U3'])->map(fn ($barcode) => [
            'id' => $this->line($barcode)->id,
            'received_quantity' => $barcode === 'U1' ? 1 : 0,
            'condition' => 'Good',
            'damage_notes' => null,
        ])->all();

        $this->actingAs($this->user)->put(route('stock-receivings.update', $this->line('U1')), [
            'asset_transfers' => [['asset_id' => $this->asset->id, 'entries' => $entries]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post(route('stock-receivings.post', $this->line('U1')))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Receiving posted: 1 unit(s) received, 2 unserved unit(s) left in transit.');
    }

    private function ledger(string $type, ?string $barcode = null): Collection
    {
        return InventoryTransaction::query()
            ->where('transaction_type', $type)
            ->when($barcode, fn ($q) => $q->where('reference_type', StockReceiving::class)
                ->where('reference_id', $this->line($barcode)->id))
            ->get();
    }

    private function soh(string $location): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger()
            ->where('inventory_transactions.asset_id', $this->asset->id)
            ->where('inventory_transactions.location', $location)
            ->sum('inventory_transactions.quantity');
    }

    /** Barcodes of the units fixedUnitsCurrentlyAt places at a location. */
    private function unitsAt(string $location): array
    {
        $locator = new class
        {
            use LocatesInventoryUnits;

            public function at(array $variants): Collection
            {
                return $this->fixedUnitsCurrentlyAt($variants);
            }
        };

        return $locator->at([$location])->pluck('barcode')->sort()->values()->all();
    }

    public function test_post_leaves_unreceived_lines_in_transit_with_one_unserved_history_row(): void
    {
        $this->postWithUnserved();

        $received = $this->line('U1');
        $this->assertSame('Received', $received->status);
        $this->assertSame('Received', $this->transferOf('U1')->status);
        $this->assertCount(1, $this->ledger('Transfer In', 'U1'));

        foreach (['U2', 'U3'] as $barcode) {
            $line = $this->line($barcode);
            $this->assertTrue($line->isInTransit(), "{$barcode} should be in transit");
            $this->assertSame('For Receiving', $line->status);
            $this->assertSame('Missing', $line->condition);
            $this->assertSame(0, $line->received_quantity);
            $this->assertSame('Hub Receiver', $line->received_by);
            $this->assertNull($line->received_at);
            $this->assertSame('Posted', $this->transferOf($barcode)->status);
            $this->assertCount(0, $this->ledger('Transfer In', $barcode));

            $unserved = $this->ledger('Receiving Unserved', $barcode);
            $this->assertCount(1, $unserved);
            $this->assertSame(0, $unserved->first()->quantity);
            $this->assertSame('ST1', $unserved->first()->location);
            $this->assertSame($this->user->id, $unserved->first()->created_by);
        }

        // Posting the open lines again changes nothing and does not log them twice.
        $this->actingAs($this->user)->post(route('stock-receivings.post', $this->line('U2')))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->ledger('Receiving Unserved'));
        $this->assertTrue($this->line('U2')->isInTransit());

        // The listing flags the open group, and stock stays where the ledger says.
        $groups = collect($this->actingAs($this->user)->get(route('stock-receivings.index'))
            ->assertOk()->viewData('page')['props']['stockReceivings']['data']);
        $open = $groups->firstWhere('status', 'For Receiving');
        $this->assertSame(2, (int) $open['in_transit_count']);
        $this->assertSame(2, (int) $open['record_count']);
        $this->assertSame(0, (int) $groups->firstWhere('status', 'Received')['in_transit_count']);

        $this->assertSame(0, $this->soh('WH'));
        $this->assertSame(1, $this->soh('ST1'));
        $this->assertSame(['U1'], $this->unitsAt('ST1'));
    }

    public function test_a_late_arrival_is_received_on_a_later_post_and_is_no_longer_missing(): void
    {
        $this->postWithUnserved();

        $this->actingAs($this->user)->put(route('stock-receivings.update', $this->line('U2')), [
            'asset_transfers' => [['asset_id' => $this->asset->id, 'entries' => [
                ['id' => $this->line('U2')->id, 'received_quantity' => 1, 'condition' => 'Missing', 'damage_notes' => null],
                ['id' => $this->line('U3')->id, 'received_quantity' => 0, 'condition' => 'Missing', 'damage_notes' => null],
            ]]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('stock-receivings.post', $this->line('U2')))->assertSessionHasNoErrors();

        $late = $this->line('U2');
        $this->assertSame('Received', $late->status);
        $this->assertSame('Good', $late->condition);
        $this->assertSame('Received', $this->transferOf('U2')->status);
        $this->assertCount(1, $this->ledger('Transfer In', 'U2'));
        $this->assertCount(1, $this->ledger('Receiving Unserved', 'U2')); // history kept
        $this->assertTrue($this->line('U3')->isInTransit());
        $this->assertSame(2, $this->soh('ST1'));
    }

    public function test_return_to_source_credits_the_origin_and_puts_the_unit_back_there(): void
    {
        $this->postWithUnserved();

        $this->actingAs($this->user)->post(route('stock-receivings.return-to-source', $this->line('U2')), [
            'reason' => 'Never loaded on the truck',
            'ids' => [$this->line('U2')->id],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 unserved unit(s) returned to WH. Origin inventory credited back.');

        $returned = $this->line('U2');
        $this->assertSame('Declined', $returned->status);
        $this->assertSame(0, $returned->received_quantity);
        $this->assertSame('Never loaded on the truck', $returned->remarks);
        $this->assertSame('Hub Receiver', $returned->received_by);
        $this->assertNotNull($returned->received_at);

        $declined = $this->ledger('Receiving Declined', 'U2');
        $this->assertCount(1, $declined);
        $this->assertSame(1, $declined->first()->quantity);
        $this->assertSame('WH', $declined->first()->location);

        $transfer = $this->transferOf('U2');
        $this->assertSame('Declined', $transfer->status);
        $this->assertNull($transfer->received_by);
        $this->assertNull($transfer->received_at);

        // ids narrowed the action: U3 is still in transit.
        $this->assertTrue($this->line('U3')->isInTransit());
        $this->assertSame('Posted', $this->transferOf('U3')->status);

        $this->assertSame(1, $this->soh('WH'));
        $this->assertSame(1, $this->soh('ST1'));
        $this->assertContains('U2', $this->unitsAt('WH'));
        $this->assertSame(['U1'], $this->unitsAt('ST1'));

        // The report's unit list agrees with the ledger: U2 is the one unit back on the WH
        // shelf (U3 is still on its Posted transfer, i.e. in transit).
        $units = $this->unitsReport('WH')->assertJsonPath('soh', 1)->assertJsonPath('unit_count', 1);
        $this->assertSame(['U2'], collect($units->json('units'))->pluck('barcode')->all());
    }

    private function unitsReport(string $location)
    {
        return $this->actingAs($this->user)
            ->getJson(route('reports.inventory.units', $this->asset->id).'?location='.urlencode($location))
            ->assertOk();
    }

    public function test_write_off_keeps_the_origin_deducted_and_the_unit_on_no_shelf(): void
    {
        $this->postWithUnserved();

        $this->actingAs($this->user)->post(route('stock-receivings.write-off', $this->line('U3')), [
            'reason' => 'Lost in transit, courier claim 42',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 unserved unit(s) written off as lost in transit. WH stays deducted.');

        foreach (['U2', 'U3'] as $barcode) {
            $line = $this->line($barcode);
            $this->assertSame('Received', $line->status);
            $this->assertSame(0, $line->received_quantity);
            $this->assertSame('Missing', $line->condition);
            $this->assertSame('Lost in transit, courier claim 42', $line->remarks);
            $this->assertSame('Hub Receiver', $line->received_by);
            $this->assertNotNull($line->received_at);

            $writtenOff = $this->ledger('Unserved Written Off', $barcode);
            $this->assertCount(1, $writtenOff);
            $this->assertSame(0, $writtenOff->first()->quantity);
            $this->assertSame('WH', $writtenOff->first()->location);

            $transfer = $this->transferOf($barcode);
            $this->assertSame('Received', $transfer->status);
            $this->assertSame('Hub Receiver', $transfer->received_by);
        }

        $this->assertSame(0, $this->soh('WH'));
        $this->assertSame(1, $this->soh('ST1'));
        // The transfers read Received, yet the pieces never arrived: on no shelf at all.
        $this->assertSame([], $this->unitsAt('WH'));
        $this->assertSame(['U1'], $this->unitsAt('ST1'));
        $units = $this->unitsReport('ST1')->assertJsonPath('soh', 1)->assertJsonPath('unit_count', 1);
        $this->assertSame(['U1'], collect($units->json('units'))->pluck('barcode')->all());

        // The Transaction Trail shows both history rows, as transfer records.
        $atOrigin = collect($this->actingAs($this->user)
            ->getJson(route('reports.inventory.history', $this->asset->id, false).'?location=WH')
            ->assertOk()->json('history'));
        $writeOffRow = $atOrigin->firstWhere('transaction_type', 'Unserved Written Off');
        $this->assertNotNull($writeOffRow);
        $this->assertSame(0, (int) $writeOffRow['total_quantity']);
        $this->assertSame('TRF-1', $writeOffRow['transfer_no']);
        $this->assertSame('Lost in transit, courier claim 42', $writeOffRow['remarks']);

        $atDestination = collect($this->actingAs($this->user)
            ->getJson(route('reports.inventory.history', $this->asset->id, false).'?location=ST1')
            ->assertOk()->json('history'));
        $unservedRow = $atDestination->firstWhere('transaction_type', 'Receiving Unserved');
        $this->assertNotNull($unservedRow);
        $this->assertSame('TRF-1', $unservedRow['transfer_no']);
    }

    public function test_only_in_transit_lines_can_be_resolved(): void
    {
        // Still pending (never posted): nothing is in transit.
        $this->actingAs($this->user)->post(route('stock-receivings.return-to-source', $this->line('U1')), [
            'reason' => 'Too early',
        ])->assertStatus(422);
        $this->actingAs($this->user)->post(route('stock-receivings.write-off', $this->line('U1')), [
            'reason' => 'Too early',
        ])->assertStatus(422);
        $this->assertCount(0, $this->ledger('Receiving Declined'));
        $this->assertCount(0, $this->ledger('Unserved Written Off'));

        $this->postWithUnserved();

        // A received line cannot be picked through `ids`.
        $this->actingAs($this->user)->post(route('stock-receivings.write-off', $this->line('U2')), [
            'reason' => 'Wrong line', 'ids' => [$this->line('U1')->id],
        ])->assertStatus(422);
        $this->assertSame('Received', $this->transferOf('U1')->status);
        $this->assertTrue($this->line('U2')->isInTransit());

        // A reason is required.
        $this->actingAs($this->user)->post(route('stock-receivings.return-to-source', $this->line('U2')), [
            'reason' => '   ',
        ])->assertSessionHasErrors('reason');
        $this->assertTrue($this->line('U2')->isInTransit());

        // Once resolved, a line cannot be resolved again.
        $this->actingAs($this->user)->post(route('stock-receivings.write-off', $this->line('U2')), [
            'reason' => 'Lost',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('stock-receivings.return-to-source', $this->line('U2')), [
            'reason' => 'Found it',
        ])->assertStatus(422);
        $this->assertCount(0, $this->ledger('Receiving Declined'));
        $this->assertCount(2, $this->ledger('Unserved Written Off'));
    }

    public function test_resolving_needs_the_post_permission(): void
    {
        $this->postWithUnserved();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('stock_receivings.view', 'web'));

        $this->actingAs($viewer)->post(route('stock-receivings.return-to-source', $this->line('U2')), [
            'reason' => 'Not mine to decide',
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('stock-receivings.write-off', $this->line('U2')), [
            'reason' => 'Not mine to decide',
        ])->assertForbidden();

        $this->assertTrue($this->line('U2')->isInTransit());
        $this->assertCount(0, $this->ledger('Receiving Declined'));
        $this->assertCount(0, $this->ledger('Unserved Written Off'));
    }

    public function test_a_receiving_with_lines_in_transit_cannot_be_deleted(): void
    {
        $this->postWithUnserved();

        $this->actingAs($this->user)->delete(route('stock-receivings.destroy', $this->line('U2')), [
            'delete_group' => true,
        ])->assertStatus(422);

        $this->assertTrue($this->line('U2')->isInTransit());
        $this->assertTrue($this->line('U3')->isInTransit());
    }
}
