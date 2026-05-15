<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpcStatus;
use App\Models\Store;
use App\Models\User;
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

        foreach (['npc_status.view', 'npc_status.create', 'npc_status.edit', 'npc_status.delete'] as $permission) {
            Permission::findOrCreate($permission);
        }

        Storage::fake('public');
    }

    public function test_can_create_npc_status_with_optional_uploads(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();

        $response = $this->actingAs($user)->post(route('npc-statuses.store'), [
            'company_id' => $company->id,
            'year' => 2026,
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'status' => 'Pending',
            'dpo_seal' => UploadedFile::fake()->create('seal.pdf', 100, 'application/pdf'),
            'dpo_registration' => UploadedFile::fake()->image('registration.png'),
        ]);

        $response->assertRedirect();

        $npcStatus = NpcStatus::firstOrFail();
        $this->assertSame($company->id, $npcStatus->company_id);
        $this->assertSame(2026, $npcStatus->year);
        $this->assertSame('Pending', $npcStatus->status);
        Storage::disk('public')->assertExists($npcStatus->dpo_seal_path);
        Storage::disk('public')->assertExists($npcStatus->dpo_registration_path);
    }

    public function test_can_create_npc_status_with_one_gb_dpo_uploads(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.create');
        $company = $this->company();

        $response = $this->actingAs($user)->post(route('npc-statuses.store'), [
            'company_id' => $company->id,
            'year' => 2026,
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'status' => 'Pending',
            'dpo_seal' => UploadedFile::fake()->create('seal.pdf', 1024000, 'application/pdf'),
            'dpo_registration' => UploadedFile::fake()->create('registration.pdf', 1024000, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $npcStatus = NpcStatus::firstOrFail();
        Storage::disk('public')->assertExists($npcStatus->dpo_seal_path);
        Storage::disk('public')->assertExists($npcStatus->dpo_registration_path);
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
                ->where('filters.year', 2026)
                ->has('npcStatuses.data', 2)
                ->has('stores', 1)
                ->where('stores.0.assigned_company_id', $companyWithStatus->id)
            );
    }

    public function test_replacing_dpo_file_deletes_previous_file(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('npc_status.edit');
        $npcStatus = $this->npcStatus();

        $this->actingAs($user)->post(route('npc-statuses.update', $npcStatus), [
            '_method' => 'put',
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'status' => 'Approved',
            'dpo_seal' => UploadedFile::fake()->create('first-seal.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $firstPath = $npcStatus->fresh()->dpo_seal_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($user)->post(route('npc-statuses.update', $npcStatus), [
            '_method' => 'put',
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'status' => 'Approved',
            'dpo_seal' => UploadedFile::fake()->create('second-seal.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $npcStatus->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($npcStatus->dpo_seal_path);
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
