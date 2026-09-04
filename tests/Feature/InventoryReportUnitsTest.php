<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\InventoryTransaction;
use App\Models\StampRedemption;
use App\Models\StampRedemptionUnit;
use App\Models\StockIn;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The clickable Stock on Hand figure on /reports/inventory: it opens the
 * individual units behind the number, with the codes staff scan.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class InventoryReportUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $staff;
    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Coffee Bean and Tea Leaf',
            'code' => 'CBTL',
            'type' => 'Entity',
            'is_active' => true,
        ]);

        $this->staff = User::factory()->create(['company_id' => $this->company->id]);
        $this->staff->givePermissionTo(Permission::findOrCreate('reports.inventory', 'web'));

        $category = Category::create(['name' => 'Loyalty Rewards', 'is_active' => true]);
        $this->asset = new Asset([
            'item_code' => 'SKU100081',
            'category_id' => $category->id,
            'description' => 'OREO TUMBLER',
            'type' => 'Fixed',
            'cost' => 1000,
            'is_active' => true,
        ]);
        $this->asset->setAttribute('company_id', $this->company->id);
        $this->asset->save();
    }

    private function stockUnit(int $number, string $location = 'CBTL EWM'): StockIn
    {
        $stockIn = new StockIn([
            'receive_date' => now()->toDateString(),
            'asset_id' => $this->asset->id,
            'quantity' => 1,
            'serial_no' => null,
            'barcode' => "SKU100081-{$number}",
            // The real printed label: the whole asset card, many lines long.
            'qrcode' => "Item Code: SKU100081\nDescription: OREO TUMBLER\n"
                . "Barcode: SKU100081-{$number}\nDestination Location: {$location}",
            'destination_location' => $location,
            'status' => 'Posted',
            'asset_type' => 'New',
            'cost' => 1000,
            'price' => 1000,
        ]);
        $stockIn->setAttribute('company_id', $this->company->id);
        $stockIn->save();

        InventoryTransaction::create([
            'asset_id' => $this->asset->id,
            'location' => $location,
            'transaction_type' => 'Stock In',
            'quantity' => 1,
            'reference_type' => StockIn::class,
            'reference_id' => $stockIn->id,
            'created_by' => $this->staff->id,
            'updated_by' => $this->staff->id,
        ]);

        return $stockIn;
    }

    private function get_units(string $location = 'CBTL EWM')
    {
        return $this->actingAs($this->staff)
            ->withSession([CompanyContext::SESSION_KEY => $this->company->id])
            ->getJson(route('reports.inventory.units', $this->asset->id)
                . '?location=' . urlencode($location));
    }

    public function test_it_lists_the_units_behind_the_stock_on_hand_figure(): void
    {
        collect(range(1, 3))->each(fn ($n) => $this->stockUnit($n));

        $response = $this->get_units();

        $response->assertOk();
        $response->assertJsonPath('soh', 3);
        $response->assertJsonPath('unit_count', 3);
        $response->assertJsonPath('asset.item_code', 'SKU100081');
        $response->assertJsonPath('location', 'CBTL EWM');

        $barcodes = collect($response->json('units'))->pluck('barcode')->all();
        $this->assertEqualsCanonicalizing(
            ['SKU100081-1', 'SKU100081-2', 'SKU100081-3'],
            $barcodes,
        );
        $this->assertStringContainsString(
            'Item Code: SKU100081',
            $response->json('units.0.qrcode'),
        );
    }

    public function test_a_unit_already_given_away_as_a_reward_is_not_listed(): void
    {
        $kept = $this->stockUnit(1);
        $spent = $this->stockUnit(2);

        // Real rows: the redemption tables carry foreign keys.
        $customer = Customer::create([
            'name' => 'Gen', 'email' => 'gen@example.com', 'is_active' => true,
        ]);
        $program = StampProgram::create([
            'name' => 'CBTL Campaign', 'year' => 2026,
            'stamps_required' => 12, 'is_active' => true,
        ]);
        $card = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 12,
            'status' => 'redeemed',
        ]);

        $redemption = StampRedemption::create([
            'stamp_card_id' => $card->id,
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'asset_id' => $this->asset->id,
            'location' => 'CBTL EWM',
            'quantity' => 1,
            'created_by' => $this->staff->id,
            'updated_by' => $this->staff->id,
        ]);
        StampRedemptionUnit::create([
            'stamp_redemption_id' => $redemption->id,
            'stock_in_id' => $spent->id,
            'barcode' => $spent->barcode,
            'qrcode' => $spent->qrcode,
        ]);

        $response = $this->get_units();

        $response->assertOk();
        $this->assertSame(
            [$kept->id],
            collect($response->json('units'))->pluck('stock_in_id')->all(),
        );
    }

    public function test_a_redemption_that_never_recorded_its_unit_is_counted_and_explained(): void
    {
        // The real shape of the confusing case: two rewards were handed out
        // of this location, but only the later redemption recorded WHICH unit
        // it took (per-unit recording was added between the two). The ledger
        // is down 2; only one unit can be named as gone. The endpoint must
        // report the shortfall so the page can explain the 5-vs-6 instead of
        // leaving it as a mystery.
        $units = collect(range(1, 3))->map(fn ($n) => $this->stockUnit($n));

        $customer = Customer::create([
            'name' => 'Gen', 'email' => 'gen@example.com', 'is_active' => true,
        ]);
        $program = StampProgram::create([
            'name' => 'CBTL Campaign', 'year' => 2026,
            'stamps_required' => 12, 'is_active' => true,
        ]);

        $makeRedemption = function () use ($customer, $program) {
            $card = StampCard::create([
                'customer_id' => $customer->id,
                'stamp_program_id' => $program->id,
                'stamps_count' => 12,
                'status' => 'redeemed',
            ]);

            $redemption = StampRedemption::create([
                'stamp_card_id' => $card->id,
                'customer_id' => $customer->id,
                'stamp_program_id' => $program->id,
                'asset_id' => $this->asset->id,
                'location' => 'CBTL EWM',
                'quantity' => 1,
                'created_by' => $this->staff->id,
                'updated_by' => $this->staff->id,
            ]);

            InventoryTransaction::create([
                'asset_id' => $this->asset->id,
                'location' => 'CBTL EWM',
                'transaction_type' => 'Stamp Redemption',
                'quantity' => -1,
                'reference_type' => StampRedemption::class,
                'reference_id' => $redemption->id,
                'created_by' => $this->staff->id,
                'updated_by' => $this->staff->id,
            ]);

            return $redemption;
        };

        // Older redemption: deducted stock, never said which unit.
        $makeRedemption();

        // Newer redemption: recorded its unit properly.
        $withUnit = $makeRedemption();
        StampRedemptionUnit::create([
            'stamp_redemption_id' => $withUnit->id,
            'stock_in_id' => $units[0]->id,
            'barcode' => $units[0]->barcode,
            'qrcode' => $units[0]->qrcode,
        ]);

        $response = $this->get_units();

        $response->assertOk();
        // 3 in, 2 redeemed away.
        $response->assertJsonPath('soh', 1);
        // Only the properly recorded one can be removed from the list.
        $response->assertJsonPath('unit_count', 2);
        // …and the difference is explained rather than left dangling.
        $response->assertJsonPath('unattributed_redemptions', 1);
        $this->assertCount(1, $response->json('unattributed_redeemed_at'));
    }

    public function test_units_at_another_location_are_not_listed(): void
    {
        $here = $this->stockUnit(1);
        $this->stockUnit(2, 'CBTL ABZ');

        $response = $this->get_units();

        $response->assertOk();
        $response->assertJsonPath('soh', 1);
        $this->assertSame(
            [$here->id],
            collect($response->json('units'))->pluck('stock_in_id')->all(),
        );
    }

    public function test_bulk_stock_reports_its_real_unit_count_instead_of_padding(): void
    {
        // Counted (not serialised) stock: ONE posted stock-in row carrying a
        // quantity of 4. The ledger says 4; only one unit row exists to name.
        // The endpoint must report both numbers honestly — the page turns the
        // difference into a warning rather than inventing three more units.
        $bulk = new StockIn([
            'receive_date' => now()->toDateString(),
            'asset_id' => $this->asset->id,
            'quantity' => 4,
            'barcode' => 'SKU100081-BULK',
            'destination_location' => 'CBTL EWM',
            'status' => 'Posted',
            'asset_type' => 'New',
            'cost' => 1000,
            'price' => 1000,
        ]);
        $bulk->setAttribute('company_id', $this->company->id);
        $bulk->save();

        InventoryTransaction::create([
            'asset_id' => $this->asset->id,
            'location' => 'CBTL EWM',
            'transaction_type' => 'Stock In',
            'quantity' => 4,
            'reference_type' => StockIn::class,
            'reference_id' => $bulk->id,
            'created_by' => $this->staff->id,
            'updated_by' => $this->staff->id,
        ]);

        $response = $this->get_units();

        $response->assertOk();
        $response->assertJsonPath('soh', 4);
        $response->assertJsonPath('unit_count', 1);
        $response->assertJsonPath('units.0.barcode', 'SKU100081-BULK');
    }

    public function test_a_location_is_required(): void
    {
        $this->stockUnit(1);

        $this->actingAs($this->staff)
            ->withSession([CompanyContext::SESSION_KEY => $this->company->id])
            ->getJson(route('reports.inventory.units', $this->asset->id))
            ->assertStatus(422);
    }

    public function test_it_is_gated_by_the_inventory_report_permission(): void
    {
        $this->stockUnit(1);
        $nobody = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($nobody)
            ->withSession([CompanyContext::SESSION_KEY => $this->company->id])
            ->getJson(route('reports.inventory.units', $this->asset->id)
                . '?location=' . urlencode('CBTL EWM'))
            ->assertForbidden();
    }
}
