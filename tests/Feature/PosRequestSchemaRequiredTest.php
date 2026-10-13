<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosRequest;
use App\Models\RequestType;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosRequestSchemaRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        Store::create([
            'code' => 'CFE I',
            'name' => 'CFE I',
            'sector' => 1,
            'area' => 'Corporate',
            'brand' => 'TGI',
            'class' => 'Office',
            'is_active' => true,
            'company_id' => $tgi->id,
        ]);

        $this->company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $this->requester = User::factory()->create(['company_id' => $this->company->id]);

        Permission::create(['name' => 'pos_requests.create']);
        $this->requester->givePermissionTo('pos_requests.create');
    }

    public function test_required_checkbox_column_left_empty_is_rejected(): void
    {
        $requestType = $this->requestType();

        // JSON bodies send an unticked group as []; multipart bodies drop the key entirely.
        foreach ([['printer_assignment' => []], []] as $printer) {
            $this->actingAs($this->requester)
                ->post(route('pos-requests.store'), $this->payload($requestType, [
                    ['product_name' => 'Coffee Break Tote Bag'] + $printer,
                ]))
                ->assertSessionHasErrors([
                    'details.0.printer_assignment' => 'Item #1: "Printer Assignment" is required.',
                ])
                ->assertSessionDoesntHaveErrors(['details.0.product_name']);
        }

        $this->assertSame(0, PosRequest::count());
    }

    public function test_only_the_incomplete_row_is_reported(): void
    {
        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($this->requestType(), [
                ['product_name' => 'Coffee Break Tote Bag', 'printer_assignment' => ['bar']],
                ['product_name' => '   ', 'printer_assignment' => []],
            ]))
            ->assertSessionHasErrors(['details.1.product_name', 'details.1.printer_assignment'])
            ->assertSessionDoesntHaveErrors(['details.0.product_name', 'details.0.printer_assignment']);

        $this->assertSame(0, PosRequest::count());
    }

    public function test_complete_line_items_create_the_request_and_ticket(): void
    {
        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($this->requestType(), [
                ['product_name' => 'Coffee Break Tote Bag', 'printer_assignment' => ['bar', 'kitchen'], 'delivery' => '0'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos-requests.index'));

        $posRequest = PosRequest::firstOrFail();
        $ticket = Ticket::withoutGlobalScopes()->findOrFail($posRequest->ticket_id);

        $this->assertStringContainsString('Printer Assignment: BAR, KITCHEN', $ticket->description);
        $this->assertStringContainsString('Delivery: 0', $ticket->description);
    }

    public function test_required_form_field_is_enforced_only_while_shown(): void
    {
        $requestType = $this->requestType([
            ['key' => 'channel', 'label' => 'Channel', 'type' => 'select', 'required' => true, 'options' => [
                ['label' => 'Dine In', 'value' => 'dine_in'],
                ['label' => 'Delivery', 'value' => 'delivery'],
            ]],
            ['key' => 'partners', 'label' => 'Partners', 'type' => 'checkbox_group', 'required' => true,
                'show_when' => ['field' => 'channel', 'value' => 'delivery'],
                'options' => [['label' => 'Grab', 'value' => 'grab']]],
        ]);
        $row = [['product_name' => 'Coffee Break Tote Bag', 'printer_assignment' => ['bar']]];

        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($requestType, $row, ['channel' => 'delivery', 'partners' => []]))
            ->assertSessionHasErrors(['form_data.partners' => '"Partners" is required.']);

        $this->assertSame(0, PosRequest::count());

        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($requestType, $row, ['channel' => 'dine_in', 'partners' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PosRequest::count());
    }

    public function test_type_with_form_fields_but_no_line_items_can_be_submitted(): void
    {
        $requestType = RequestType::create([
            'code' => 'POS-FIELDS-ONLY',
            'name' => 'POS Fields Only Test',
            'request_for' => ['POS'],
            'approval_levels' => 1,
            'approver_matrix' => [],
            'form_schema' => [
                'fields' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'required' => true]],
                'has_items' => false,
                'items_columns' => [],
            ],
            'is_active' => true,
        ]);

        // The form posts an empty details list for a type without a line-items table.
        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($requestType, [], ['reason' => '']))
            ->assertSessionHasErrors(['form_data.reason' => '"Reason" is required.']);

        $this->actingAs($this->requester)
            ->post(route('pos-requests.store'), $this->payload($requestType, [], ['reason' => 'Menu refresh']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pos-requests.index'));

        $this->assertSame(['reason' => 'Menu refresh'], PosRequest::firstOrFail()->form_data);
    }

    private function payload(RequestType $requestType, array $details, array $formData = []): array
    {
        return [
            'company_id' => $this->company->id,
            'request_type_id' => $requestType->id,
            'launch_date' => now()->toDateString(),
            'stores_covered' => ['all'],
            'form_data' => $formData,
            'details' => $details,
        ];
    }

    private function requestType(array $fields = []): RequestType
    {
        static $sequence = 0;
        $sequence++;

        return RequestType::create([
            'code' => "POS-REQUIRED-{$sequence}",
            'name' => "POS Required Test {$sequence}",
            'request_for' => ['POS'],
            'approval_levels' => 0,
            'approver_matrix' => [],
            'form_schema' => [
                'fields' => $fields,
                'has_items' => true,
                'item_label' => 'Product Item Detail',
                'items_columns' => [
                    ['key' => 'product_name', 'label' => 'Product Name', 'type' => 'text', 'required' => true],
                    ['key' => 'printer_assignment', 'label' => 'Printer Assignment', 'type' => 'checkbox_group', 'required' => true, 'options' => [
                        ['label' => 'BAR', 'value' => 'bar'],
                        ['label' => 'KITCHEN', 'value' => 'kitchen'],
                    ]],
                    ['key' => 'delivery', 'label' => 'Delivery', 'type' => 'text', 'required' => false],
                ],
            ],
            'is_active' => true,
        ]);
    }
}
