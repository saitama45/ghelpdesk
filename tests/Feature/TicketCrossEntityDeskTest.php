<?php

namespace Tests\Feature;

use App\Models\{Category, Company, Department, Item, Role, Store, SubCategory, Ticket, User, Vendor};
use App\Support\{CompanyContext, DepartmentContext};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A desk serves locations of entities other than its own: TAS (a TGI department)
 * runs IT for the ENTECH offices, and ENTECH has no IT desk or item catalogue.
 * Its catalogue and partners therefore follow the desk, and the bulk bar can move
 * tickets to another entity without switching to it.
 */
class TicketCrossEntityDeskTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;
    private Company $entech;
    private Company $gsi;
    private Department $tas;
    private Store $tgiStore;
    private Store $entechStore;
    private Item $tasItem;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Model::unguarded(function () {
            $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
            $this->entech = Company::create(['name' => 'ENTECH', 'code' => 'ENTECH', 'is_active' => true]);
            $this->gsi = Company::create(['name' => 'GSI', 'code' => 'GSI', 'is_active' => true]);
            $this->tas = Department::create(['name' => 'TAS', 'code' => 'TAS', 'company_id' => $this->tgi->id, 'is_active' => true]);
            // ENTECH has departments of its own, but none of them is an IT desk.
            Department::create(['name' => 'Business Support', 'company_id' => $this->entech->id, 'is_active' => true]);
            $this->tgiStore = $this->store('TGI Office', 'TGI-HO', $this->tgi);
            $this->entechStore = $this->store('One Vertis Plaza', 'OVP', $this->entech);
            $this->tasItem = $this->item('Laptop repair', $this->tgi, $this->tas);

            foreach (['tickets.view', 'tickets.create', 'tickets.edit'] as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            $role = Role::create(['name' => 'Ticket Admin', 'guard_name' => 'web', 'is_assignable' => true]);
            $role->givePermissionTo(['tickets.view', 'tickets.create', 'tickets.edit']);
            // Can switch to TGI and ENTECH, but not GSI.
            $role->companies()->sync([$this->tgi->id, $this->entech->id]);
            $this->agent = User::factory()->create(['company_id' => $this->tgi->id, 'department_id' => $this->tas->id]);
            $this->agent->assignRole($role);
        });

        // Working the TAS queue under TGI - never switched to ENTECH.
        $this->actingAs($this->agent)->withSession([
            CompanyContext::SESSION_KEY => $this->tgi->id,
            DepartmentContext::SESSION_KEY => $this->tas->id,
        ]);
    }

    public function test_the_desk_catalogue_is_flagged_usable_at_any_location(): void
    {
        $this->getJson(route('tickets.data.items'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->tasItem->id)
            ->assertJsonPath('0.usable_everywhere', true);
    }

    public function test_a_desk_classifies_a_ticket_at_another_entitys_location_with_its_own_catalogue(): void
    {
        $this->postJson(route('tickets.store'), $this->payload())->assertCreated();

        $ticket = Ticket::withoutGlobalScopes()->where('title', 'Printer offline')->firstOrFail();
        $this->assertSame($this->entechStore->id, (int) $ticket->store_id);
        $this->assertSame($this->tasItem->id, (int) $ticket->item_id);
        $this->assertSame($this->tas->id, (int) $ticket->serving_department_id);
        $this->assertStringStartsWith('ENTECH-', $ticket->ticket_key);
    }

    public function test_the_desk_brings_its_partners_to_another_entitys_location(): void
    {
        // Only the desk's OWN entity rides along: a third entity's partner still fails.
        $vendor = fn (string $name, Company $company) => Model::unguarded(fn () => Vendor::create([
            'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
            'company_id' => $company->id, 'is_active' => true,
        ]));

        $this->postJson(route('tickets.store'), $this->payload(['vendor_id' => $vendor('GSI Partner', $this->gsi)->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('vendor_id');

        $this->postJson(route('tickets.store'), $this->payload(['vendor_id' => $vendor('TGI Partner', $this->tgi)->id]))
            ->assertCreated();
    }

    public function test_bulk_update_moves_tickets_to_another_entity_without_switching_to_it(): void
    {
        $first = $this->intake('First email');
        $second = $this->intake('Second email');
        $oldKey = $first->ticket_key;
        $this->assertStringStartsWith('TGI-', $oldKey);

        $this->post(route('tickets.bulk-update'), [
            'ticket_ids' => [$first->id, $second->id],
            'company_id' => $this->entech->id,
            'store_id' => $this->entechStore->id,
            'item_id' => $this->tasItem->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'moved to ENTECH'));

        foreach ([$first, $second] as $ticket) {
            $moved = Ticket::withoutGlobalScopes()->findOrFail($ticket->id);
            $this->assertSame($this->entech->id, (int) $moved->company_id);
            $this->assertSame($this->entechStore->id, (int) $moved->store_id);
            $this->assertSame($this->tasItem->id, (int) $moved->item_id);
            // Still TAS's work: the move changes the entity, not the desk.
            $this->assertSame($this->tas->id, (int) $moved->serving_department_id);
            $this->assertStringStartsWith('ENTECH-', $moved->ticket_key);
        }

        // The old key still resolves, and the move left a trail.
        $this->assertDatabaseHas('ticket_key_aliases', ['ticket_key' => $oldKey, 'ticket_id' => $first->id]);
        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $first->id, 'column_changed' => 'company_id', 'old_value' => 'TGI', 'new_value' => 'ENTECH',
        ]);
    }

    public function test_bulk_move_is_limited_to_entities_the_user_can_switch_to(): void
    {
        $ticket = $this->intake('Stays put');

        $this->postJson(route('tickets.bulk-update'), ['ticket_ids' => [$ticket->id], 'company_id' => $this->gsi->id])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');

        $this->assertSame($this->tgi->id, (int) $ticket->fresh()->company_id);
    }

    public function test_bulk_move_never_leaves_a_ticket_at_another_entitys_location(): void
    {
        $storeless = $this->intake('No location yet');
        $located = $this->intake('Already at TGI');
        DB::table('tickets')->where('id', $located->id)->update(['store_id' => $this->tgiStore->id]);

        // A location that is not the target entity's.
        $this->postJson(route('tickets.bulk-update'), [
            'ticket_ids' => [$storeless->id], 'company_id' => $this->entech->id, 'store_id' => $this->tgiStore->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');

        // A ticket that would keep its TGI location.
        $this->postJson(route('tickets.bulk-update'), [
            'ticket_ids' => [$storeless->id, $located->id], 'company_id' => $this->entech->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->assertSame($this->tgi->id, (int) $storeless->fresh()->company_id);

        // A ticket with no location moves on the entity alone.
        $this->post(route('tickets.bulk-update'), ['ticket_ids' => [$storeless->id], 'company_id' => $this->entech->id])
            ->assertSessionHasNoErrors();
        $moved = Ticket::withoutGlobalScopes()->findOrFail($storeless->id);
        $this->assertSame($this->entech->id, (int) $moved->company_id);
        $this->assertStringStartsWith('ENTECH-', $moved->ticket_key);
    }

    public function test_the_entity_location_feed_lists_that_entitys_locations_only(): void
    {
        $this->getJson(route('tickets.data.stores', ['company_id' => $this->entech->id]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'One Vertis Plaza');

        $this->getJson(route('tickets.data.stores', ['company_id' => $this->gsi->id]))->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Printer offline', 'status' => 'open', 'company_id' => $this->entech->id,
            'store_id' => $this->entechStore->id, 'item_id' => $this->tasItem->id,
            'serving_department_id' => $this->tas->id, 'is_self_requester' => true,
            'notify_requester' => false, 'assignee_id' => $this->agent->id, ...$overrides,
        ];
    }

    /** An email ticket as intake leaves it: on the default entity, routed to TAS, unclassified. */
    private function intake(string $title): Ticket
    {
        return Ticket::create([
            'title' => $title, 'company_id' => $this->tgi->id, 'serving_department_id' => $this->tas->id,
            'status' => 'open', 'type' => 'task', 'priority' => 'medium', 'severity' => 'minor',
        ]);
    }

    private function store(string $name, string $code, Company $company): Store
    {
        return Store::create([
            'company_id' => $company->id, 'code' => $code, 'name' => $name, 'sector' => 1,
            'area' => 'A', 'brand' => 'B', 'class' => 'Regular', 'is_active' => true,
        ]);
    }

    private function item(string $name, Company $company, Department $department): Item
    {
        $tag = ['company_id' => $company->id, 'department_id' => $department->id, 'is_active' => true];
        $category = Category::create([...$tag, 'name' => $name.' category']);
        $sub = SubCategory::create([...$tag, 'name' => $name.' sub']);

        return Item::create([...$tag, 'category_id' => $category->id, 'sub_category_id' => $sub->id,
            'name' => $name, 'concern_type' => 'Incident', 'priority' => 'Low']);
    }
}
