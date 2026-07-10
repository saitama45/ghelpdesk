<?php

namespace Tests\Feature;

use App\Jobs\SendDecisionCallbackJob;
use App\Models\AcctDocumentReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountingDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function inboundPayload(array $overrides = []): array
    {
        return $overrides + [
            'idempotency_key' => 'lp-doc-1-s1',
            'source_document_id' => 1,
            'reference_no' => 'DOC-2026-00001',
            'document_type' => 'invoice',
            'vendor' => ['code' => 'VND-001', 'name' => 'Test Vendor', 'company' => 'Acme'],
            'fields' => ['invoice_no' => 'SI-100', 'total_amount' => 1456.00],
            'line_items' => [['description' => 'Widget A', 'quantity' => 10, 'uom' => 'PCS', 'unit_price' => 100, 'line_total' => 1000]],
            'confidence' => ['overall' => 0.95, 'fields' => ['invoice_no' => 0.99]],
            'exceptions' => [],
            'file_url' => 'https://linkportal.test/integrations/files/1?signature=abc',
            'callback_url' => 'https://linkportal.test/api/integrations/ghelpdesk/document-review-decision',
        ];
    }

    public function test_inbound_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/accounting/document-reviews', $this->inboundPayload())
            ->assertUnauthorized();
    }

    public function test_inbound_endpoint_creates_review(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->postJson('/api/accounting/document-reviews', $this->inboundPayload())
            ->assertCreated()
            ->assertJsonPath('review.status', 'pending');

        $review = AcctDocumentReview::firstOrFail();
        $this->assertSame('DOC-2026-00001', $review->source_reference_no);
        $this->assertSame(1, $review->source_document_id);
        $this->assertNotNull($review->due_at);
        $this->assertSame(1, $review->events()->where('event', 'received')->count());
    }

    public function test_inbound_replay_returns_existing_review(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $first = $this->postJson('/api/accounting/document-reviews', $this->inboundPayload())->json('review.id');
        $this->postJson('/api/accounting/document-reviews', $this->inboundPayload())
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('review.id', $first);

        $this->assertSame(1, AcctDocumentReview::count());
    }

    private function makeReview(): AcctDocumentReview
    {
        return AcctDocumentReview::create([
            'idempotency_key' => 'lp-doc-9-s1',
            'source_document_id' => 9,
            'source_reference_no' => 'DOC-2026-00009',
            'document_type' => 'invoice',
            'vendor_name' => 'Test Vendor',
            'status' => AcctDocumentReview::STATUS_PENDING,
            'callback_url' => 'https://linkportal.test/callback',
            'received_at' => now(),
        ]);
    }

    public function test_approve_dispatches_callback_job(): void
    {
        Queue::fake();
        $review = $this->makeReview();
        $user = $this->reviewer(['accounting-documents.view', 'accounting-documents.approve']);

        $this->actingAs($user)
            ->post(route('accounting-documents.approve', $review->id), ['remarks' => 'Looks good'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(AcctDocumentReview::STATUS_APPROVED, $review->status);
        $this->assertSame($user->id, $review->decided_by);
        Queue::assertPushed(SendDecisionCallbackJob::class, fn ($job) => $job->reviewId === $review->id);
    }

    public function test_return_requires_remarks(): void
    {
        Queue::fake();
        $review = $this->makeReview();
        $user = $this->reviewer(['accounting-documents.view', 'accounting-documents.return']);

        $this->actingAs($user)
            ->from(route('accounting-documents.show', $review->id))
            ->post(route('accounting-documents.return', $review->id), [])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(AcctDocumentReview::STATUS_PENDING, $review->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_decision_requires_permission(): void
    {
        Queue::fake();
        $review = $this->makeReview();
        $user = $this->reviewer(['accounting-documents.view']);

        $this->actingAs($user)
            ->post(route('accounting-documents.reject', $review->id), ['remarks' => 'no'])
            ->assertForbidden();
    }

    public function test_already_decided_review_cannot_be_decided_again(): void
    {
        Queue::fake();
        $review = $this->makeReview();
        $review->update(['status' => AcctDocumentReview::STATUS_APPROVED, 'decision' => 'approve']);
        $user = $this->reviewer(['accounting-documents.view', 'accounting-documents.reject']);

        $this->actingAs($user)
            ->post(route('accounting-documents.reject', $review->id), ['remarks' => 'changed my mind'])
            ->assertRedirect();

        $this->assertSame(AcctDocumentReview::STATUS_APPROVED, $review->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_callback_job_posts_decision_and_marks_sent(): void
    {
        Http::fake(['linkportal.test/*' => Http::response(['status' => 'ok'])]);

        $review = $this->makeReview();
        $decider = User::factory()->create(['is_active' => true]);
        $review->update([
            'status' => AcctDocumentReview::STATUS_RETURNED,
            'decision' => 'return',
            'decision_remarks' => 'Fix the PO number',
            'decided_by' => $decider->id,
            'decided_at' => now(),
        ]);

        (new SendDecisionCallbackJob($review->id))->handle();

        $review->refresh();
        $this->assertSame('sent', $review->callback_status);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'linkportal.test/callback')
                && $request['decision'] === 'return'
                && $request['remarks'] === 'Fix the PO number';
        });
    }

    public function test_callback_job_failure_records_error(): void
    {
        Http::fake(['linkportal.test/*' => Http::response(['error' => 'nope'], 500)]);

        $review = $this->makeReview();
        $review->update([
            'status' => AcctDocumentReview::STATUS_APPROVED,
            'decision' => 'approve',
            'decided_at' => now(),
        ]);

        $job = new SendDecisionCallbackJob($review->id);
        try {
            $job->handle();
            $this->fail('Expected callback failure to throw');
        } catch (\RuntimeException) {
            // expected: triggers queue retry
        }

        $this->assertSame('failed', $review->fresh()->callback_status);
    }
}
