<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BrandHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandHealthTopListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_lists_rank_open_tickets_per_brand(): void
    {
        [$alpha, $beta] = $this->fixture();

        $data = app(BrandHealthService::class)->build(User::factory()->create());
        $brands = collect($data['brands'])->keyBy('code');

        // Alpha: 3 POS + 1 Network + 1 unspecified open tickets (closed ones excluded).
        $alphaSubs = collect($brands['ALPHA']['top_subcategories']);
        $this->assertSame('POS', $alphaSubs->first()['name']); // ranked by open volume
        $this->assertSame(['Network' => 1, 'POS' => 3, 'Unspecified' => 1], $alphaSubs->pluck('count', 'name')->sortKeys()->all());
        $this->assertSame('none', $alphaSubs->firstWhere('name', 'Unspecified')['id']);

        // Stores rank by open volume; a store with no open tickets never appears.
        $alphaStores = collect($brands['ALPHA']['top_stores']);
        $this->assertSame(['ALP-1', 'ALP-2'], $alphaStores->pluck('code')->all());
        $this->assertSame([4, 1], $alphaStores->pluck('count')->all());
        $this->assertSame(1, $alphaStores->first()['workflow']['wsp']);

        // Beta's lists are its own — no bleed across brands.
        $this->assertSame(['Network'], collect($brands['BETA']['top_subcategories'])->pluck('name')->all());
        $this->assertSame(['BET-1'], collect($brands['BETA']['top_stores'])->pluck('code')->all());

        // Summary totals span both brands.
        $totals = collect($data['totals']['top_subcategories'])->pluck('count', 'name');
        $this->assertSame(3, $totals['POS']);
        $this->assertSame(2, $totals['Network']);
        $this->assertCount(3, $data['totals']['top_stores']);

        $this->assertNotNull($alpha->id);
        $this->assertNotNull($beta->id);
    }

    public function test_build_follows_the_entity_selection(): void
    {
        [$alpha] = $this->fixture();

        $data = app(BrandHealthService::class)->build(User::factory()->create(), null, [$alpha->id]);

        // Beta is a brand, but it sits outside the selection — its stores and tickets
        // must not leak into the tab that the other entity-scoped tabs describe.
        $this->assertSame(['ALPHA'], collect($data['brands'])->pluck('code')->all());
        $this->assertSame(5, $data['totals']['active_tickets']);
        $this->assertSame(['ALP-1', 'ALP-2'], collect($data['totals']['top_stores'])->pluck('code')->all());
        $this->assertTrue($data['entity_scoped']);
        $this->assertSame(1, $data['brands_outside_scope']);

        // An empty selection means "no accessible entity" — that yields nothing.
        $empty = app(BrandHealthService::class)->build(User::factory()->create(), null, []);
        $this->assertSame([], $empty['brands']);
        $this->assertSame(2, $empty['brands_outside_scope']);
        $this->assertSame(0, $empty['totals']['active_tickets']);
    }

    public function test_drill_down_endpoint_filters_by_sub_category_and_store(): void
    {
        [$alpha] = $this->fixture();

        $viewer = User::factory()->create(['company_id' => $alpha->id]);
        $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('tickets.view', 'web'));

        $posId = SubCategory::where('name', 'POS')->value('id');

        $bySubCategory = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['brand_id' => $alpha->id, 'sub_category_id' => $posId]))
            ->assertOk()
            ->json();

        $this->assertSame(3, $bySubCategory['count']);
        $this->assertSame(['Terminal Down'], collect($bySubCategory['items'])->pluck('name')->all());
        $this->assertSame('Incident', $bySubCategory['items'][0]['concern_type']);

        $byStore = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['store_id' => Store::where('code', 'ALP-1')->value('id')]))
            ->assertOk()
            ->json();

        $this->assertSame(4, $byStore['count']);
        $this->assertSame(['[ALP-1] Store ALP-1'], collect($byStore['tickets'])->pluck('store')->unique()->all());

        // 'none' means "no sub-category", not "sub-category 0".
        $unspecified = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['brand_id' => $alpha->id, 'sub_category_id' => 'none']))
            ->assertOk()
            ->json();

        $this->assertSame(1, $unspecified['count']);
        $this->assertSame(['Unspecified'], collect($unspecified['items'])->pluck('name')->all());
    }

    public function test_drill_down_cannot_reach_brands_outside_the_entity_selection(): void
    {
        [$alpha, $beta] = $this->fixture();

        // Viewer is scoped to Alpha; asking for Beta's brand must come back empty
        // rather than handing over another entity's tickets.
        $viewer = User::factory()->create(['company_id' => $alpha->id]);
        $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('tickets.view', 'web'));

        $out = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['brand_id' => $beta->id]))
            ->assertOk()
            ->json();

        $this->assertSame(0, $out['count']);

        // Same for a store id belonging to Beta.
        $betaStore = Store::where('code', 'BET-1')->value('id');
        $this->assertSame(0, $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['store_id' => $betaStore]))
            ->assertOk()
            ->json('count'));
    }

    public function test_drill_down_requires_ticket_view_permission(): void
    {
        $this->fixture();

        $this->actingAs(User::factory()->create())
            ->getJson(route('dashboard.brand-health.tickets'))
            ->assertForbidden();
    }

    /** @return array{0: Company, 1: Company} */
    private function fixture(): array
    {
        $alpha = $this->brand('Alpha Brand', 'ALPHA');
        $beta = $this->brand('Beta Brand', 'BETA');

        $pos = SubCategory::create(['name' => 'POS', 'is_active' => true]);
        $network = SubCategory::create(['name' => 'Network', 'is_active' => true]);
        $terminal = Item::create(['name' => 'Terminal Down', 'sub_category_id' => $pos->id, 'concern_type' => 'Incident', 'is_active' => true]);
        $link = Item::create(['name' => 'Slow Link', 'sub_category_id' => $network->id, 'concern_type' => 'Service Request', 'is_active' => true]);

        $alphaOne = $this->store($alpha, 'ALP-1');
        $alphaTwo = $this->store($alpha, 'ALP-2');
        $this->store($alpha, 'ALP-3'); // no open tickets → never in the top list
        $betaOne = $this->store($beta, 'BET-1');

        $this->ticket($alphaOne, 'open', $pos, $terminal);
        $this->ticket($alphaOne, 'in_progress', $pos, $terminal);
        $this->ticket($alphaOne, 'waiting_service_provider', $pos, $terminal);
        $this->ticket($alphaOne, 'open', null, null);            // Unspecified sub-category
        $this->ticket($alphaTwo, 'open', $network, $link);
        $this->ticket($alphaTwo, 'closed', $pos, $terminal);     // terminal → excluded
        $this->ticket($betaOne, 'open', $network, $link);

        return [$alpha, $beta];
    }

    private function brand(string $name, string $code): Company
    {
        return Company::create(['name' => $name, 'code' => $code, 'type' => 'Brand', 'is_active' => true]);
    }

    private function store(Company $company, string $code): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => $company->name,
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $company->id,
        ]);
    }

    private function ticket(Store $store, string $status, ?SubCategory $subCategory, ?Item $item): Ticket
    {
        return Ticket::create([
            'title' => "{$store->code} {$status}",
            'description' => 'Brand health top-list fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'store_id' => $store->id,
            'company_id' => $store->company_id,
            'sub_category_id' => $subCategory?->id,
            'item_id' => $item?->id,
        ]);
    }
}
