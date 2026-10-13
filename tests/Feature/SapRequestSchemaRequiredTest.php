<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\RequestType;
use App\Models\SapRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SapRequestSchemaRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->company = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        Store::create([
            'code' => 'CFE I',
            'name' => 'CFE I',
            'sector' => 1,
            'area' => 'Corporate',
            'brand' => 'TGI',
            'class' => 'Office',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->requester = User::factory()->create(['company_id' => $this->company->id]);

        Permission::create(['name' => 'sap_requests.create']);
        $this->requester->givePermissionTo('sap_requests.create');
    }

    public function test_required_line_item_cells_left_empty_are_rejected(): void
    {
        $this->actingAs($this->requester)
            ->post(route('sap-requests.store'), $this->payload($this->itemsType(), ['entity' => ['tgi']], [
                ['item_name' => 'Arabica Beans 1kg', 'withholding_tax_liable' => 'yes', 'remarks' => ''],
                ['item_name' => '', 'withholding_tax_liable' => '', 'remarks' => ''],
            ]))
            ->assertSessionHasErrors([
                'items.1.item_name' => 'Item #2: "Item Name" is required.',
                'items.1.withholding_tax_liable' => 'Item #2: "Withholding Tax Liable" is required.',
                'items.0.vendor_item_quotation',
                'items.1.vendor_item_quotation',
            ])
            ->assertSessionDoesntHaveErrors([
                'form_data.entity', 'items.0.item_name', 'items.0.withholding_tax_liable', 'items.0.remarks', 'items.1.remarks',
            ]);

        $this->assertSame(0, SapRequest::count());
    }

    public function test_required_checkbox_and_radio_fields_left_empty_are_rejected(): void
    {
        $requestType = $this->fieldsType();

        // JSON bodies send an unticked group as []; multipart bodies drop the key entirely.
        foreach ([['entity' => []], []] as $entity) {
            $this->actingAs($this->requester)
                ->post(route('sap-requests.store'), $this->payload($requestType, $entity + ['vendor_type' => 'supplier']))
                ->assertSessionHasErrors([
                    'form_data.entity' => '"Entity" is required.',
                    'form_data.subject_to_withholding_tax' => '"Subject to Withholding Tax" is required.',
                ])
                ->assertSessionDoesntHaveErrors(['form_data.vendor_type', 'form_data.employee_name']);
        }

        $this->assertSame(0, SapRequest::count());
    }

    public function test_conditional_field_is_required_only_while_shown(): void
    {
        $requestType = $this->fieldsType();
        $answers = ['entity' => ['tgi'], 'subject_to_withholding_tax' => 'no'];

        $this->actingAs($this->requester)
            ->post(route('sap-requests.store'), $this->payload($requestType, $answers + ['vendor_type' => 'employee']))
            ->assertSessionHasErrors(['form_data.employee_name' => '"Employee Name" is required.']);

        $this->assertSame(0, SapRequest::count());

        $this->actingAs($this->requester)
            ->post(route('sap-requests.store'), $this->payload($requestType, $answers + ['vendor_type' => 'supplier']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sap-requests.index'));

        $this->assertSame(1, SapRequest::count());
    }

    public function test_complete_request_with_an_upload_is_created_with_its_ticket(): void
    {
        $this->actingAs($this->requester)
            ->post(route('sap-requests.store'), $this->payload($this->itemsType(), ['entity' => ['tgi']], [[
                'item_name' => 'Arabica Beans 1kg',
                'withholding_tax_liable' => 'no',
                'vendor_item_quotation' => [UploadedFile::fake()->create('quotation.pdf', 12, 'application/pdf')],
            ]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sap-requests.index'));

        $sapRequest = SapRequest::with('items')->firstOrFail();
        $ticket = Ticket::withoutGlobalScopes()->findOrFail($sapRequest->ticket_id);

        $this->assertSame('quotation.pdf', $sapRequest->items->first()->item_data['vendor_item_quotation'][0]['name']);
        $this->assertStringContainsString('Item Name: Arabica Beans 1kg', $ticket->description);
    }

    private function payload(RequestType $requestType, array $formData, array $items = []): array
    {
        return [
            'company_id' => $this->company->id,
            'request_type_id' => $requestType->id,
            'form_data' => $formData,
            'items' => $items,
        ];
    }

    private function entityField(): array
    {
        return ['key' => 'entity', 'label' => 'Entity', 'type' => 'checkbox_group', 'required' => true, 'options' => [
            ['label' => 'TGI', 'value' => 'tgi'],
            ['label' => 'CBTL', 'value' => 'cbtl'],
        ]];
    }

    private function itemsType(): RequestType
    {
        return $this->requestType('SAP-REQ-ITEMS', [
            'fields' => [$this->entityField()],
            'has_items' => true,
            'items_columns' => [
                ['key' => 'item_name', 'label' => 'Item Name', 'type' => 'text', 'required' => true],
                ['key' => 'withholding_tax_liable', 'label' => 'Withholding Tax Liable', 'type' => 'radio', 'required' => true, 'options' => [
                    ['label' => 'Yes', 'value' => 'yes'],
                    ['label' => 'No', 'value' => 'no'],
                ]],
                ['key' => 'vendor_item_quotation', 'label' => 'Vendor Item Quotation', 'type' => 'file', 'multiple' => true, 'required' => true],
                ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'required' => false],
            ],
        ]);
    }

    private function fieldsType(): RequestType
    {
        return $this->requestType('SAP-REQ-FIELDS', [
            'fields' => [
                $this->entityField(),
                ['key' => 'vendor_type', 'label' => 'Vendor Type', 'type' => 'select', 'required' => true, 'options' => [
                    ['label' => 'Supplier', 'value' => 'supplier'],
                    ['label' => 'Employee', 'value' => 'employee'],
                ]],
                ['key' => 'employee_name', 'label' => 'Employee Name', 'type' => 'text', 'required' => true,
                    'show_when' => ['field' => 'vendor_type', 'value' => 'employee']],
                ['key' => 'subject_to_withholding_tax', 'label' => 'Subject to Withholding Tax', 'type' => 'radio', 'required' => true, 'options' => [
                    ['label' => 'Yes', 'value' => 'yes'],
                    ['label' => 'No', 'value' => 'no'],
                ]],
            ],
            'has_items' => false,
            'items_columns' => [],
        ]);
    }

    private function requestType(string $code, array $schema): RequestType
    {
        return RequestType::create([
            'code' => $code,
            'name' => $code,
            'request_for' => ['SAP'],
            'approval_levels' => 0,
            'approver_matrix' => [],
            'form_schema' => $schema,
            'is_active' => true,
        ]);
    }
}
