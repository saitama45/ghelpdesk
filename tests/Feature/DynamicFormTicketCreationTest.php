<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FormDefinition;
use App\Models\FormRecordApproval;
use App\Models\FormRecord;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DynamicForms\DefaultFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicFormTicketCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_dynamic_form_with_zero_approvals_immediately_creates_ticket(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Zero Approval Form',
            'slug' => 'zero-approval-form',
            'workflow_type' => 'sequential',
            'approval_levels' => 0,
            'is_active' => true,
        ]);

        $requestType = RequestType::create([
            'code' => 'ZERO-APP',
            'name' => 'Zero Approval Request Type',
            'approval_levels' => 0,
            'is_active' => true,
        ]);

        $formDefinition->requestTypes()->attach($requestType);

        $request = new \Illuminate\Http\Request([
            'request_type_id' => $requestType->id,
            'form_data' => [
                'reason' => 'Immediate ticket please',
            ],
        ]);

        $this->actingAs($user);

        $record = app(DefaultFormService::class)->store($request, $formDefinition);

        $this->assertEquals('Approved', $record->status);
        $this->assertNotNull($record->ticket_id);

        $ticket = Ticket::find($record->ticket_id);
        $this->assertNotNull($ticket);
        $this->assertEquals($company->id, $ticket->company_id);
        $this->assertEquals('open', $ticket->status);
        $this->assertStringContainsString('TCOMP-', $ticket->ticket_key);
        $this->assertStringContainsString('Immediate ticket please', $ticket->description);
    }

    public function test_dynamic_form_resolves_company_stored_as_display_name(): void
    {
        $company = Company::create([
            'name' => 'The Table Group, Inc. (TGI)',
            'code' => 'TGI',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['company_id' => null]);
        $formDefinition = FormDefinition::create([
            'name' => 'TGI Work Tools',
            'slug' => 'tgi-work-tools',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $record = app(DefaultFormService::class)->store(new \Illuminate\Http\Request([
            'form_data' => ['company' => 'The Table Group, Inc. (TGI)'],
        ]), $formDefinition);

        $ticket = Ticket::findOrFail($record->ticket_id);

        $this->assertSame($company->id, $ticket->company_id);
        $this->assertStringStartsWith('TGI-', $ticket->ticket_key);
    }

    public function test_ticket_uses_external_key_when_no_company_can_be_resolved(): void
    {
        $user = User::factory()->create(['company_id' => null]);
        $formDefinition = FormDefinition::create([
            'name' => 'Companyless Form',
            'slug' => 'companyless-form',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $record = app(DefaultFormService::class)->store(
            new \Illuminate\Http\Request(['form_data' => ['reason' => 'No entity']]),
            $formDefinition
        );

        $ticket = Ticket::findOrFail($record->ticket_id);

        $this->assertNull($ticket->company_id);
        $this->assertStringStartsWith('EXT-', $ticket->ticket_key);
    }

    public function test_replaying_the_same_submission_token_reuses_the_original_record_and_ticket(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $formDefinition = FormDefinition::create([
            'name' => 'Idempotent Form',
            'slug' => 'idempotent-form',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'is_active' => true,
        ]);
        $submissionToken = (string) Str::uuid();

        $this->actingAs($user);
        $service = app(DefaultFormService::class);
        $first = $service->store(new \Illuminate\Http\Request([
            'submission_token' => $submissionToken,
            'form_data' => ['reason' => 'Create this once'],
        ]), $formDefinition);
        $replay = $service->store(new \Illuminate\Http\Request([
            'submission_token' => $submissionToken,
            'form_data' => ['reason' => 'Create this once'],
        ]), $formDefinition);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame($first->ticket_id, $replay->ticket_id);
        $this->assertFalse($replay->wasRecentlyCreated);
        $this->assertSame(1, FormRecord::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_ticket_creation_failure_rolls_back_the_dynamic_form_record(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $formDefinition = FormDefinition::create([
            'name' => 'Transactional Form',
            'slug' => 'transactional-form',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'is_active' => true,
        ]);
        $service = new class extends DefaultFormService
        {
            public function processApprovedRequest(FormDefinition $formDefinition, FormRecord $record): void
            {
                throw new \RuntimeException('Simulated ticket failure');
            }
        };

        $this->actingAs($user);

        try {
            $service->store(new \Illuminate\Http\Request([
                'submission_token' => (string) Str::uuid(),
                'form_data' => ['reason' => 'Must roll back'],
            ]), $formDefinition);
            $this->fail('Expected ticket creation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ticket failure', $exception->getMessage());
        }

        $this->assertSame(0, FormRecord::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_completing_sequential_approvals_creates_ticket_at_final_stage(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver1 = User::factory()->create(['company_id' => $company->id]);
        $approver2 = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Two Level Sequential Form',
            'slug' => 'two-level-form',
            'workflow_type' => 'sequential',
            'approval_levels' => 2,
            'is_active' => true,
        ]);

        $requestType = RequestType::create([
            'code' => 'TWO-SEQ',
            'name' => 'Two Level Sequential',
            'approval_levels' => 2,
            'approver_matrix' => [
                ['level' => 1, 'user_ids' => [$approver1->id]],
                ['level' => 2, 'user_ids' => [$approver2->id]],
            ],
            'is_active' => true,
        ]);

        $formDefinition->requestTypes()->attach($requestType);

        $request = new \Illuminate\Http\Request([
            'request_type_id' => $requestType->id,
            'form_data' => [
                'reason' => 'Approve me twice',
            ],
        ]);

        $this->actingAs($requester);
        $record = app(DefaultFormService::class)->store($request, $formDefinition);

        $this->assertEquals('Open', $record->status);
        $this->assertEquals(1, $record->current_approval_level);
        $this->assertNull($record->ticket_id);

        // Approve level 1
        $this->actingAs($approver1);
        $approveRequest = new \Illuminate\Http\Request([
            'remarks' => 'Looks good',
        ]);
        app(DefaultFormService::class)->approve($approveRequest, $formDefinition, $record);

        $record->refresh();
        $this->assertEquals('Approved Level 1', $record->status);
        $this->assertEquals(2, $record->current_approval_level);
        $this->assertNull($record->ticket_id);

        // Approve level 2 (final)
        $this->actingAs($approver2);
        $approveRequest2 = new \Illuminate\Http\Request([
            'remarks' => 'Fully approved',
        ]);
        app(DefaultFormService::class)->approve($approveRequest2, $formDefinition, $record);

        $record->refresh();
        $this->assertEquals('Approved', $record->status);
        $this->assertNotNull($record->ticket_id);

        $ticket = Ticket::find($record->ticket_id);
        $this->assertNotNull($ticket);
        $this->assertEquals($company->id, $ticket->company_id);
        $this->assertStringContainsString('TCOMP-', $ticket->ticket_key);
        $this->assertStringContainsString('Approve me twice', $ticket->description);
        $this->assertStringContainsString('Stage 1 Approved by: ' . $approver1->name, $ticket->description);
        $this->assertStringContainsString('Stage 2 Approved by: ' . $approver2->name, $ticket->description);
    }

    public function test_reapproving_final_record_does_not_create_duplicate_ticket(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'One Level Form',
            'slug' => 'one-level-form',
            'workflow_type' => 'approval',
            'approval_levels' => 1,
            'approver_matrix' => [
                ['level' => 1, 'user_ids' => [$approver->id]],
            ],
            'is_active' => true,
        ]);

        $request = new \Illuminate\Http\Request([
            'form_data' => ['reason' => 'Only one ticket'],
        ]);

        $this->actingAs($requester);
        $record = app(DefaultFormService::class)->store($request, $formDefinition);

        $this->actingAs($approver);
        app(DefaultFormService::class)->approve(new \Illuminate\Http\Request(), $formDefinition, $record);

        $record->refresh();
        $firstTicketId = $record->ticket_id;

        app(DefaultFormService::class)->approve(new \Illuminate\Http\Request(), $formDefinition, $record);

        $record->refresh();
        $this->assertEquals($firstTicketId, $record->ticket_id);
        $this->assertEquals(1, Ticket::count());
    }

    public function test_nested_array_values_do_not_break_ticket_description_creation(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Nested Array Form',
            'slug' => 'nested-array-form',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'form_schema' => [
                'fields' => [[
                    'key' => 'nested_payload',
                    'label' => 'Nested Payload',
                    'type' => 'text',
                ]],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $record = app(DefaultFormService::class)->store(new \Illuminate\Http\Request([
            'form_data' => [
                'nested_payload' => [
                    ['label' => 'first', 'meta' => ['code' => 'A1']],
                    ['label' => 'second', 'meta' => ['code' => 'B2']],
                ],
            ],
        ]), $formDefinition);

        $ticket = Ticket::find($record->ticket_id);

        $this->assertNotNull($ticket);
        $this->assertStringContainsString('Nested Payload', $ticket->description);
        $this->assertStringContainsString('Code: A1', $ticket->description);
        $this->assertStringContainsString('Code: B2', $ticket->description);
    }

    public function test_checklist_ticket_is_created_after_all_generated_tasks_are_complete(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Checklist Form',
            'slug' => 'checklist-form',
            'workflow_type' => 'checklist',
            'approval_levels' => 0,
            'form_schema' => [
                'fields' => [[
                    'key' => 'tasks',
                    'label' => 'Tasks',
                    'type' => 'checkbox_group',
                    'checklist_source' => true,
                    'checklist_assignees' => [$approver->id],
                    'options' => [
                        ['value' => 'verify', 'label' => 'Verify'],
                        ['value' => 'release', 'label' => 'Release'],
                    ],
                ]],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($requester);
        $record = app(DefaultFormService::class)->store(new \Illuminate\Http\Request([
            'form_data' => ['tasks' => ['verify', 'release']],
        ]), $formDefinition);

        $this->assertEquals('Open', $record->status);
        $this->assertCount(2, $record->data['_checklist_tasks']);

        $this->actingAs($approver);
        app(DefaultFormService::class)->approve(new \Illuminate\Http\Request(['force_level' => 1]), $formDefinition, $record);

        $record->refresh();
        $this->assertEquals('Open', $record->status);
        $this->assertNull($record->ticket_id);

        app(DefaultFormService::class)->approve(new \Illuminate\Http\Request(['force_level' => 2]), $formDefinition, $record);

        $record->refresh();
        $this->assertEquals('Approved', $record->status);
        $this->assertNotNull($record->ticket_id);
        $this->assertEquals(1, Ticket::count());
    }

    public function test_copying_zero_approval_dynamic_record_creates_ticket(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Copied Form',
            'slug' => 'copied-form',
            'workflow_type' => 'approval',
            'approval_levels' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('copy.transfer'), [
                'target_type' => 'dynamic',
                'target_id' => $formDefinition->slug,
                'payload' => [
                    'source_user_id' => $user->id,
                    'form_data' => [
                        'reason' => 'Copied ticket please',
                    ],
                ],
            ])
            ->assertOk();

        $record = FormRecord::first();

        $this->assertNotNull($record);
        $this->assertEquals('Approved', $record->status);
        $this->assertNotNull($record->ticket_id);
        $this->assertEquals(1, Ticket::count());
    }

    public function test_repair_command_finalizes_supplied_stuck_record_and_creates_ticket(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TCOMP', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $formDefinition = FormDefinition::create([
            'name' => 'Stuck Form',
            'slug' => 'stuck-form',
            'workflow_type' => 'approval',
            'approval_levels' => 1,
            'is_active' => true,
        ]);

        $record = FormRecord::create([
            'form_definition_id' => $formDefinition->id,
            'data' => ['reason' => 'Repair me'],
            'status' => 'Approved Level 1',
            'current_approval_level' => 2,
            'created_by' => $requester->id,
        ]);

        FormRecordApproval::create([
            'form_record_id' => $record->id,
            'user_id' => $approver->id,
            'level' => 1,
            'remarks' => 'Already approved',
        ]);

        $this->artisan('dynamic-forms:repair-tickets', [
            'ids' => [$record->id],
            '--dry-run' => true,
            '--finalize-stuck' => true,
        ])->assertSuccessful();

        $record->refresh();
        $this->assertNull($record->ticket_id);

        $this->artisan('dynamic-forms:repair-tickets', [
            'ids' => [$record->id],
            '--finalize-stuck' => true,
        ])->assertSuccessful();

        $record->refresh();
        $this->assertEquals('Approved', $record->status);
        $this->assertEquals(0, $record->current_approval_level);
        $this->assertNotNull($record->ticket_id);
        $this->assertEquals(1, Ticket::count());
    }
}
