<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\VoucherBatch;
use App\Models\VoucherRedemption;
use App\Jobs\GenerateVoucherBatchPdf;
use App\Services\VoucherService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VoucherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $cashier;
    private Store $store;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Coffee Bean & Tea Leaf', 'code' => 'CBTL', 'type' => 'Entity', 'is_active' => true]);
        $this->cashier = User::factory()->create(['company_id' => $this->company->id]);
        foreach (['stamps.view', 'stamps.create', 'stamps.edit', 'stamps.redeem', 'stamps.approve', 'stamps.cancel', 'stamps.export'] as $name) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->store = Store::create([
            'code' => 'CBTL-001', 'name' => 'Test Store', 'class' => 'Regular', 'sector' => 1,
            'area' => 'Metro Manila', 'brand' => 'CBTL', 'cluster' => 'Test',
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->session = [CompanyContext::SESSION_KEY => $this->company->id];
    }

    public function test_generation_creates_the_exact_number_of_unique_scanner_safe_codes(): void
    {
        $batch = $this->batch(['quantity' => 50]);
        $this->assertIsInt($batch->fresh()->company_id);
        app(VoucherService::class)->generateCodes($batch);

        $this->assertSame(50, $batch->vouchers()->count());
        $this->assertSame(50, $batch->vouchers()->distinct('code')->count('code'));
        $this->assertTrue($batch->vouchers->every(fn ($v) => preg_match('/^VCH-[2-9A-HJ-NP-Z]{4}(?:-[2-9A-HJ-NP-Z]{4}){3}$/', $v->code) === 1));
    }

    public function test_verification_is_read_only_and_logs_the_attempt(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $voucher = $this->activeVoucher();

        $response = $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.verify'), ['code' => strtolower($voucher->code), 'store_id' => $this->store->id]);

        $response->assertOk()->assertJsonPath('result', 'active')->assertJsonPath('voucher.code', $voucher->code);
        $this->assertSame('issued', $voucher->fresh()->status);
        $this->assertDatabaseHas('voucher_verification_attempts', ['voucher_id' => $voucher->id, 'result' => 'active', 'verified_by' => $this->cashier->id]);
    }

    public function test_redemption_marks_used_links_customer_and_calculates_no_change(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $voucher = $this->activeVoucher();

        $response = $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.redeem'), [
                'code' => $voucher->code, 'new_customer_name' => 'Globe Customer',
                'new_customer_phone' => '09171234567', 'store_id' => $this->store->id,
                'receipt_number' => 'OR-1001', 'sale_date' => '2026-09-15', 'gross_sale_total' => 120,
            ]);

        $response->assertOk()->assertJsonPath('redemption.applied_amount', '120.00')->assertJsonPath('redemption.forfeited_amount', '30.00');
        $this->assertSame('used', $voucher->fresh()->status);
        $this->assertDatabaseHas('customers', ['name' => 'Globe Customer', 'phone' => '09171234567']);
        $this->assertDatabaseHas('voucher_sale_claims', ['voucher_redemption_id' => $response->json('redemption.id')]);

        $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.verify'), ['code' => $voucher->code, 'store_id' => $this->store->id])
            ->assertJsonPath('result', 'already_used')
            ->assertJsonPath('voucher.redemption.customer.name', 'Globe Customer')
            ->assertJsonPath('voucher.redemption.receipt_number', 'OR-1001');
    }

    public function test_redemption_workspace_and_partial_batches_serialize_effective_status_safely(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $voucher = $this->activeVoucher();
        $customer = Customer::create(['name' => 'Serialization Customer', 'phone' => '09170000009', 'is_active' => true]);

        app(VoucherService::class)->redeem([
            'code' => $voucher->code, 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'receipt_number' => 'OR-SERIALIZE', 'sale_date' => '2026-09-15', 'gross_sale_total' => 150,
        ], $this->cashier->id, $this->company->id);

        $props = \App\Http\Controllers\VoucherController::indexProps($this->company->id);
        $redemptions = $props['voucherRedemptions']->toArray();
        $this->assertSame('active', $redemptions[0]['voucher']['batch']['effective_status']);

        $partial = VoucherBatch::query()->select(['id', 'title'])->findOrFail($voucher->batch->id)->toArray();
        $this->assertSame('unknown', $partial['effective_status']);
    }

    public function test_only_one_voucher_can_be_applied_to_a_store_date_and_receipt(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $first = $this->activeVoucher();
        $second = $first->batch->vouchers()->whereKeyNot($first->id)->firstOrFail();
        $customer = Customer::create(['name' => 'Existing Customer', 'phone' => '09170000000', 'is_active' => true]);
        $payload = ['customer_id' => $customer->id, 'store_id' => $this->store->id, 'receipt_number' => 'OR-2001', 'sale_date' => '2026-09-15', 'gross_sale_total' => 300];

        $this->actingAs($this->cashier)->withSession($this->session)->postJson(route('stamps.vouchers.redeem'), ['code' => $first->code] + $payload)->assertOk();
        $this->actingAs($this->cashier)->withSession($this->session)->postJson(route('stamps.vouchers.redeem'), ['code' => $second->code] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_number');
        $this->assertSame('issued', $second->fresh()->status);
    }

    public function test_manager_reversal_preserves_history_and_restores_voucher_and_receipt(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $voucher = $this->activeVoucher();
        $customer = Customer::create(['name' => 'Customer', 'phone' => '09170000001', 'is_active' => true]);
        $redemption = app(VoucherService::class)->redeem([
            'code' => $voucher->code, 'customer_id' => $customer->id, 'store_id' => $this->store->id,
            'receipt_number' => 'OR-3001', 'sale_date' => '2026-09-15', 'gross_sale_total' => 150,
        ], $this->cashier->id, $this->company->id);

        $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.voucher-redemptions.void', $redemption), ['reason' => 'Wrong customer'])
            ->assertOk();

        $this->assertSame('issued', $voucher->fresh()->status);
        $this->assertNotNull($redemption->fresh()->voided_at);
        $this->assertDatabaseMissing('voucher_sale_claims', ['voucher_redemption_id' => $redemption->id]);
    }

    public function test_claim_dates_and_batch_state_control_verification(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        $voucher = $this->activeVoucher();
        $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.verify'), ['code' => $voucher->code])->assertJsonPath('result', 'not_yet_valid');

        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.verify'), ['code' => $voucher->code])->assertJsonPath('result', 'expired');

        Carbon::setTestNow('2026-09-15 10:00:00');
        $voucher->batch->update(['status' => 'suspended']);
        $this->actingAs($this->cashier)->withSession($this->session)
            ->postJson(route('stamps.vouchers.verify'), ['code' => $voucher->code])->assertJsonPath('result', 'suspended');
    }

    public function test_claim_period_can_be_changed_after_activation_and_invalidates_print_pdf(): void
    {
        Storage::fake('local');
        $batch = $this->batch([
            'pdf_status' => 'ready',
            'pdf_path' => 'voucher-pdfs/existing.pdf',
            'pdf_generated_at' => now(),
        ]);
        Storage::disk('local')->put($batch->pdf_path, '%PDF existing');

        $this->actingAs($this->cashier)->withSession($this->session)
            ->post(route('stamps.voucher-batches.claim-period', $batch), [
                'claim_starts_on' => '2026-09-20',
                'claim_ends_on' => '2026-10-15',
            ])->assertRedirect();

        $batch->refresh();
        $this->assertSame('2026-09-20', $batch->claim_starts_on->format('Y-m-d'));
        $this->assertSame('2026-10-15', $batch->claim_ends_on->format('Y-m-d'));
        $this->assertSame('not_generated', $batch->pdf_status);
        $this->assertNull($batch->pdf_path);
        Storage::disk('local')->assertMissing('voucher-pdfs/existing.pdf');
    }

    public function test_pdf_job_creates_a_private_barcode_print_file(): void
    {
        Storage::fake('local');
        $batch = $this->batch(['quantity' => 19, 'status' => 'draft']);
        app(VoucherService::class)->generateCodes($batch);

        (new GenerateVoucherBatchPdf($batch->id, $this->cashier->id))->handle();

        $batch->refresh();
        $this->assertSame('ready', $batch->pdf_status);
        Storage::disk('local')->assertExists($batch->pdf_path);
        $pdf = Storage::disk('local')->get($batch->pdf_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertLessThan(200_000, strlen($pdf));
    }

    public function test_manager_can_activate_a_batch_and_generate_its_pdf_without_a_queue_worker(): void
    {
        Storage::fake('local');
        $batch = $this->batch(['status' => 'draft']);
        app(VoucherService::class)->generateCodes($batch);

        $this->actingAs($this->cashier)->withSession($this->session)
            ->post(route('stamps.voucher-batches.activate', $batch))->assertRedirect();
        $this->assertSame('active', $batch->fresh()->status);

        $this->actingAs($this->cashier)->withSession($this->session)
            ->post(route('stamps.voucher-batches.pdf', $batch))->assertRedirect();

        $batch->refresh();
        $this->assertSame('ready', $batch->pdf_status);
        Storage::disk('local')->assertExists($batch->pdf_path);
    }

    public function test_an_abandoned_pdf_request_becomes_retryable(): void
    {
        $batch = $this->batch(['pdf_status' => 'queued']);
        $batch->forceFill(['updated_at' => now()->subMinutes(7)])->saveQuietly();

        $this->assertTrue($batch->fresh()->pdf_is_stale);
    }

    public function test_pdf_job_timeout_cannot_leave_a_batch_processing_forever(): void
    {
        $job = new GenerateVoucherBatchPdf(123, $this->cashier->id);

        $this->assertSame(300, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_stamp_pdf_poll_always_clears_consumed_flash_messages(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withSession($this->session)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Stamps/Index',
                'X-Inertia-Partial-Data' => 'voucherBatches',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('stamps.index', ['tab' => 'vouchers']));

        $response->assertOk()->assertJsonPath('props.flash.success', null);
    }

    private function batch(array $overrides = []): VoucherBatch
    {
        return VoucherBatch::create(array_merge([
            'company_id' => $this->company->id, 'partner_name' => 'Globe', 'title' => 'Globe ₱150 Voucher',
            'quantity' => 2, 'face_value' => 150, 'turnover_date' => '2026-09-09',
            'claim_starts_on' => '2026-09-10', 'claim_ends_on' => '2026-09-30', 'status' => 'active',
            'created_by' => $this->cashier->id, 'updated_by' => $this->cashier->id,
        ], $overrides));
    }

    private function activeVoucher(?VoucherBatch $batch = null)
    {
        $batch ??= $this->batch();
        $service = app(VoucherService::class);
        if (! $batch->vouchers()->exists()) $service->generateCodes($batch);
        return $batch->vouchers()->where('status', 'issued')->whereNotIn('id', VoucherRedemption::pluck('voucher_id'))->first();
    }
}
