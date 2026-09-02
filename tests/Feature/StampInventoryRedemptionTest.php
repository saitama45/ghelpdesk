<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\StockIn;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StampInventoryRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_inventory_with_posted_stock_is_available_and_redeemable_in_the_active_entity(): void
    {
        $cbtl = Company::create([
            'name' => 'Coffee Bean and Tea Leaf',
            'code' => 'CBTL',
            'type' => 'Entity',
            'is_active' => true,
        ]);

        $staff = User::factory()->create(['company_id' => $cbtl->id]);
        $staff->givePermissionTo([
            Permission::findOrCreate('stamps.view', 'web'),
            Permission::findOrCreate('stamps.redeem', 'web'),
            Permission::findOrCreate('reports.inventory', 'web'),
        ]);

        $category = Category::create(['name' => 'Loyalty Rewards', 'is_active' => true]);
        $asset = new Asset([
            'item_code' => 'SKU100081',
            'category_id' => $category->id,
            'description' => 'CBTL Tumbler',
            'type' => 'Fixed',
            'cost' => 1000,
            'is_active' => true,
        ]);
        $asset->setAttribute('company_id', $cbtl->id);
        $asset->save();

        $stockUnits = collect(range(1, 7))->map(function ($number) use ($asset, $cbtl, $staff) {
            $stockIn = new StockIn([
                'receive_date' => now()->toDateString(),
                'asset_id' => $asset->id,
                'quantity' => 1,
                'serial_no' => "CBTL-EWM-{$number}",
                'barcode' => "CBTL-BC-{$number}",
                'qrcode' => "CBTL-QR-{$number}",
                'destination_location' => 'CBTL EWM',
                'status' => 'Posted',
                'asset_type' => 'New',
                'cost' => 1000,
                'price' => 1000,
            ]);
            $stockIn->setAttribute('company_id', $cbtl->id);
            $stockIn->save();

            InventoryTransaction::create([
                'asset_id' => $asset->id,
                'location' => 'CBTL EWM',
                'transaction_type' => 'Stock In',
                'quantity' => 1,
                'reference_type' => StockIn::class,
                'reference_id' => $stockIn->id,
                'created_by' => $staff->id,
                'updated_by' => $staff->id,
            ]);

            return $stockIn;
        });

        // Stock with the same location text must not leak in from another entity.
        $otherCompany = Company::create([
            'name' => 'Other Entity',
            'code' => 'OTHER',
            'type' => 'Entity',
            'is_active' => true,
        ]);
        $otherAsset = new Asset([
            'item_code' => 'OTHER-REWARD',
            'category_id' => $category->id,
            'description' => 'Other Entity Reward',
            'type' => 'Fixed',
            'cost' => 500,
            'is_active' => true,
        ]);
        $otherAsset->setAttribute('company_id', $otherCompany->id);
        $otherAsset->save();

        $otherStockIn = new StockIn([
            'receive_date' => now()->toDateString(),
            'asset_id' => $otherAsset->id,
            'quantity' => 20,
            'destination_location' => 'CBTL EWM',
            'status' => 'Posted',
            'asset_type' => 'New',
            'cost' => 500,
            'price' => 500,
        ]);
        $otherStockIn->setAttribute('company_id', $otherCompany->id);
        $otherStockIn->save();

        InventoryTransaction::create([
            'asset_id' => $otherAsset->id,
            'location' => 'CBTL EWM',
            'transaction_type' => 'Stock In',
            'quantity' => 20,
            'reference_type' => StockIn::class,
            'reference_id' => $otherStockIn->id,
            'created_by' => $staff->id,
            'updated_by' => $staff->id,
        ]);

        $session = [CompanyContext::SESSION_KEY => $cbtl->id];

        $this->actingAs($staff)
            ->withSession($session)
            ->getJson(route('stamps.assets-at-location', ['location' => 'CBTL EWM']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $asset->id)
            ->assertJsonPath('0.type', 'Fixed')
            ->assertJsonPath('0.soh', 7);

        $this->actingAs($staff)
            ->withSession($session)
            ->getJson(route('stamps.assets.units-at-location', [
                'asset' => $asset->id,
                'location' => 'CBTL EWM',
            ]))
            ->assertOk()
            ->assertJsonCount(7)
            ->assertJsonPath('0.stock_in_id', $stockUnits->first()->id)
            ->assertJsonPath('0.barcode', 'CBTL-BC-1')
            ->assertJsonPath('0.qrcode', 'CBTL-QR-1');

        $customer = Customer::create([
            'name' => 'CBTL Member',
            'email' => 'member@example.com',
            'is_active' => true,
        ]);
        $program = StampProgram::create([
            'company_id' => $cbtl->id,
            'name' => 'CBTL Rewards',
            'year' => 2026,
            'stamps_required' => 10,
            'is_active' => true,
        ]);
        $card = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 10,
            'status' => 'completed',
            'completed_at' => now(),
            'created_by' => $staff->id,
            'updated_by' => $staff->id,
        ]);

        $this->actingAs($staff)
            ->withSession($session)
            ->post(route('stamps.cards.redeem', $card), [
                'asset_id' => $asset->id,
                'location' => 'CBTL EWM',
                'quantity' => 2,
                'stock_in_ids' => $stockUnits->take(2)->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('redeemed', $card->fresh()->status);
        $redemption = StampRedemption::where('stamp_card_id', $card->id)->firstOrFail();
        $this->assertDatabaseHas('stamp_redemption_units', [
            'stamp_redemption_id' => $redemption->id,
            'stock_in_id' => $stockUnits->first()->id,
            'serial_no' => 'CBTL-EWM-1',
            'barcode' => 'CBTL-BC-1',
            'qrcode' => 'CBTL-QR-1',
        ]);
        $this->assertDatabaseHas('stamp_redemption_units', [
            'stamp_redemption_id' => $redemption->id,
            'stock_in_id' => $stockUnits->get(1)->id,
            'barcode' => 'CBTL-BC-2',
            'qrcode' => 'CBTL-QR-2',
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'asset_id' => $asset->id,
            'location' => 'CBTL EWM',
            'transaction_type' => 'Stamp Redemption',
            'quantity' => -2,
            'reference_type' => StampRedemption::class,
            'reference_id' => $redemption->id,
        ]);

        $this->actingAs($staff)
            ->withSession($session)
            ->getJson(route('stamps.assets-at-location', ['location' => 'CBTL EWM']))
            ->assertOk()
            ->assertJsonPath('0.soh', 5);

        $historyResponse = $this->actingAs($staff)
            ->withSession($session)
            ->getJson(route('reports.inventory.history', [
                'asset' => $asset->id,
                'location' => 'CBTL EWM',
            ]))
            ->assertOk();

        $redemptionHistory = collect($historyResponse->json('history'))
            ->firstWhere('transaction_type', 'Stamp Redemption');
        $this->assertSame($redemption->id, $redemptionHistory['stamp_redemption_reference_id']);
        $this->assertSame($stockUnits->first()->id, $redemptionHistory['redeemed_units'][0]['stock_in_id']);
        $this->assertSame('CBTL-BC-1', $redemptionHistory['redeemed_units'][0]['barcode']);
        $this->assertSame('CBTL-QR-1', $redemptionHistory['redeemed_units'][0]['qrcode']);
        $this->assertSame($stockUnits->get(1)->id, $redemptionHistory['redeemed_units'][1]['stock_in_id']);
        $this->assertSame('CBTL-BC-2', $redemptionHistory['redeemed_units'][1]['barcode']);
        $this->assertSame('CBTL-QR-2', $redemptionHistory['redeemed_units'][1]['qrcode']);
    }
}
