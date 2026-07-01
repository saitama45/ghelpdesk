<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\Store;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NpcStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['npc_status.view', 'npc_status.create', 'npc_status.edit', 'npc_status.delete', 'npc_status.download'] as $permission) {
            Permission::findOrCreate($permission);
        }

        Storage::fake('public');
    }

    public function test_can_create_npc_status_without_dpo_uploads(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();

        $response = $this->actingAs($user)->post(route('npc-statuses.store'), [
            'company_id' => $company->id,
            'year' => 2026,
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
        ]);

        $response->assertRedirect();

        $npcStatus = NpcStatus::firstOrFail();
        $this->assertSame($company->id, $npcStatus->company_id);
        $this->assertSame(2026, $npcStatus->year);
        $this->assertSame('Active', $npcStatus->status);
        $this->assertCount(0, $npcStatus->attachments);
        $this->assertNull($npcStatus->dpo_seal_path);
        $this->assertNull($npcStatus->dpo_registration_path);
    }

    public function test_can_upload_one_gb_dpo_attachment(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $response = $this->actingAs($user)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('seal.pdf', 1024000, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $npcStatus->refresh();
        Storage::disk('public')->assertExists($npcStatus->dpo_seal_path);
        $this->assertSame(1, $npcStatus->attachments()->count());
    }

    public function test_index_returns_all_entities_for_selected_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.view');
        $companyWithStatus = $this->company(['code' => 'IDX1']);
        $this->company(['code' => 'IDX2']);
        $npcStatus = $this->npcStatus(['company_id' => $companyWithStatus->id]);
        $store = $this->store();
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $this->actingAs($user)
            ->get(route('npc-statuses.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('NpcStatus/Index')
                ->where('viewMode', 'admin')
                ->where('defaultNpcSection', 'monitoring')
                ->where('currentYear', 2026)
                ->has('npcStatuses.data', 2)
                ->has('stores', 1)
                ->where('stores.0.assigned_company_id', $companyWithStatus->id)
            );
    }

    public function test_dedicated_dpo_uploads_are_kept_as_file_history(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('seal.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->actingAs($user)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_REGISTRATION,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('registration.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $npcStatus->refresh();
        Storage::disk('public')->assertExists($npcStatus->dpo_seal_path);
        Storage::disk('public')->assertExists($npcStatus->dpo_registration_path);
        $this->assertSame(2, $npcStatus->attachments()->count());
    }

    public function test_index_includes_all_year_attachment_history_for_entity(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo('npc_status.edit');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('npc_status.view');
        $company = $this->company(['code' => 'HIS']);
        $status2026 = $this->npcStatus(['company_id' => $company->id]);
        $status2027 = $this->npcStatus([
            'company_id' => $company->id,
            'year' => 2027,
            'validity_from' => '2027-01-01',
            'validity_to' => '2027-12-31',
        ]);

        $this->actingAs($editor)->post(route('npc-statuses.attachments.store', $status2026), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('seal-2026.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->actingAs($editor)->post(route('npc-statuses.attachments.store', $status2027), [
            'type' => NpcStatusAttachment::TYPE_DPO_REGISTRATION,
            'validity_from' => '2027-01-01',
            'file' => UploadedFile::fake()->create('registration-2027.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->actingAs($viewer)
            ->get(route('npc-statuses.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('npcStatuses.data.0.attachment_history.0.year', 2027)
                ->where('npcStatuses.data.0.attachment_history.0.attachments.dpo_registration.0.name', 'registration-2027.pdf')
                ->where('npcStatuses.data.0.attachment_history.1.year', 2026)
                ->where('npcStatuses.data.0.attachment_history.1.attachments.dpo_seal.0.name', 'seal-2026.pdf')
            );
    }

    public function test_attachment_history_groups_by_file_validity_year_even_if_attached_to_wrong_record(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('npc_status.view');
        $company = $this->company(['code' => 'MIS']);
        $this->npcStatus([
            'company_id' => $company->id,
            'year' => 2025,
            'validity_from' => '2025-07-09',
            'validity_to' => '2026-07-09',
        ]);
        $status2026 = $this->npcStatus([
            'company_id' => $company->id,
            'year' => 2026,
            'validity_from' => '2026-07-09',
            'validity_to' => '2027-07-09',
        ]);

        $status2026->attachments()->create([
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2025-07-09',
            'file_path' => 'npc-statuses/misfile.pdf',
            'file_name' => 'CORSeal_CCTSI.pdf',
        ]);

        $this->actingAs($viewer)
            ->get(route('npc-statuses.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('npcStatuses.data.0.attachment_history.0.year', 2026)
                ->has('npcStatuses.data.0.attachment_history.0.attachments.dpo_seal', 0)
                ->where('npcStatuses.data.0.attachment_history.1.year', 2025)
                ->where('npcStatuses.data.0.attachment_history.1.attachments.dpo_seal.0.name', 'CORSeal_CCTSI.pdf')
            );
    }

    public function test_deleting_only_attachment_clears_legacy_columns(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('first-seal.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $firstPath = $npcStatus->fresh()->dpo_seal_path;

        $attachment = $npcStatus->fresh()->attachments()->where('file_name', 'first-seal.pdf')->firstOrFail();

        $this->actingAs($user)
            ->delete(route('npc-status-attachments.destroy', $attachment))
            ->assertRedirect();

        $npcStatus->refresh();
        $this->assertNull($npcStatus->dpo_seal_path);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_attachment_upload_rejects_mismatched_validity_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)
            ->from(route('npc-statuses.index'))
            ->post(route('npc-statuses.attachments.store', $npcStatus), [
                'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
                'validity_from' => '2027-01-01',
                'file' => UploadedFile::fake()->create('seal.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('npc-statuses.index'))
            ->assertSessionHasErrors('validity_from');

        $this->assertSame(0, $npcStatus->attachments()->count());
    }

    public function test_duplicate_dpo_attachment_for_same_type_and_validity_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $payload = [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('duplicate-seal.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($user)
            ->post(route('npc-statuses.attachments.store', $npcStatus), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->from(route('npc-statuses.index'))
            ->post(route('npc-statuses.attachments.store', $npcStatus), [
                'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
                'validity_from' => '2026-07-01',
                'file' => UploadedFile::fake()->create('duplicate-seal.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('npc-statuses.index'))
            ->assertSessionHasErrors('file');

        $this->assertSame(1, $npcStatus->attachments()->count());
    }

    public function test_same_filename_can_be_uploaded_for_different_dpo_type_in_same_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)
            ->post(route('npc-statuses.attachments.store', $npcStatus), [
                'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
                'validity_from' => '2026-01-01',
                'file' => UploadedFile::fake()->create('shared-name.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('npc-statuses.attachments.store', $npcStatus), [
                'type' => NpcStatusAttachment::TYPE_DPO_REGISTRATION,
                'validity_from' => '2026-01-01',
                'file' => UploadedFile::fake()->create('shared-name.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $npcStatus->attachments()->count());
    }

    public function test_duplicate_company_validity_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();
        $this->npcStatus(['company_id' => $company->id]);

        $this->actingAs($user)
            ->from(route('npc-statuses.index'))
            ->post(route('npc-statuses.store'), [
                'company_id' => $company->id,
                'validity_from' => '2026-02-01',
                'validity_to' => '2026-12-31',
            ])
            ->assertRedirect(route('npc-statuses.index'))
            ->assertSessionHasErrors('validity_from');
    }

    public function test_json_duplicate_company_validity_year_returns_existing_workflow_without_error(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();
        $npcStatus = $this->npcStatus(['company_id' => $company->id]);

        $this->actingAs($user)
            ->postJson(route('npc-statuses.store'), [
                'company_id' => $company->id,
                'validity_from' => '2026-02-01',
                'validity_to' => '2026-12-31',
            ])
            ->assertOk()
            ->assertJsonPath('existing_record', true)
            ->assertJsonPath('message', null)
            ->assertJsonPath('company.npc_status.id', $npcStatus->id)
            ->assertJsonCount(count(NpcStatus::WORKFLOW_STEPS), 'company.npc_status.workflow_steps');

        $this->assertSame(
            count(NpcStatus::WORKFLOW_STEPS),
            $npcStatus->workflowSteps()->count()
        );
    }

    public function test_company_lookup_returns_fresh_current_year_workflow(): void
    {
        $this->travelTo('2026-07-01');

        $activeCompany = $this->company();
        $user = User::factory()->create(['company_id' => $activeCompany->id]);
        $user->givePermissionTo('npc_status.view');
        $company = $this->company();
        $npcStatus = $this->npcStatus(['company_id' => $company->id]);

        foreach (NpcStatus::WORKFLOW_STEPS as $step) {
            $npcStatus->workflowSteps()->create([
                'key' => $step['key'],
                'label' => $step['label'],
                'sort_order' => $step['sort_order'],
            ]);
        }

        CompanyContext::flushMemo();

        $this->actingAs($user)
            ->withSession([CompanyContext::SESSION_KEY => $activeCompany->id])
            ->getJson(route('npc-statuses.companies.show', $company))
            ->assertOk()
            ->assertJsonPath('company.id', $company->id)
            ->assertJsonPath('company.npc_status.id', $npcStatus->id)
            ->assertJsonCount(count(NpcStatus::WORKFLOW_STEPS), 'company.npc_status.workflow_steps');
    }

    public function test_existing_renewal_cannot_be_moved_to_another_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)
            ->from(route('npc-statuses.index'))
            ->post(route('npc-statuses.update', $npcStatus), [
                '_method' => 'put',
                'validity_from' => '2027-01-01',
                'validity_to' => '2027-12-31',
            ])
            ->assertRedirect(route('npc-statuses.index'))
            ->assertSessionHasErrors('validity_from');

        $this->assertSame(2026, $npcStatus->fresh()->year);
    }

    public function test_automatic_renewal_statuses_are_returned_for_selected_year(): void
    {
        $this->travelTo('2026-05-22');

        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.view');
        $this->npcStatus([
            'company_id' => $this->company(['code' => 'ACT'])->id,
            'validity_to' => '2026-12-31',
        ]);
        $this->npcStatus([
            'company_id' => $this->company(['code' => 'WIN'])->id,
            'validity_to' => '2026-08-01',
        ]);
        $this->npcStatus([
            'company_id' => $this->company(['code' => 'CRI'])->id,
            'validity_to' => '2026-06-01',
        ]);
        $this->npcStatus([
            'company_id' => $this->company(['code' => 'DUE'])->id,
            'validity_to' => '2026-05-22',
        ]);
        $this->npcStatus([
            'company_id' => $this->company(['code' => 'OVR'])->id,
            'validity_to' => '2026-05-01',
        ]);

        $this->actingAs($user)
            ->get(route('npc-statuses.index', ['year' => 2026, 'per_page' => 100]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusCounts.all.entities', 5)
                ->where('statusCounts.active.entities', 1)
                ->where('statusCounts.for_renewal.entities', 4)
            );
    }

    public function test_validity_from_sets_record_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();

        $this->actingAs($user)->post(route('npc-statuses.store'), [
            'company_id' => $company->id,
            'year' => 2025,
            'validity_from' => '2027-02-01',
            'validity_to' => '2028-01-31',
        ])->assertRedirect();

        $this->assertDatabaseHas('npc_statuses', [
            'company_id' => $company->id,
            'year' => 2027,
        ]);
    }

    public function test_workflow_steps_compute_stage_and_progress(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)
            ->put(route('npc-statuses.workflow.update', $npcStatus), [
                'steps' => [
                    ['key' => 'form_completion', 'is_done' => true, 'completed_at' => '2026-01-02', 'remarks' => 'Done'],
                    ['key' => 'documents_uploading', 'is_done' => true, 'completed_at' => '2026-01-03', 'remarks' => null],
                    ['key' => 'application_signing', 'is_done' => true, 'completed_at' => '2026-01-04', 'remarks' => null],
                    ['key' => 'npc_approval', 'is_done' => false, 'completed_at' => null, 'remarks' => 'Waiting'],
                    ['key' => 'payment_processing', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                    ['key' => 'store_distribution', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                ],
            ])
            ->assertRedirect();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('npc_status.view');

        $this->actingAs($viewer)
            ->get(route('npc-statuses.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('npcStatuses.data.0.npc_status.workflow_stage', 'Waiting for NPC Approval')
                ->where('npcStatuses.data.0.npc_status.workflow_progress', 50)
                ->where('npcStatuses.data.0.npc_status.workflow_steps.0.remarks', 'Done')
            );
    }

    public function test_index_includes_all_year_workflow_history_for_entity(): void
    {
        $editor = User::factory()->create();
        $editor->givePermissionTo('npc_status.edit');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('npc_status.view');
        $company = $this->company(['code' => 'WFH']);
        $status2026 = $this->npcStatus(['company_id' => $company->id]);
        $status2027 = $this->npcStatus([
            'company_id' => $company->id,
            'year' => 2027,
            'validity_from' => '2027-01-01',
            'validity_to' => '2027-12-31',
        ]);

        $this->actingAs($editor)
            ->put(route('npc-statuses.workflow.update', $status2027), [
                'steps' => [
                    ['key' => 'form_completion', 'is_done' => true, 'completed_at' => '2027-01-05', 'remarks' => '2027 renewal started'],
                    ['key' => 'documents_uploading', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                    ['key' => 'application_signing', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                    ['key' => 'npc_approval', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                    ['key' => 'payment_processing', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                    ['key' => 'store_distribution', 'is_done' => false, 'completed_at' => null, 'remarks' => null],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($viewer)
            ->get(route('npc-statuses.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('npcStatuses.data.0.workflow_history.0.id', $status2027->id)
                ->where('npcStatuses.data.0.workflow_history.0.year', 2027)
                ->where('npcStatuses.data.0.workflow_history.0.workflow_progress', 17)
                ->where('npcStatuses.data.0.workflow_history.0.workflow_steps.0.remarks', '2027 renewal started')
                ->where('npcStatuses.data.0.workflow_history.1.id', $status2026->id)
                ->where('npcStatuses.data.0.workflow_history.1.year', 2026)
            );
    }

    public function test_incomplete_historical_renewal_is_editable_until_workflow_and_all_seals_are_confirmed(): void
    {
        $this->travelTo('2026-07-01');

        $editor = User::factory()->create();
        $editor->givePermissionTo('npc_status.edit');
        $company = $this->company(['code' => 'HISTEDIT']);
        $historicalStatus = $this->npcStatus([
            'company_id' => $company->id,
            'year' => 2025,
            'validity_from' => '2025-01-01',
            'validity_to' => '2025-12-31',
        ]);
        $store = $this->store();
        $historicalStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2025]);

        $this->actingAs($editor)
            ->putJson(route('npc-statuses.update', $historicalStatus), [
                'validity_from' => '2025-02-01',
                'validity_to' => '2025-12-31',
            ])
            ->assertOk();

        $steps = collect(NpcStatus::WORKFLOW_STEPS)->map(fn (array $step) => [
            'key' => $step['key'],
            'is_done' => true,
            'completed_at' => '2025-12-01',
            'remarks' => 'Completed',
        ])->all();

        $this->actingAs($editor)
            ->putJson(route('npc-statuses.workflow.update', $historicalStatus), ['steps' => $steps])
            ->assertOk();

        foreach (NpcStatusAttachment::SEAL_TYPES as $type) {
            $this->actingAs($editor)
                ->postJson(route('npc-statuses.stores.seal.confirm', [$historicalStatus, $store, $type]), [
                    'confirmed' => true,
                ])
                ->assertOk();
        }

        $this->actingAs($editor)
            ->getJson(route('npc-statuses.companies.show', [$company, 'year' => 2025]))
            ->assertOk()
            ->assertJsonPath('company.workflow_history.0.id', $historicalStatus->id)
            ->assertJsonPath('company.workflow_history.0.is_finalized', true)
            ->assertJsonCount(1, 'company.workflow_history.0.store_receipts')
            ->assertJsonPath('stores.0.assigned_npc_status_id', $historicalStatus->id);

        $this->actingAs($editor)
            ->putJson(route('npc-statuses.update', $historicalStatus), [
                'validity_from' => '2025-03-01',
                'validity_to' => '2025-12-31',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('npc_status');
    }

    public function test_store_has_one_replaceable_cctv_seal_notice(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $store = $this->store();

        $this->actingAs($user)
            ->post(route('stores.cctv-seal-notice.store', $store), [
                'file' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $firstPath = $store->fresh()->cctv_seal_notice_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($user)
            ->post(route('stores.cctv-seal-notice.store', $store), [
                'file' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $store->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($store->cctv_seal_notice_path);
        $this->assertSame('second.pdf', $store->cctv_seal_notice_name);
    }

    public function test_store_can_only_be_assigned_to_one_entity_per_year(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');

        $firstStatus = $this->npcStatus(['company_id' => $this->company(['code' => 'C01'])->id]);
        $secondStatus = $this->npcStatus(['company_id' => $this->company(['code' => 'C02'])->id]);
        $store = $this->store();

        $firstStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $response = $this->actingAs($user)
            ->from(route('npc-statuses.index'))
            ->put(route('npc-statuses.stores.update', $secondStatus), [
                'store_ids' => [$store->id],
            ]);

        $response->assertRedirect(route('npc-statuses.index'));
        $response->assertSessionHasErrors('store_ids');
        $this->assertDatabaseMissing('npc_status_store', [
            'npc_status_id' => $secondStatus->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_delete_removes_files_and_store_tags(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.delete');
        $npcStatus = $this->npcStatus();
        $store = $this->store();

        Storage::disk('public')->put('npc-statuses/test-seal.pdf', 'seal');
        Storage::disk('public')->put('npc-statuses/test-registration.pdf', 'registration');
        $npcStatus->update([
            'dpo_seal_path' => 'npc-statuses/test-seal.pdf',
            'dpo_registration_path' => 'npc-statuses/test-registration.pdf',
        ]);
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $this->actingAs($user)
            ->delete(route('npc-statuses.destroy', $npcStatus))
            ->assertRedirect();

        $this->assertDatabaseMissing('npc_statuses', ['id' => $npcStatus->id]);
        $this->assertDatabaseMissing('npc_status_store', ['npc_status_id' => $npcStatus->id]);
        Storage::disk('public')->assertMissing('npc-statuses/test-seal.pdf');
        Storage::disk('public')->assertMissing('npc-statuses/test-registration.pdf');
    }

    public function test_store_user_download_records_receipt_and_notifies_admins(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $admin = User::factory()->create();
        $admin->givePermissionTo(['npc_status.view', 'npc_status.edit']);

        $storeUser = User::factory()->create();
        $storeUser->givePermissionTo('npc_status.download');

        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $store->users()->attach($storeUser->id);
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $this->actingAs($admin)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_CCTV_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('cctv.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($storeUser)
            ->get(route('npc-statuses.stores.seal.download', [$npcStatus, $store, NpcStatusAttachment::TYPE_CCTV_SEAL]))
            ->assertOk();

        $this->assertDatabaseHas('npc_seal_receipts', [
            'npc_status_id' => $npcStatus->id,
            'store_id' => $store->id,
            'seal_type' => NpcStatusAttachment::TYPE_CCTV_SEAL,
            'downloaded_by' => $storeUser->id,
        ]);

        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \App\Notifications\ActivityNotification::class);
    }

    public function test_download_does_not_mark_store_checked_until_admin_confirms(): void
    {
        $storeUser = User::factory()->create();
        $storeUser->givePermissionTo('npc_status.download');
        $admin = User::factory()->create();
        $admin->givePermissionTo('npc_status.edit');

        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $store->users()->attach($storeUser->id);
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $this->actingAs($admin)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('seal.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($storeUser)
            ->get(route('npc-statuses.stores.seal.download', [$npcStatus, $store, NpcStatusAttachment::TYPE_DPO_SEAL]))
            ->assertOk();

        $this->assertNull($npcStatus->sealReceipts()->where('store_id', $store->id)->first()->confirmed_at);

        $this->actingAs($admin)
            ->post(route('npc-statuses.stores.seal.confirm', [$npcStatus, $store, NpcStatusAttachment::TYPE_DPO_SEAL]), [
                'confirmed' => true,
            ])
            ->assertRedirect();

        $this->assertNotNull($npcStatus->sealReceipts()->where('store_id', $store->id)->first()->confirmed_at);
    }

    public function test_json_download_request_records_receipt_before_browser_streams_file(): void
    {
        $storeUser = User::factory()->create();
        $storeUser->givePermissionTo('npc_status.download');

        $admin = User::factory()->create();
        $admin->givePermissionTo('npc_status.edit');

        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $store->users()->attach($storeUser->id);
        $npcStatus->stores()->attach($store->id, ['year' => $npcStatus->year]);

        $this->actingAs($admin)->post(route('npc-statuses.attachments.store', $npcStatus), [
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file' => UploadedFile::fake()->create('seal.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($storeUser)
            ->getJson(route('npc-statuses.stores.seal.download', [
                $npcStatus,
                $store,
                NpcStatusAttachment::TYPE_DPO_SEAL,
            ]));

        $response->assertOk()
            ->assertJsonPath('download_url', route('npc-statuses.stores.seal.download', [
                $npcStatus,
                $store,
                NpcStatusAttachment::TYPE_DPO_SEAL,
            ]))
            ->assertJsonPath(
                'downloaded_at',
                fn ($value) => is_string($value)
                    && preg_match('/T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1
            );

        $this->assertDatabaseHas('npc_seal_receipts', [
            'npc_status_id' => $npcStatus->id,
            'store_id' => $store->id,
            'seal_type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'downloaded_by' => $storeUser->id,
        ]);
    }

    public function test_store_user_cannot_download_seal_for_unassigned_store(): void
    {
        $storeUser = User::factory()->create();
        $storeUser->givePermissionTo('npc_status.download');

        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $store->users()->attach($storeUser->id);
        // store is NOT assigned to the npc status this year

        $this->actingAs($storeUser)
            ->get(route('npc-statuses.stores.seal.download', [$npcStatus, $store, NpcStatusAttachment::TYPE_DPO_SEAL]))
            ->assertNotFound();
    }

    public function test_store_user_sees_download_view(): void
    {
        $storeUser = User::factory()->create();
        $storeUser->givePermissionTo('npc_status.download');

        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $store->users()->attach($storeUser->id);
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);

        $this->actingAs($storeUser)
            ->get(route('npc-statuses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewMode', 'store')
                ->where('defaultNpcSection', 'downloads')
                ->where('storeSeals.0.store_id', $store->id)
                ->where('storeSeals.0.years.0.year', 2026)
            );
    }

    public function test_view_and_download_user_only_receives_seals_for_user_assigned_stores(): void
    {
        $this->travelTo('2026-07-01');

        $user = User::factory()->create();
        $user->givePermissionTo(['npc_status.view', 'npc_status.download']);

        $npcStatus = $this->npcStatus();
        $allowedStore = $this->store(['name' => 'Allowed Store']);
        $otherStore = $this->store(['name' => 'Other Store']);
        $user->stores()->attach($allowedStore->id);
        $npcStatus->stores()->syncWithPivotValues(
            [$allowedStore->id, $otherStore->id],
            ['year' => 2026]
        );
        $hiddenStatus = $this->npcStatus();
        $hiddenStore = $this->store(['name' => 'Hidden Store']);
        $hiddenStatus->stores()->syncWithPivotValues([$hiddenStore->id], ['year' => 2026]);

        $this->actingAs($user)
            ->get(route('npc-statuses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewMode', 'admin')
                ->where('canDownloadAssignedSeals', true)
                ->where('defaultNpcSection', 'downloads')
                ->has('npcStatuses.data', 1)
                ->where('npcStatuses.data.0.id', $npcStatus->company_id)
                ->where('npcStatuses.data.0.store_count', 1)
                ->has('npcStatuses.data.0.npc_status.store_receipts', 1)
                ->where('statusCounts.all.entities', 1)
                ->where('statusCounts.all.stores', 1)
                ->has('stores', 1)
                ->where('stores.0.id', $allowedStore->id)
                ->has('storeSeals', 1)
                ->where('storeSeals.0.store_id', $allowedStore->id)
            );

        $this->actingAs($user)
            ->get(route('npc-statuses.companies.show', $hiddenStatus->company_id))
            ->assertNotFound();
    }

    public function test_view_and_download_user_without_assigned_stores_receives_no_store_seals(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['npc_status.view', 'npc_status.download']);

        $npcStatus = $this->npcStatus();
        $npcStatus->stores()->syncWithPivotValues([$this->store()->id], ['year' => 2026]);

        $this->actingAs($user)
            ->get(route('npc-statuses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewMode', 'admin')
                ->where('canDownloadAssignedSeals', true)
                ->where('defaultNpcSection', 'downloads')
                ->has('storeSeals', 0)
            );
    }

    public function test_view_and_download_user_cannot_use_admin_download_or_mutation_routes(): void
    {
        Storage::disk('public')->put('npc-statuses/restricted-seal.pdf', 'seal');

        $user = User::factory()->create();
        $user->givePermissionTo(['npc_status.view', 'npc_status.download']);
        $npcStatus = $this->npcStatus();
        $store = $this->store();
        $npcStatus->stores()->syncWithPivotValues([$store->id], ['year' => 2026]);
        $attachment = $npcStatus->attachments()->create([
            'type' => NpcStatusAttachment::TYPE_DPO_SEAL,
            'validity_from' => '2026-01-01',
            'file_path' => 'npc-statuses/restricted-seal.pdf',
            'file_name' => 'restricted-seal.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4,
        ]);

        $this->actingAs($user)
            ->get(route('npc-status-attachments.download', $attachment))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('npc-statuses.stores.seal.download', [
                $npcStatus,
                $store,
                NpcStatusAttachment::TYPE_DPO_SEAL,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('npc-statuses.update', $npcStatus), [
                'validity_from' => '2026-01-01',
                'validity_to' => '2026-12-31',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('npc-statuses.workflow.update', $npcStatus), ['steps' => []])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('npc-statuses.stores.update', $npcStatus), ['store_ids' => [$store->id]])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('npc-statuses.stores.seal.confirm', [
                $npcStatus,
                $store,
                NpcStatusAttachment::TYPE_DPO_SEAL,
            ]), ['confirmed' => true])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('npc-status-attachments.destroy', $attachment))
            ->assertForbidden();
    }

    private function company(array $overrides = []): Company
    {
        static $count = 1;

        return Company::create(array_merge([
            'name' => 'Company ' . $count,
            'code' => 'CMP' . str_pad((string) $count++, 3, '0', STR_PAD_LEFT),
            'description' => 'Test company',
            'is_active' => true,
        ], $overrides));
    }

    private function npcStatus(array $overrides = []): NpcStatus
    {
        return NpcStatus::create(array_merge([
            'company_id' => $overrides['company_id'] ?? $this->company()->id,
            'year' => 2026,
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'status' => 'Pending',
        ], $overrides));
    }

    private function store(array $overrides = []): Store
    {
        static $count = 1;

        return Store::create(array_merge([
            'code' => 'STR' . str_pad((string) $count, 3, '0', STR_PAD_LEFT),
            'name' => 'Store ' . $count++,
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'class' => 'Regular',
            'is_active' => true,
        ], $overrides));
    }
}
