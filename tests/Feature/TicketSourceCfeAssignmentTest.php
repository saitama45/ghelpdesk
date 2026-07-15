<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosRequest;
use App\Models\RequestType;
use App\Models\SapRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\PosRequestService;
use App\Services\SapRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketSourceCfeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $tgi;

    private Company $requestCompany;

    private Store $cfeStore;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgi = Company::create(['name' => 'TGI', 'code' => 'TGI', 'is_active' => true]);
        $this->requestCompany = Company::create([
            'name' => 'Request Company',
            'code' => 'REQUEST',
            'is_active' => true,
        ]);
        $this->cfeStore = Store::create([
            'code' => 'CFE I',
            'name' => 'CFE I',
            'sector' => 1,
            'area' => 'Corporate',
            'brand' => 'TGI',
            'class' => 'Office',
            'is_active' => true,
            'company_id' => $this->tgi->id,
        ]);
        $this->user = User::factory()->create(['company_id' => $this->requestCompany->id]);
    }

    public function test_sap_ticket_is_always_assigned_to_cfe_and_tgi(): void
    {
        $requestType = $this->requestType('SAP-CFE', 'SAP CFE Assignment', ['SAP']);
        $sapRequest = SapRequest::create([
            'company_id' => $this->requestCompany->id,
            'request_type_id' => $requestType->id,
            'user_id' => $this->user->id,
            'status' => 'Approved',
            'current_approval_level' => 0,
            'form_data' => ['reason' => 'Test fixed assignment'],
        ]);

        app(SapRequestService::class)->processApprovedRequest($sapRequest);
        $ticket = $sapRequest->fresh()->ticket;

        $this->assertSame($this->cfeStore->id, $ticket->store_id);
        $this->assertSame($this->tgi->id, $ticket->company_id);
        $this->assertStringStartsWith('TGI-', $ticket->ticket_key);
    }

    public function test_pos_ticket_is_always_assigned_to_cfe_and_tgi(): void
    {
        $requestType = $this->requestType('POS-CFE', 'POS CFE Assignment', ['POS']);
        $posRequest = PosRequest::create([
            'company_id' => $this->requestCompany->id,
            'request_type_id' => $requestType->id,
            'user_id' => $this->user->id,
            'launch_date' => now()->toDateString(),
            'effectivity_date' => now()->toDateString(),
            'stores_covered' => ['all'],
            'status' => 'Approved',
            'current_approval_level' => 0,
            'form_data' => [],
        ]);

        app(PosRequestService::class)->processApprovedRequest($posRequest);
        $ticket = $posRequest->fresh()->ticket;

        $this->assertSame($this->cfeStore->id, $ticket->store_id);
        $this->assertSame($this->tgi->id, $ticket->company_id);
        $this->assertStringStartsWith('TGI-', $ticket->ticket_key);
    }

    private function requestType(string $code, string $name, array $requestFor): RequestType
    {
        return RequestType::create([
            'code' => $code,
            'name' => $name,
            'request_for' => $requestFor,
            'approval_levels' => 0,
            'approver_matrix' => [],
            'form_schema' => [],
            'is_active' => true,
        ]);
    }
}
