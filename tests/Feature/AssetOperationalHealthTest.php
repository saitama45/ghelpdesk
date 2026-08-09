<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\ReferenceOption;
use App\Models\StockIn;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Ticket;
use App\Models\TicketAsset;
use App\Models\TicketSlaMetric;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AssetOperationalHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Asset Operational Health — the per-physical-unit RED/GREEN state machine.
 *
 * Health is derived, never stored, so each of these asserts the query result rather
 * than a persisted column: that is exactly what makes reopen / multi-ticket / untag
 * correct without a synchronization hook.
 */
class AssetOperationalHealthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Store $store;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Entity', 'code' => 'ALPHA', 'type' => 'Entity', 'is_active' => true]);
        $this->store = $this->store($this->company, 'ST001');

        // The six slide groups are already seeded by migration — reuse, don't duplicate.
        $group = $this->assetGroup('POS Systems');
        $category = Category::create(['name' => 'POS Hardware', 'asset_group_id' => $group->id, 'is_active' => true]);
        $subCategory = SubCategory::create(['name' => 'POS Terminal', 'is_active' => true]);

        $this->asset = Asset::create([
            'item_code' => 'PC-001',
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'brand' => 'Acme',
            'model' => 'Desktop PC',
            'type' => 'Fixed',
            'is_active' => true,
        ]);
        $this->stamp($this->asset);
    }

    /** 1. A posted fixed unit with no linked tickets starts OPERATIONAL. */
    public function test_deployed_unit_with_no_linked_tickets_is_operational(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);

        $data = $this->build();

        $this->assertSame(1, $data['totals']['units']);
        $this->assertSame(1, $data['totals']['operational']);
        $this->assertSame(0, $data['totals']['impacted']);
        $this->assertSame(0.0, $data['totals']['impacted_pct']);
        $this->assertSame('operational', $this->unitRow($unit)['status']);
        // Grouped by the category's asset_group, not by a hardcoded taxonomy.
        $this->assertSame('POS Systems', $this->unitRow($unit)['group']);
    }

    /** 2. Tagging that exact unit to an open ticket makes it IMPACTED. */
    public function test_tagging_unit_to_active_ticket_makes_it_impacted(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $this->tag($this->ticket('open'), $unit);

        $data = $this->build();

        $this->assertSame(0, $data['totals']['operational']);
        $this->assertSame(1, $data['totals']['impacted']);
        $this->assertSame(100.0, $data['totals']['impacted_pct']);
        $this->assertSame(1, $data['totals']['stores_impacted']);
        $this->assertSame('impacted', $this->unitRow($unit)['status']);
        $this->assertSame(1, $this->unitRow($unit)['active_tickets']);
    }

    /** 3 + 4. Two active tickets: resolving one keeps it RED, resolving both frees it. */
    public function test_multi_ticket_unit_stays_impacted_until_every_link_is_terminal(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $first = $this->ticket('open');
        $second = $this->ticket('in_progress');
        $this->tag($first, $unit);
        $this->tag($second, $unit);

        $this->assertSame(2, $this->unitRow($unit)['active_tickets']);

        // Resolve only the first — one active link remains, so the unit is still RED.
        $first->update(['status' => 'resolved']);
        $this->assertSame('impacted', $this->unitRow($unit)['status']);
        $this->assertSame(1, $this->unitRow($unit)['active_tickets']);

        // Close the last one — zero active links, so the unit recovers.
        $second->update(['status' => 'closed']);
        $this->assertSame('operational', $this->unitRow($unit)['status']);
        $this->assertSame(0, $this->unitRow($unit)['active_tickets']);
    }

    /** 5. Reopening a resolved linked ticket turns the unit RED again. */
    public function test_reopening_a_linked_ticket_makes_the_unit_impacted_again(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $ticket = $this->ticket('open');
        $this->tag($ticket, $unit);

        $ticket->update(['status' => 'closed']);
        $this->assertSame('operational', $this->unitRow($unit)['status']);

        $ticket->update(['status' => 'open']);
        $this->assertSame('impacted', $this->unitRow($unit)['status']);
    }

    /** 6. Removing the only ticket-asset link makes the unit OPERATIONAL. */
    public function test_removing_the_only_link_makes_the_unit_operational(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $link = $this->tag($this->ticket('open'), $unit);

        $this->assertSame('impacted', $this->unitRow($unit)['status']);

        $link->delete();
        $this->assertSame('operational', $this->unitRow($unit)['status']);
    }

    /** 7. Soft-deleted linked tickets never hold a unit RED. */
    public function test_soft_deleted_linked_ticket_does_not_keep_the_unit_impacted(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $ticket = $this->ticket('open');
        $this->tag($ticket, $unit);

        $ticket->delete(); // Ticket uses SoftDeletes — the link row survives.

        $this->assertNotNull($ticket->fresh()->deleted_at);
        $this->assertDatabaseCount('ticket_assets', 1);
        $this->assertSame('operational', $this->unitRow($unit)['status']);
    }

    /** Child (escalation) tickets count too — any active link impacts the unit. */
    public function test_active_child_ticket_impacts_the_unit(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $parent = $this->ticket('waiting_service_provider');
        $child = $this->ticket('open');
        $child->update(['parent_id' => $parent->id]);
        $this->tag($child, $unit);

        // The store dashboards count root tickets only; per-unit health does not,
        // because the physical unit is impacted either way.
        $this->assertSame('impacted', $this->unitRow($unit)['status']);
    }

    /** 9. A received transfer moves the unit's health to its new store. */
    public function test_received_transfer_moves_the_unit_to_its_current_store(): void
    {
        $destination = $this->store($this->company, 'ST002');
        $unit = $this->unit('SN-98765', $this->store->code);
        $this->tag($this->ticket('open'), $unit);

        StockTransfer::create([
            'transfer_no' => 'TR-0001',
            'transfer_date' => now()->toDateString(),
            'origin_location' => $this->store->code,
            'destination_location' => $destination->code,
            'status' => 'Received',
            'asset_id' => $this->asset->id,
            'source_stock_in_id' => $unit->id,
            'asset_type' => 'Fixed',
            'quantity' => 1,
            'serial_no' => $unit->serial_no,
        ]);

        $row = $this->unitRow($unit);

        // Health follows the physical unit; the ticket link is untouched.
        $this->assertSame($destination->id, $row['store_id']);
        $this->assertSame('impacted', $row['status']);
        $this->assertSame(1, $row['active_tickets']);

        $byStore = collect($this->build()['stores'])->keyBy('code');
        $this->assertSame(0, $byStore['ST001']['total']);
        $this->assertSame(1, $byStore['ST002']['total']);
        $this->assertSame(1, $byStore['ST002']['impacted']);
    }

    /** 10. Entity scope cannot be escaped, from the build or the drill-down endpoint. */
    public function test_entity_scope_hides_units_outside_the_selection(): void
    {
        $other = Company::create(['name' => 'Beta Entity', 'code' => 'BETA', 'type' => 'Entity', 'is_active' => true]);
        $otherStore = $this->store($other, 'ST900');

        $this->unit('SN-ALPHA', $this->store->code);
        $this->unit('SN-BETA', $otherStore->code);

        // Scoped to Alpha only — Beta's unit must not appear in any total.
        $scoped = $this->build([$this->company->id]);
        $this->assertSame(1, $scoped['totals']['units']);
        $this->assertSame(['ST001'], collect($scoped['stores'])->pluck('code')->all());

        // An empty selection means "no accessible entity" → nothing, not everything.
        $this->assertSame(0, $this->build([])['totals']['units']);

        // And the drill-down re-applies it server-side even when the client asks for
        // a store id it should not be able to see.
        $viewer = User::factory()->create(['company_id' => $this->company->id]);
        $viewer->givePermissionTo(Permission::findOrCreate('stock_ins.view', 'web'));

        $response = $this->actingAs($viewer)
            ->getJson(route('dashboard.asset-health.units', ['store_id' => $otherStore->id]));

        $response->assertOk();
        $this->assertSame(0, $response->json('count'));
        $this->assertSame([], $response->json('units'));
    }

    /**
     * A multi-entity selection must survive the ActiveEntityScope on StockIn/Asset.
     *
     * That scope pins queries to the viewer's single ACTIVE entity. This tab scopes by
     * the store that physically holds the unit instead, so the scope has to be off —
     * otherwise widening the Entity filter silently returns only the active entity's
     * units while still claiming to cover both.
     */
    public function test_multi_entity_selection_is_not_narrowed_by_the_active_entity_scope(): void
    {
        $beta = Company::create(['name' => 'Beta Entity', 'code' => 'BETA', 'type' => 'Entity', 'is_active' => true]);
        $betaStore = $this->store($beta, 'ST900');

        $betaAsset = Asset::create([
            'item_code' => 'PC-002',
            'category_id' => $this->asset->category_id,
            'brand' => 'Acme',
            'model' => 'Desktop PC',
            'type' => 'Fixed',
            'is_active' => true,
        ]);
        $this->stamp($betaAsset, $beta->id);

        $this->unit('SN-ALPHA', $this->store->code);
        $this->unit('SN-BETA', $betaStore->code, 'Posted', $betaAsset);

        // Viewer's active entity is Alpha, but the dashboard filter selects both.
        $viewer = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($viewer);

        $data = app(AssetOperationalHealthService::class)->build([$this->company->id, $beta->id]);

        $this->assertSame(2, $data['totals']['units']);
        $this->assertSame(['ST001', 'ST900'], collect($data['stores'])->pluck('code')->sort()->values()->all());
    }

    /** The drill-down lists EVERY active linked ticket, not just the newest. */
    public function test_drill_down_returns_all_active_linked_tickets_for_a_unit(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $first = $this->ticket('open');
        $second = $this->ticket('in_progress');
        $resolved = $this->ticket('resolved');
        $this->tag($first, $unit);
        $this->tag($second, $unit);
        $this->tag($resolved, $unit);

        $units = app(AssetOperationalHealthService::class)
            ->units([$this->company->id], $this->store->id);

        $this->assertSame(1, $units['count']);
        $row = $units['units'][0];
        $this->assertSame('impacted', $row['status']);
        $this->assertSame(2, $row['active_tickets']);

        $keys = collect($row['tickets'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$first->id, $second->id], $keys);
    }

    /** 8. Tagging revalidates location server-side — a stale client cannot tag away. */
    public function test_tagging_rejects_a_unit_that_is_no_longer_at_the_ticket_store(): void
    {
        $elsewhere = $this->store($this->company, 'ST002');
        $unit = $this->unit('SN-98765', $elsewhere->code); // sits at ST002
        $ticket = $this->ticket('open');                   // ticket is on ST001

        $tech = User::factory()->create(['company_id' => $this->company->id]);
        $tech->givePermissionTo(Permission::findOrCreate('tickets.edit', 'web'));

        $response = $this->actingAs($tech)->postJson(route('tickets.assets.store', $ticket->id), [
            'asset_id' => $this->asset->id,
            'stock_in_id' => $unit->id,
            'transaction_type' => 'Repair',
            'quantity' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('stock_in_id');
        $this->assertDatabaseCount('ticket_assets', 0);

        // The same unit tags fine once the ticket is on the store that holds it.
        $ticket->update(['store_id' => $elsewhere->id]);

        $this->actingAs($tech)->postJson(route('tickets.assets.store', $ticket->id), [
            'asset_id' => $this->asset->id,
            'stock_in_id' => $unit->id,
            'transaction_type' => 'Repair',
            'quantity' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseCount('ticket_assets', 1);
    }

    /**
     * The board's column axis is FIXED to the reference sheet's six groups, in its
     * order, regardless of what the current scope happens to contain — otherwise a
     * row's shape would change per store and the sheet could not be read down a column.
     */
    public function test_board_columns_are_the_fixed_slide_groups_with_category_subheaders(): void
    {
        $this->unit('SN-98765', $this->store->code);

        $columns = collect($this->build()['columns']);

        // Every group is a column even though this scope only has POS Systems units.
        $this->assertSame([
            'POS Systems',
            'Peripherals',
            'Security',
            'Network & Connectivity',
            'Digital Experience',
            'Back Office',
        ], $columns->pluck('name')->all());

        // The sheet's second header row: the real Category names mapped to a group.
        $this->assertSame(['POS Hardware'], $columns->firstWhere('name', 'POS Systems')['categories']);
        $this->assertSame([], $columns->firstWhere('name', 'Peripherals')['categories']);
    }

    /** "Ungrouped" is a tail column that appears only when something lands in it. */
    public function test_ungrouped_column_appears_only_for_unmapped_units(): void
    {
        $this->unit('SN-MAPPED', $this->store->code);

        // Fully mapped scope → exactly the six sheet columns.
        $this->assertNotContains('Ungrouped', collect($this->build()['columns'])->pluck('name')->all());

        $orphanCategory = Category::create(['name' => 'Misc Hardware', 'is_active' => true]);
        $orphan = Asset::create([
            'item_code' => 'MISC-002',
            'category_id' => $orphanCategory->id,
            'brand' => 'Acme',
            'model' => 'Unknown Box',
            'type' => 'Fixed',
            'is_active' => true,
        ]);
        $this->stamp($orphan);
        $this->unit('SN-ORPHAN', $this->store->code, 'Posted', $orphan);

        // An unmapped unit must still be visible, so the tail column appears — last.
        $names = collect($this->build()['columns'])->pluck('name')->all();
        $this->assertSame('Ungrouped', end($names));
        $this->assertCount(7, $names);
    }

    /**
     * The sheet's 4-level legend. Health stays binary; this grades HOW BAD, from the
     * worst active ticket's priority — which is why two stores each showing one issue
     * can sit on different colours.
     */
    public function test_severity_band_follows_the_worst_active_ticket_priority(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);

        $this->assertSame('healthy', $this->unitRow($unit)['band']);

        $low = $this->ticket('open', 'low');
        $this->tag($low, $unit);
        $this->assertSame('warning', $this->unitRow($unit)['band']);

        $high = $this->ticket('open', 'high');
        $this->tag($high, $unit);
        $this->assertSame('at_risk', $this->unitRow($unit)['band'], 'high outranks low');

        $urgent = $this->ticket('open', 'urgent');
        $this->tag($urgent, $unit);
        $this->assertSame('critical', $this->unitRow($unit)['band'], 'urgent outranks high');

        // Resolving the urgent one drops the row back to the next-worst band.
        $urgent->update(['status' => 'resolved']);
        $this->assertSame('at_risk', $this->unitRow($unit)['band']);

        // The store row inherits the worst band across its units.
        $store = collect($this->build()['stores'])->firstWhere('code', 'ST001');
        $this->assertSame('at_risk', $store['band']);
        $this->assertSame('at_risk', $store['groups']['POS Systems']['band']);
    }

    /** Owner / Next Action / ETA are derived from the driving ticket, never invented. */
    public function test_store_row_carries_owner_next_action_and_eta_from_the_driving_ticket(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $ticket = $this->ticket('waiting_service_provider', 'urgent');
        $this->tag($ticket, $unit);

        TicketSlaMetric::create([
            'ticket_id' => $ticket->id,
            'resolution_target_at' => '2026-08-20 17:00:00',
        ]);

        $store = collect($this->build()['stores'])->firstWhere('code', 'ST001');

        $this->assertSame('Waiting for Partner', $store['next_action']);
        $this->assertSame('Aug 20, 2026', $store['eta']);
        // No vendor and no assignee on this fixture → honest "Unassigned", not blank.
        $this->assertSame('Unassigned', $store['owner']);
        $this->assertFalse($store['is_partner']);
        $this->assertSame(1, $store['active_tickets']);
    }

    /** A ticket escalated to a partner shows that partner as the row's Owner. */
    public function test_partner_escalation_shows_the_partner_as_owner(): void
    {
        $vendor = Vendor::create(['name' => 'Acme Support Co', 'is_active' => true]);

        $unit = $this->unit('SN-98765', $this->store->code);
        $ticket = $this->ticket('waiting_service_provider', 'high');
        $ticket->update(['vendor_id' => $vendor->id]);
        $this->tag($ticket, $unit);

        $store = collect($this->build()['stores'])->firstWhere('code', 'ST001');

        $this->assertSame('Acme Support Co', $store['owner']);
        $this->assertTrue($store['is_partner']);
    }

    /** One ticket tagged to three units is ONE issue on the row, not three. */
    public function test_active_issues_counts_distinct_tickets_not_links(): void
    {
        $a = $this->unit('SN-A', $this->store->code);
        $b = $this->unit('SN-B', $this->store->code);
        $c = $this->unit('SN-C', $this->store->code);

        $ticket = $this->ticket('open');
        foreach ([$a, $b, $c] as $unit) {
            $this->tag($ticket, $unit);
        }

        $store = collect($this->build()['stores'])->firstWhere('code', 'ST001');

        $this->assertSame(3, $store['impacted'], 'three units are impacted');
        $this->assertSame(1, $store['active_tickets'], 'but it is a single issue');
    }

    /**
     * The board's "Active Issues" number and the drill-down's unit list are different
     * counts, and the drill-down must report BOTH — otherwise clicking a cell showing
     * "2" opens a list of 3 units and reads as a bug rather than as one shared ticket.
     *
     * Mirrors the real CFE I case: one CCTV inspection ticket tagged to two cameras,
     * plus a separate ticket on a laptop → 2 issues across 3 impacted units.
     */
    public function test_drill_down_reports_issue_count_and_unit_count_separately(): void
    {
        $camA = $this->unit('SN-CAM-A', $this->store->code);
        $camB = $this->unit('SN-CAM-B', $this->store->code);
        $laptop = $this->unit('SN-LAPTOP', $this->store->code);
        $this->unit('SN-HEALTHY', $this->store->code); // untouched, stays operational

        $inspection = $this->ticket('open');
        $this->tag($inspection, $camA);
        $this->tag($inspection, $camB);
        $this->tag($this->ticket('open'), $laptop);

        $store = collect($this->build()['stores'])->firstWhere('code', 'ST001');
        $this->assertSame(2, $store['active_tickets'], 'board shows 2 issues');
        $this->assertSame(3, $store['impacted'], 'across 3 impacted units');

        $drill = app(AssetOperationalHealthService::class)
            ->units([$this->company->id], $this->store->id, null, 'impacted');

        // Both numbers travel with the drill-down so the modal can reconcile them.
        $this->assertSame(2, $drill['ticket_count']);
        $this->assertSame(3, $drill['impacted_count']);
        $this->assertSame(3, $drill['count']);

        // And the shared ticket really does appear on both cameras.
        $keys = collect($drill['units'])
            ->mapWithKeys(fn ($unit) => [$unit['serial_no'] => collect($unit['tickets'])->pluck('id')->all()]);
        $this->assertSame($keys['SN-CAM-A'], $keys['SN-CAM-B']);
        $this->assertNotSame($keys['SN-CAM-A'], $keys['SN-LAPTOP']);
    }

    /** Consumables and unposted stock never enter the deployed fleet. */
    public function test_only_posted_fixed_units_are_counted(): void
    {
        $consumable = Asset::create([
            'item_code' => 'PAPER-01',
            'category_id' => $this->asset->category_id,
            'brand' => 'Acme',
            'model' => 'Receipt Roll',
            'type' => 'Consumables',
            'is_active' => true,
        ]);
        $this->stamp($consumable);

        $this->unit('SN-FIXED', $this->store->code);
        $this->unit('SN-DRAFT', $this->store->code, 'For Posting');
        $this->unit('SN-PAPER', $this->store->code, 'Posted', $consumable);

        $data = $this->build();

        $this->assertSame(1, $data['totals']['units']);
    }

    /** The group filter narrows the fleet without dropping the group list itself. */
    public function test_group_filter_narrows_units_but_keeps_the_group_list(): void
    {
        $cctvCategory = Category::create(['name' => 'CCTV', 'asset_group_id' => $this->assetGroup('Security')->id, 'is_active' => true]);
        $camera = Asset::create([
            'item_code' => 'CAM-001',
            'category_id' => $cctvCategory->id,
            'brand' => 'Acme',
            'model' => 'Dome Camera',
            'type' => 'Fixed',
            'is_active' => true,
        ]);
        $this->stamp($camera);

        $this->unit('SN-PC', $this->store->code);
        $this->unit('SN-CAM', $this->store->code, 'Posted', $camera);

        $filtered = $this->build([$this->company->id], 'Security');

        $this->assertSame(1, $filtered['totals']['units']);
        // Both groups stay selectable even though only one is in view.
        $this->assertSame(['POS Systems', 'Security'], collect($filtered['groups'])->pluck('name')->sort()->values()->all());
    }

    /** A unit whose category has no asset group still counts, under "Ungrouped". */
    public function test_units_without_a_mapped_group_fold_into_ungrouped(): void
    {
        $orphanCategory = Category::create(['name' => 'Misc Hardware', 'is_active' => true]);
        $orphan = Asset::create([
            'item_code' => 'MISC-001',
            'category_id' => $orphanCategory->id,
            'brand' => 'Acme',
            'model' => 'Unknown Box',
            'type' => 'Fixed',
            'is_active' => true,
        ]);
        $this->stamp($orphan);

        $unit = $this->unit('SN-MISC', $this->store->code, 'Posted', $orphan);

        $this->assertSame('Ungrouped', $this->unitRow($unit)['group']);
        $this->assertSame(1, $this->build()['totals']['units']);
    }

    /** 11. The existing Store Health metric is untouched by any of this. */
    public function test_store_health_semantics_are_unchanged_by_asset_tagging(): void
    {
        $unit = $this->unit('SN-98765', $this->store->code);
        $ticket = $this->ticket('open');

        $before = Ticket::whereNotIn('status', ['resolved', 'closed'])
            ->where('store_id', $this->store->id)->count();

        $this->tag($ticket, $unit);

        $after = Ticket::whereNotIn('status', ['resolved', 'closed'])
            ->where('store_id', $this->store->id)->count();

        // Asset Health is a different metric: tagging a unit changes unit health but
        // must not move the open-ticket count the store dashboards are built on.
        $this->assertSame($before, $after);
        $this->assertSame('impacted', $this->unitRow($unit)['status']);
    }

    // --- helpers -------------------------------------------------------------

    private function build(?array $companyIds = null, ?string $group = null): array
    {
        return app(AssetOperationalHealthService::class)->build($companyIds ?? [$this->company->id], null, $group);
    }

    /** The drill-down row for one unit, which is where per-unit status lives. */
    private function unitRow(StockIn $unit): array
    {
        $units = app(AssetOperationalHealthService::class)->units([$this->company->id], null);

        return collect($units['units'])->firstWhere('id', $unit->id) ?? [];
    }

    /**
     * Stamp a record with its owning entity.
     *
     * company_id is deliberately NOT fillable on Asset/StockIn — the app sets it from
     * the eloquent.creating listener on real authed requests. Fixtures have no active
     * entity, so they must do the same thing explicitly or the entity-scoped models
     * come back unstamped and invisible to any acting-as request.
     */
    private function stamp($model, ?int $companyId = null): void
    {
        $model->forceFill(['company_id' => $companyId ?? $this->company->id])->save();
    }

    private function assetGroup(string $label): ReferenceOption
    {
        return ReferenceOption::firstOrCreate(
            ['type' => 'asset_group', 'value' => $label],
            ['label' => $label, 'sort_order' => 0],
        );
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

    private function unit(string $serial, string $location, string $status = 'Posted', ?Asset $asset = null): StockIn
    {
        $unit = StockIn::create([
            'receive_date' => now()->toDateString(),
            'destination_location' => $location,
            'status' => $status,
            'asset_id' => ($asset ?? $this->asset)->id,
            'asset_type' => ($asset ?? $this->asset)->type,
            'quantity' => 1,
            'serial_no' => $serial,
            'barcode' => "BC-{$serial}",
        ]);

        $this->stamp($unit, ($asset ?? $this->asset)->company_id);

        return $unit;
    }

    private function ticket(string $status, string $priority = 'medium'): Ticket
    {
        return Ticket::create([
            'title' => "Asset health fixture {$status}",
            'description' => 'Asset operational health fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => $priority,
            'severity' => 'minor',
            'store_id' => $this->store->id,
        ]);
    }

    private function tag(Ticket $ticket, StockIn $unit): TicketAsset
    {
        return TicketAsset::create([
            'ticket_id' => $ticket->id,
            'asset_id' => $unit->asset_id,
            'stock_in_id' => $unit->id,
            'serial_no' => $unit->serial_no,
            'barcode' => $unit->barcode,
            'transaction_type' => 'Existing',
            'quantity' => 1,
        ]);
    }
}
