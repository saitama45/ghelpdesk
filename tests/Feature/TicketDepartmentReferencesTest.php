<?php

namespace Tests\Feature;

use App\Models\{Category, Company, Department, Item, Role, Store, SubCategory, Ticket, User, Vendor};
use App\Support\{CompanyContext, DepartmentContext};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Tests\TestCase;

class TicketDepartmentReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $tas;
    private Department $fm;
    private User $agent;
    private Store $store;
    private Item $tasItem;
    private Item $fmItem;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Model::unguarded(function () {
            $this->company = Company::create(['name' => 'Entity', 'code' => 'TGI', 'is_active' => true]);
            $this->tas = Department::create(['name' => 'TAS', 'code' => 'TAS', 'company_id' => $this->company->id, 'is_active' => true]);
            $this->fm = Department::create(['name' => 'FM', 'code' => 'FM', 'company_id' => $this->company->id, 'is_active' => true]);
            $this->store = Store::create(['name' => 'Shared store', 'code' => 'S1', 'company_id' => $this->company->id,
                'sector' => 1, 'area' => 'A', 'brand' => 'B', 'class' => 'Regular', 'is_active' => true]);
            $this->tasItem = $this->item($this->tas);
            $this->fmItem = $this->item($this->fm);
            $this->agent = User::factory()->create(['company_id' => $this->company->id, 'department_id' => $this->tas->id]);
            $this->agent->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        });
        $this->actingAs($this->agent)->withSession([
            CompanyContext::SESSION_KEY => $this->company->id,
            DepartmentContext::SESSION_KEY => $this->tas->id,
        ]);
    }

    private function item(Department $department): Item
    {
        $category = Category::create(['company_id' => $this->company->id, 'department_id' => $department->id, 'name' => 'Hardware', 'is_active' => true]);
        $sub = SubCategory::create(['company_id' => $this->company->id, 'department_id' => $department->id, 'name' => 'Repair', 'is_active' => true]);
        return Item::create(['company_id' => $this->company->id, 'department_id' => $department->id,
            'category_id' => $category->id, 'sub_category_id' => $sub->id, 'name' => 'Repair service',
            'concern_type' => 'Incident', 'priority' => 'Low', 'is_active' => true]);
    }

    private function payload(Item $item): array
    {
        return ['title' => 'Help needed', 'status' => 'open', 'company_id' => $this->company->id,
            'store_id' => $this->store->id, 'item_id' => $item->id, 'serving_department_id' => $this->tas->id,
            'is_self_requester' => true, 'notify_requester' => false, 'assignee_id' => $this->agent->id];
    }

    private function ticket(Department $department, Item $item): Ticket
    {
        return Ticket::withoutEvents(fn () => Ticket::create([
            ...$this->payload($item), 'ticket_key' => 'TGI-'.random_int(1000, 99999),
            'reporter_id' => $this->agent->id, 'serving_department_id' => $department->id,
            'category_id' => $item->category_id, 'sub_category_id' => $item->sub_category_id,
        ]));
    }

    public function test_picker_uses_provider_and_existing_ticket_route_instead_of_current_tab(): void
    {
        $this->getJson(route('tickets.data.items'))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->tasItem->id);
        $ticket = $this->ticket($this->fm, $this->fmItem);
        $this->getJson(route('tickets.data.items', ['ticket_id' => $ticket->id]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->fmItem->id);
    }

    public function test_create_rejects_another_departments_item_and_preserves_mail_route_identity(): void
    {
        $this->postJson(route('tickets.store'), $this->payload($this->fmItem))->assertUnprocessable()->assertJsonValidationErrors('item_id');
        $this->postJson(route('tickets.store'), $this->payload($this->tasItem))->assertCreated();
        $this->assertDatabaseHas('tickets', ['title' => 'Help needed', 'serving_department_id' => $this->tas->id, 'item_id' => $this->tasItem->id]);
    }

    public function test_stores_and_vendors_are_shared_by_every_department_without_being_enabled(): void
    {
        $vendor = Model::unguarded(fn () => Vendor::create(['name' => 'Partner', 'company_id' => $this->company->id, 'is_active' => true]));
        $this->postJson(route('tickets.store'), [...$this->payload($this->tasItem), 'vendor_id' => $vendor->id])->assertCreated();
        // The enabling endpoint is gone: only catalogues carry a department tag.
        $this->put(route('reference-departments.update', ['type' => 'stores', 'id' => $this->store->id]),
            ['department_id' => $this->tas->id])->assertNotFound();
        $this->put(route('reference-departments.update', ['type' => 'vendors', 'id' => $vendor->id]),
            ['department_id' => $this->tas->id])->assertNotFound();
    }

    public function test_a_shared_store_serves_a_second_department_with_its_own_catalogue(): void
    {
        $this->postJson(route('tickets.store'), [...$this->payload($this->fmItem), 'serving_department_id' => $this->fm->id])
            ->assertCreated();
        $this->assertDatabaseHas('tickets', ['store_id' => $this->store->id,
            'serving_department_id' => $this->fm->id, 'item_id' => $this->fmItem->id]);
    }

    public function test_store_change_revalidates_an_unchanged_item(): void
    {
        $ticket = $this->ticket($this->tas, $this->tasItem);
        $other = $this->store->replicate();
        $other->name = 'Other store';
        $other->code = 'S2';
        $other->is_active = false;
        $other->save();
        $this->putJson(route('tickets.update', $ticket), [...$this->payload($this->tasItem), 'store_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->assertSame($this->store->id, $ticket->fresh()->store_id);
    }

    public function test_legacy_classification_does_not_block_unrelated_edits(): void
    {
        $ticket = $this->ticket($this->tas, $this->tasItem);
        DB::table('items')->where('id', $this->tasItem->id)->update(['department_id' => null]);
        $this->putJson(route('tickets.update', $ticket), ['title' => 'Follow up', 'status' => 'open', 'company_id' => $this->company->id])->assertRedirect();
        $this->assertSame('Follow up', $ticket->fresh()->title);
    }

    public function test_catalog_names_are_unique_within_a_department_and_new_rows_are_tagged(): void
    {
        $this->postJson(route('categories.store'), ['name' => 'Hardware'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->post(route('categories.store'), ['name' => 'Network'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['name' => 'Network', 'department_id' => $this->tas->id, 'company_id' => $this->company->id]);
        $this->assertSame(2, Category::where('name', 'Hardware')->count());
    }

    public function test_untagged_reference_can_be_tagged_and_later_moved_by_a_department_administrator(): void
    {
        $id = DB::table('categories')->insertGetId(['name' => 'Legacy', 'company_id' => $this->company->id, 'is_active' => true]);
        $this->put(route('reference-departments.update', ['type' => 'categories', 'id' => $id]), ['department_id' => $this->tas->id])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $id, 'department_id' => $this->tas->id]);
        $this->put(route('reference-departments.update', ['type' => 'categories', 'id' => $id]), ['department_id' => $this->fm->id])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $id, 'department_id' => $this->fm->id]);
    }

    public function test_tagging_an_untagged_item_brings_or_maps_its_classification(): void
    {
        $row = fn (array $extra) => ['company_id' => $this->company->id, 'is_active' => true, ...$extra];
        $category = Category::find(DB::table('categories')->insertGetId($row(['name' => 'Addl Service'])));
        $sub = SubCategory::find(DB::table('sub_categories')->insertGetId($row(['name' => 'Repair'])));
        $item = Item::find(DB::table('items')->insertGetId($row(['name' => 'Service', 'category_id' => $category->id,
            'sub_category_id' => $sub->id, 'concern_type' => 'Service Request', 'priority' => 'High'])));

        $this->putJson(route('reference-departments.update', ['type' => 'items', 'id' => $item->id]),
            ['department_id' => $this->tas->id])->assertRedirect();

        $item->refresh();
        $this->assertSame($this->tas->id, (int) $item->department_id);
        // The untagged category comes along; the sub-category maps to TAS's own "Repair".
        $this->assertSame($category->id, (int) $item->category_id);
        $this->assertSame($this->tas->id, (int) $category->fresh()->department_id);
        $this->assertSame($this->tasItem->sub_category_id, (int) $item->sub_category_id);
        $this->assertNull($sub->fresh()->department_id);
    }

    public function test_retagging_is_refused_when_it_would_duplicate_an_existing_identity(): void
    {
        $category = Category::where('department_id', $this->tas->id)->firstOrFail();
        $this->putJson(route('reference-departments.update', ['type' => 'categories', 'id' => $category->id]),
            ['department_id' => $this->fm->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertSame($this->tas->id, $category->fresh()->department_id);
    }

    public function test_item_cannot_use_another_departments_category(): void
    {
        $this->postJson(route('items.store'), ['name' => 'Invalid item', 'category_id' => $this->fmItem->category_id,
            'sub_category_id' => $this->tasItem->sub_category_id, 'priority' => 'Low', 'concern_type' => 'Incident'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_bulk_store_change_is_rejected_before_any_ticket_is_updated(): void
    {
        $first = $this->ticket($this->tas, $this->tasItem);
        $second = $this->ticket($this->fm, $this->fmItem);
        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$first->id, $second->id], 'item_id' => $this->tasItem->id])
            ->assertUnprocessable();
        $this->assertSame($this->fmItem->id, $second->fresh()->item_id);
        $this->assertSame($this->tasItem->id, $first->fresh()->item_id);
    }

    public function test_final_auto_assigned_store_is_validated(): void
    {
        $other = $this->store->replicate();
        $other->name = 'Deactivated store';
        $other->code = 'S3';
        $other->is_active = false;
        $other->save();
        $this->mock(\App\Services\AutoAssigneeService::class, function ($mock) use ($other) {
            $mock->shouldReceive('resolveAssignee')->andReturn([
                'assignee_id' => $this->agent->id, 'company_id' => $this->company->id, 'store_id' => $other->id,
            ]);
        });
        $payload = $this->payload($this->tasItem);
        unset($payload['assignee_id']);
        $this->postJson(route('tickets.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->assertDatabaseMissing('tickets', ['title' => 'Help needed']);
    }

    public function test_a_tag_cannot_reach_a_reference_of_an_unrelated_entity(): void
    {
        $company = Company::create(['name' => 'Unrelated', 'code' => 'OTHER', 'is_active' => true]);
        $id = DB::table('categories')->insertGetId(['name' => 'Foreign', 'company_id' => $company->id, 'is_active' => true]);
        $this->putJson(route('reference-departments.update', ['type' => 'categories', 'id' => $id]),
            ['department_id' => $this->tas->id])->assertNotFound();
        $this->assertNull(DB::table('categories')->where('id', $id)->value('department_id'));
    }

    public function test_another_department_cannot_take_over_a_tagged_reference(): void
    {
        $this->agent->syncRoles([]);
        $this->agent->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'categories.edit', 'guard_name' => 'web']));
        $category = Category::where('department_id', $this->tas->id)->firstOrFail();
        $this->agent->update(['department_id' => $this->fm->id]);
        $this->withSession([DepartmentContext::SESSION_KEY => $this->fm->id])
            ->putJson(route('reference-departments.update', ['type' => 'categories', 'id' => $category->id]),
                ['department_id' => $this->fm->id])->assertNotFound();
        $this->assertSame($this->tas->id, $category->fresh()->department_id);
    }

    public function test_unclassified_routed_intake_keeps_its_department_without_requiring_reference_tags(): void
    {
        $ticket = Ticket::create(['title' => 'Email intake', 'company_id' => $this->company->id,
            'serving_department_id' => $this->fm->id, 'status' => 'open', 'type' => 'task', 'priority' => 'medium', 'severity' => 'minor']);
        $this->assertSame($this->fm->id, $ticket->fresh()->serving_department_id);
        $this->assertNull($ticket->fresh()->item_id);
    }

    public function test_tagged_catalog_names_have_a_database_uniqueness_constraint(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('categories')->insert(['company_id' => $this->company->id, 'department_id' => $this->tas->id, 'name' => 'Hardware', 'is_active' => true]);
    }

    public function test_department_deletion_cannot_clear_routes_before_reference_constraints_reject_it(): void
    {
        $ticket = $this->ticket($this->tas, $this->tasItem);
        try {
            $this->tas->delete();
            $this->fail('Deleting a department with reference ownership must fail.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('department', $exception->errors());
        }
        $this->assertSame($this->tas->id, $ticket->fresh()->serving_department_id);
        $this->assertDatabaseHas('departments', ['id' => $this->tas->id]);
    }

    public function test_automated_classified_source_can_use_item_provider_without_overriding_priority(): void
    {
        $ticket = Ticket::create(['title' => 'Automated inspection', 'company_id' => $this->company->id,
            'store_id' => $this->store->id, 'item_id' => $this->tasItem->id,
            'status' => 'open', 'type' => 'task', 'priority' => 'high', 'severity' => 'minor']);
        $this->assertSame($this->tas->id, $ticket->fresh()->serving_department_id);
        $this->assertSame('high', $ticket->fresh()->priority);
    }
}
