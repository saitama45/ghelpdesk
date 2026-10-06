<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Affected Assets picker on the ticket edit page. A ticket's item pins the search
 * to assets filed under the same category and sub-category on /assets, instead of
 * offering every unit in the store.
 */
class TicketAssetSearchCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Store $store;

    private User $tech;

    private Category $audio;

    private SubCategory $amplifier;

    private SubCategory $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Entity', 'code' => 'ALPHA', 'type' => 'Entity', 'is_active' => true]);
        $this->store = Store::create([
            'code' => 'ST001', 'name' => 'Store ST001', 'sector' => 1, 'area' => 'Test Area',
            'brand' => 'Alpha Entity', 'class' => 'Regular', 'is_active' => true, 'company_id' => $this->company->id,
        ]);
        $this->tech = User::factory()->create(['company_id' => $this->company->id]);

        $this->audio = Category::create(['name' => 'ASSET-Audio', 'is_active' => true]);
        $this->amplifier = SubCategory::create(['name' => 'ASSET-Amplifier', 'is_active' => true]);
        $this->speaker = SubCategory::create(['name' => 'ASSET-Speaker', 'is_active' => true]);

        // Two fixed units and one consumable, all sitting at the ticket's store.
        $this->unit($this->asset('AMP-001', 'Fixed', $this->amplifier), 'SN-AMP');
        $this->unit($this->asset('SPK-001', 'Fixed', $this->speaker), 'SN-SPK');
        $this->stock($this->asset('CBL-001', 'Consumables', $this->amplifier), 5);
    }

    public function test_search_without_an_item_stays_store_wide(): void
    {
        $out = $this->search();

        $this->assertSame(['AMP-001', 'CBL-001', 'SPK-001'], $this->codes($out));
        $this->assertNull($out['category_filter']);
    }

    public function test_item_pins_the_search_to_its_category_and_sub_category(): void
    {
        $out = $this->search(['item_id' => $this->item('Amplifier issue', $this->audio, $this->amplifier)->id]);

        // The speaker unit shares the category but not the sub-category — it is out.
        $this->assertSame(['AMP-001', 'CBL-001'], $this->codes($out));
        $this->assertSame(['category' => 'ASSET-Audio', 'sub_category' => 'ASSET-Amplifier'], $out['category_filter']);
        $this->assertSame(5, collect($out['results'])->firstWhere('item_code', 'CBL-001')['soh_at_store']);

        $speakers = $this->search(['item_id' => $this->item('Speaker issue', $this->audio, $this->speaker)->id]);
        $this->assertSame(['SPK-001'], $this->codes($speakers));
    }

    public function test_the_typed_term_still_narrows_inside_the_item_category(): void
    {
        $item = $this->item('Amplifier issue', $this->audio, $this->amplifier);

        $this->assertSame(['AMP-001'], $this->codes($this->search(['item_id' => $item->id, 'q' => 'SN-AMP'])));
        // A speaker serial is at the store, but not in this ticket's category.
        $this->assertSame([], $this->codes($this->search(['item_id' => $item->id, 'q' => 'SN-SPK'])));
    }

    public function test_asset_category_with_no_matching_sub_category_lists_nothing(): void
    {
        $microphone = SubCategory::create(['name' => 'ASSET-Microphone', 'is_active' => true]);
        $out = $this->search(['item_id' => $this->item('Microphone issue', $this->audio, $microphone)->id]);

        // Same category, but nothing is filed under that sub-category: no fallback to "all".
        $this->assertSame([], $this->codes($out));
        $this->assertSame('ASSET-Microphone', $out['category_filter']['sub_category']);
    }

    public function test_item_without_a_sub_category_matches_the_whole_category(): void
    {
        $out = $this->search(['item_id' => $this->item('Audio issue', $this->audio, null)->id]);

        $this->assertSame(['AMP-001', 'CBL-001', 'SPK-001'], $this->codes($out));
        $this->assertSame(['category' => 'ASSET-Audio', 'sub_category' => null], $out['category_filter']);
    }

    public function test_item_in_a_ticket_only_category_does_not_block_tagging(): void
    {
        // No asset is filed under this category, so it cannot narrow anything — the
        // picker keeps offering the store's units instead of going permanently empty.
        $sap = Category::create(['name' => 'SAP Requests', 'is_active' => true]);
        $vendor = SubCategory::create(['name' => 'New Vendor', 'is_active' => true]);

        $out = $this->search(['item_id' => $this->item('New vendor request', $sap, $vendor)->id]);

        $this->assertSame(['AMP-001', 'CBL-001', 'SPK-001'], $this->codes($out));
        $this->assertNull($out['category_filter']);

        // An unknown or malformed item id is ignored the same way.
        $this->assertCount(3, $this->search(['item_id' => 999999])['results']);
        $this->assertCount(3, $this->search(['item_id' => 'abc'])['results']);
    }

    private function search(array $params = []): array
    {
        return $this->actingAs($this->tech)
            ->getJson(route('reports.inventory.assets-search', ['store_id' => $this->store->id] + $params))
            ->assertOk()
            ->json();
    }

    /** Item codes in the result list, sorted so the assertion ignores list order. */
    private function codes(array $out): array
    {
        return collect($out['results'])->pluck('item_code')->sort()->values()->all();
    }

    private function item(string $name, Category $category, ?SubCategory $subCategory): Item
    {
        return Item::create([
            'name' => $name,
            'category_id' => $category->id,
            'sub_category_id' => $subCategory?->id,
            'concern_type' => 'Incident',
            'is_active' => true,
        ]);
    }

    private function asset(string $code, string $type, SubCategory $subCategory): Asset
    {
        $asset = Asset::create([
            'item_code' => $code,
            'category_id' => $this->audio->id,
            'sub_category_id' => $subCategory->id,
            'brand' => 'Acme',
            'model' => $code,
            'type' => $type,
            'is_active' => true,
        ]);

        return $this->stamp($asset);
    }

    private function unit(Asset $asset, string $serial, int $quantity = 1): StockIn
    {
        return $this->stamp(StockIn::create([
            'receive_date' => now()->toDateString(),
            'destination_location' => $this->store->code,
            'status' => 'Posted',
            'asset_id' => $asset->id,
            'asset_type' => $asset->type,
            'quantity' => $quantity,
            'serial_no' => $serial,
            'barcode' => "BC-{$serial}",
        ]));
    }

    /** Put consumable stock on hand at the store: a posted stock-in plus its ledger row. */
    private function stock(Asset $asset, int $quantity): void
    {
        $stockIn = $this->unit($asset, "LOT-{$asset->item_code}", $quantity);

        $this->stamp(InventoryTransaction::create([
            'asset_id' => $asset->id,
            'location' => $this->store->code,
            'transaction_type' => 'Stock In',
            'quantity' => $quantity,
            'reference_type' => StockIn::class,
            'reference_id' => $stockIn->id,
        ]));
    }

    /**
     * Stamp a record with its owning entity. company_id is not fillable on the
     * entity-scoped inventory models — the app sets it from a creating listener on
     * real requests — so a fixture has to do it or the rows are invisible to the user.
     */
    private function stamp($model)
    {
        if (Schema::hasColumn($model->getTable(), 'company_id')) {
            $model->forceFill(['company_id' => $this->company->id])->save();
        }

        return $model;
    }
}
