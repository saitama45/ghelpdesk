<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DynamicForms\DefaultFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertEquals('Open', $record->status);
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
}
