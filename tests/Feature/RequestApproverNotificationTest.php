<?php

namespace Tests\Feature;

use App\Mail\PosRequestNotification;
use App\Mail\SapRequestNotification;
use App\Models\Company;
use App\Models\RequestType;
use App\Models\User;
use App\Services\PosRequestService;
use App\Services\SapRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RequestApproverNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_pos_creation_notifies_current_level_approver_and_existing_recipients(): void
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $requestType = RequestType::create([
            'code' => 'POS-APPROVER',
            'name' => 'POS Approver Test',
            'request_for' => ['POS'],
            'approval_levels' => 1,
            'approver_matrix' => [
                ['level' => 1, 'user_ids' => [$approver->id]],
            ],
            'cc_emails' => 'cc@example.com',
            'form_schema' => [],
            'is_active' => true,
        ]);

        app(PosRequestService::class)->createRequest([
            'company_id' => $company->id,
            'request_type_id' => $requestType->id,
            'launch_date' => now()->toDateString(),
            'stores_covered' => ['all'],
        ], $requester->id);

        Mail::assertSent(PosRequestNotification::class, fn ($mail) =>
            $mail->hasTo($approver->email)
            && $mail->isApprover
            && $mail->approvalLevel === 1
        );
        Mail::assertSent(PosRequestNotification::class, fn ($mail) =>
            $mail->hasTo($requester->email)
            && $mail->isRequester
        );
        Mail::assertSent(PosRequestNotification::class, fn ($mail) =>
            $mail->hasTo('cc@example.com')
            && ! $mail->isRequester
            && ! $mail->isApprover
        );
    }

    public function test_sap_creation_notifies_static_current_level_approver(): void
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);

        $requestType = RequestType::create([
            'code' => 'SAP-APPROVER',
            'name' => 'SAP Approver Test',
            'request_for' => ['SAP'],
            'approval_levels' => 1,
            'approver_matrix' => [
                ['level' => 1, 'user_ids' => [$approver->id]],
            ],
            'form_schema' => [],
            'is_active' => true,
        ]);

        app(SapRequestService::class)->createRequest([
            'company_id' => $company->id,
            'request_type_id' => $requestType->id,
            'form_data' => ['description' => 'Static approver test'],
        ], $requester->id);

        Mail::assertSent(SapRequestNotification::class, fn ($mail) =>
            $mail->hasTo($approver->email)
            && $mail->isApprover
            && $mail->approvalLevel === 1
        );
    }

    public function test_sap_creation_notifies_dynamic_checkbox_approver(): void
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $dynamicApprover = User::factory()->create(['company_id' => $company->id]);

        $requestType = RequestType::create([
            'code' => 'SAP-DYNAMIC',
            'name' => 'SAP Dynamic Approver Test',
            'request_for' => ['SAP'],
            'approval_levels' => 0,
            'approver_matrix' => [],
            'form_schema' => [
                'fields' => [[
                    'key' => 'modules',
                    'label' => 'Modules',
                    'type' => 'checkbox_group',
                    'has_option_approvers' => true,
                    'options' => [[
                        'label' => 'Materials Management',
                        'value' => 'mm',
                        'approval_matrix' => [
                            ['level' => 1, 'user_ids' => [$dynamicApprover->id]],
                        ],
                    ]],
                ]],
            ],
            'is_active' => true,
        ]);

        app(SapRequestService::class)->createRequest([
            'company_id' => $company->id,
            'request_type_id' => $requestType->id,
            'form_data' => ['modules' => ['mm']],
        ], $requester->id);

        Mail::assertSent(SapRequestNotification::class, fn ($mail) =>
            $mail->hasTo($dynamicApprover->email)
            && $mail->isApprover
            && $mail->approvalLevel === 1
        );
    }

    public function test_pos_approval_advancement_notifies_next_level_approver(): void
    {
        Mail::fake();

        Permission::create(['name' => 'pos_requests.approve']);

        $company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $levelOneApprover = User::factory()->create(['company_id' => $company->id]);
        $levelTwoApprover = User::factory()->create(['company_id' => $company->id]);
        $levelOneApprover->givePermissionTo('pos_requests.approve');

        $requestType = RequestType::create([
            'code' => 'POS-TWO-LEVEL',
            'name' => 'POS Two Level Test',
            'request_for' => ['POS'],
            'approval_levels' => 2,
            'approver_matrix' => [
                ['level' => 1, 'user_ids' => [$levelOneApprover->id]],
                ['level' => 2, 'user_ids' => [$levelTwoApprover->id]],
            ],
            'form_schema' => [],
            'is_active' => true,
        ]);

        $posRequest = app(PosRequestService::class)->createRequest([
            'company_id' => $company->id,
            'request_type_id' => $requestType->id,
            'launch_date' => now()->toDateString(),
            'stores_covered' => ['all'],
        ], $requester->id);

        $this->actingAs($levelOneApprover)
            ->post(route('pos-requests.approve', $posRequest), ['remarks' => 'Approved'])
            ->assertRedirect();

        Mail::assertSent(PosRequestNotification::class, fn ($mail) =>
            $mail->hasTo($levelTwoApprover->email)
            && $mail->isApprover
            && $mail->approvalLevel === 2
        );
    }

    public function test_no_approver_email_is_sent_when_no_approval_is_required(): void
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Company', 'code' => 'TEST', 'is_active' => true]);
        $requester = User::factory()->create(['company_id' => $company->id]);

        $requestType = RequestType::create([
            'code' => 'SAP-NO-APPROVAL',
            'name' => 'SAP No Approval Test',
            'request_for' => ['SAP'],
            'approval_levels' => 0,
            'approver_matrix' => [],
            'form_schema' => [],
            'is_active' => true,
        ]);

        app(SapRequestService::class)->createRequest([
            'company_id' => $company->id,
            'request_type_id' => $requestType->id,
            'form_data' => ['description' => 'No approval test'],
        ], $requester->id);

        Mail::assertNotSent(SapRequestNotification::class, fn ($mail) => $mail->isApprover);
    }
}
