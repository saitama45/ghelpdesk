<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Item;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A response is the desk's answer of record, so /tickets/{id}/edit refuses to send
 * one until the ticket is routable: Department, Store, Company, Item and Assignee.
 *
 * The exemptions matter as much as the rule — internal notes, customers and
 * partner-escalation children would otherwise be locked out of a thread they are
 * not allowed to classify in the first place.
 */
class TicketResponseClassificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Store $store;
    private Item $item;
    private Department $serving;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);

        $this->store = Store::create([
            'code' => 'TC1',
            'name' => 'Test Store',
            'sector' => 1,
            'area' => 'North',
            'brand' => 'Test',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->serving = Department::create([
            'name' => 'Information Technology',
            'code' => 'IT',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        // Catalogue references are owned by a service department; an untagged one
        // is deliberately not selectable on a ticket (see DepartmentReferences).
        // company_id is not fillable on these models - it is normally stamped from
        // the active entity - so the fixture sets it unguarded.
        [$category, $this->item] = \Illuminate\Database\Eloquent\Model::unguarded(function () {
            $category = Category::create([
                'name' => 'Hardware',
                'is_active' => true,
                'company_id' => $this->company->id,
                'department_id' => $this->serving->id,
            ]);

            return [$category, Item::create([
                'category_id' => $category->id,
                'name' => 'POS Terminal',
                'description' => 'POS device issues',
                'priority' => 'medium',
                'concern_type' => 'Incident',
                'is_active' => true,
                'company_id' => $this->company->id,
                'department_id' => $this->serving->id,
            ])];
        });

        $this->agent = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->serving->id,
        ]);
    }

    public function test_a_fully_classified_ticket_accepts_a_response(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $ticket->id), ['comment_text' => 'On it.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'comment_text' => 'On it.',
        ]);
    }

    /**
     * @dataProvider missingClassificationProvider
     */
    public function test_a_response_is_blocked_while_a_required_field_is_unset(array $missing, string $label): void
    {
        $ticket = $this->ticket($missing);

        $response = $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $ticket->id), ['comment_text' => 'On it.']);

        $response->assertSessionHasErrors('classification');
        $this->assertStringContainsString(
            $label,
            (string) session('errors')->first('classification')
        );
        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public static function missingClassificationProvider(): array
    {
        return [
            'department' => [['department' => null, 'department_id' => null], 'Department'],
            'store' => [['store_id' => null], 'Store'],
            'item' => [['item_id' => null], 'Item'],
            'assignee' => [['assignee_id' => null], 'Assignee'],
        ];
    }

    public function test_the_error_names_every_missing_field_at_once(): void
    {
        $ticket = $this->ticket([
            'department' => null,
            'department_id' => null,
            'store_id' => null,
            'item_id' => null,
            'assignee_id' => null,
        ]);

        $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $ticket->id), ['comment_text' => 'On it.'])
            ->assertSessionHasErrors('classification');

        $message = (string) session('errors')->first('classification');
        foreach (['Department', 'Store', 'Item', 'Assignee'] as $field) {
            $this->assertStringContainsString($field, $message);
        }
    }

    public function test_a_terminal_status_change_is_blocked_while_the_ticket_is_unclassified(): void
    {
        $ticket = $this->ticket(['assignee_id' => null]);
        $this->agent->givePermissionTo(Permission::findOrCreate('tickets.resolve', 'web'));

        $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'status' => 'resolved',
            'action_taken' => 'Swapped the terminal.',
        ])->assertSessionHasErrors('classification');

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_comments', 0);
    }

    public function test_an_internal_note_is_exempt_because_the_desk_writes_it_while_classifying(): void
    {
        $ticket = $this->ticket([
            'department' => null,
            'department_id' => null,
            'store_id' => null,
            'item_id' => null,
            'assignee_id' => null,
        ]);

        $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'comment_text' => 'Waiting on the store to confirm the serial.',
            'is_internal' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'is_internal' => true,
        ]);
    }

    public function test_a_customer_may_still_reply_to_an_unclassified_ticket(): void
    {
        $otherDepartment = Department::create([
            'name' => 'Operations',
            'code' => 'OPS',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $requester = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $otherDepartment->id,
        ]);

        $ticket = $this->ticket([
            'store_id' => null,
            'item_id' => null,
            'assignee_id' => null,
            'serving_department_id' => $this->serving->id,
            'reporter_id' => $requester->id,
        ]);

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $ticket->id), ['comment_text' => 'Any update?'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
        ]);
    }

    public function test_a_partner_escalation_child_needs_no_assignee(): void
    {
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Partner Co', 'is_active' => true]);
        $parent = $this->ticket();

        $child = $this->ticket([
            'assignee_id' => null,
            'parent_id' => $parent->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $child->id), ['comment_text' => 'Escalated to the partner.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $child->id,
            'comment_text' => 'Escalated to the partner.',
        ]);
    }

    /**
     * The bulk bar on /tickets holds the same five fields to the same rule: no
     * selected ticket may leave it without a Department, Location, Entity, Item
     * and Assignee.
     */
    public function test_bulk_update_is_refused_while_a_selected_ticket_would_stay_unclassified(): void
    {
        $this->allowBulkEdit();
        $classified = $this->ticket();
        $incomplete = $this->ticket(['store_id' => null, 'assignee_id' => null]);

        $this->actingAs($this->agent)
            ->post(route('tickets.bulk-update'), [
                'ticket_ids' => [$classified->id, $incomplete->id],
                'status' => 'in_progress',
            ])
            ->assertSessionHasErrors('classification');

        $message = (string) session('errors')->first('classification');
        $this->assertStringContainsString('Location on 1 ticket', $message);
        $this->assertStringContainsString('Assignee on 1 ticket', $message);
        $this->assertStringNotContainsString('Item on', $message);

        // All or nothing: the ticket that was complete is not moved either.
        $this->assertSame('open', $classified->fresh()->status);
        $this->assertSame('open', $incomplete->fresh()->status);
    }

    public function test_bulk_update_is_accepted_once_the_bar_supplies_what_a_ticket_lacks(): void
    {
        $this->allowBulkEdit();
        $first = $this->ticket(['department' => null, 'department_id' => null, 'store_id' => null, 'item_id' => null, 'assignee_id' => null]);
        $second = $this->ticket(['store_id' => null, 'assignee_id' => null]);

        $this->actingAs($this->agent)
            ->post(route('tickets.bulk-update'), [
                'ticket_ids' => [$first->id, $second->id],
                'store_id' => $this->store->id,
                'item_id' => $this->item->id,
                'department' => $this->serving->name,
                'assignee_id' => $this->agent->id,
            ])
            ->assertSessionHasNoErrors();

        foreach ([$first, $second] as $ticket) {
            $ticket->refresh();
            $this->assertSame($this->store->id, (int) $ticket->store_id);
            $this->assertSame($this->item->id, (int) $ticket->item_id);
            $this->assertSame($this->serving->name, $ticket->department);
            $this->assertSame($this->agent->id, (int) $ticket->assignee_id);
        }
    }

    /** "Unchanged" is fine for a field every selected ticket already carries. */
    public function test_bulk_update_may_change_one_field_on_tickets_that_are_already_classified(): void
    {
        $this->allowBulkEdit();
        $first = $this->ticket();
        $second = $this->ticket();

        $this->actingAs($this->agent)
            ->post(route('tickets.bulk-update'), ['ticket_ids' => [$first->id, $second->id], 'status' => 'in_progress'])
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $first->fresh()->status);
        $this->assertSame('in_progress', $second->fresh()->status);
    }

    public function test_bulk_update_needs_no_assignee_for_a_partner_escalation_child(): void
    {
        $this->allowBulkEdit();
        $vendor = Vendor::create(['code' => 'V1', 'name' => 'Partner Co', 'is_active' => true]);
        $parent = $this->ticket();
        $child = $this->ticket(['assignee_id' => null, 'parent_id' => $parent->id, 'vendor_id' => $vendor->id]);

        $this->actingAs($this->agent)
            ->post(route('tickets.bulk-update'), ['ticket_ids' => [$child->id], 'status' => 'in_progress'])
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $child->fresh()->status);
    }

    private function allowBulkEdit(): void
    {
        $this->agent->givePermissionTo(Permission::findOrCreate('tickets.edit', 'web'));
    }

    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'POS terminal offline',
            'description' => 'The POS terminal cannot connect to the network.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $this->company->id,
            'store_id' => $this->store->id,
            'item_id' => $this->item->id,
            'assignee_id' => $this->agent->id,
            'department' => $this->serving->name,
            'department_id' => $this->serving->id,
        ], $overrides));
    }
}
