<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * /vendors reads the accreditation files the vendor uploaded in the portal. The
 * rows are shared through the database; the FILE is on the portal's disk, so
 * both ways of reaching it are covered here.
 */
class VendorDocumentPanelTest extends TestCase
{
    use RefreshDatabase;

    private string $portalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Stands in for linkportal's public storage on a shared filesystem.
        $this->portalRoot = storage_path('framework/testing/portal-storage');
        File::ensureDirectoryExists($this->portalRoot);
        config(['services.linkportal.documents_root' => $this->portalRoot]);
        config(['services.linkportal.base_url' => 'https://portal.test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->portalRoot);

        parent::tearDown();
    }

    private function staff(array $permissions = ['vendors.view']): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function portalVendor(): Vendor
    {
        return Vendor::create([
            'name' => 'ABC Supplies Inc',
            'code' => 'VND-2026-00005',
            'email' => 'abc@example.com',
            'is_active' => true,
        ]);
    }

    /** Writes the file where the portal would have put it, and returns the row. */
    private function document(Vendor $vendor, array $overrides = [], string $contents = '%PDF-1.4 test'): VendorDocument
    {
        $relative = "portal/vendors/{$vendor->id}/documents/permit.pdf";
        File::ensureDirectoryExists(dirname($this->portalRoot . '/' . $relative));
        File::put($this->portalRoot . '/' . $relative, $contents);

        $typeId = DB::table('portal_reference_options')->insertGetId([
            'type' => 'document_type',
            'value' => 'mayors_permit',
            'label' => "Mayor's / Business Permit",
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // forceCreate: the model has no $fillable on purpose — this app only
        // ever reads these rows, the portal is what writes them.
        return VendorDocument::query()->forceCreate(array_merge([
            'vendor_id' => $vendor->id,
            'document_type_id' => $typeId,
            'title' => "Mayor's Business Permit 2026",
            'file_path' => $relative,
            'file_name' => 'Mayors_Permit_2026.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1468006,
            'issued_date' => '2026-01-15',
            'expiry_date' => '2026-12-31',
            'version' => 1,
            'status' => VendorDocument::STATUS_APPROVED,
        ], $overrides));
    }

    public function test_it_returns_every_field_the_portal_captured_on_upload(): void
    {
        $vendor = $this->portalVendor();
        $reviewer = $this->staff();
        $this->document($vendor, [
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_remarks' => 'Matches the SEC registration.',
        ]);

        $payload = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/documents")
            ->assertOk()
            ->json('documents.0');

        $this->assertSame("Mayor's Business Permit 2026", $payload['name']);
        $this->assertSame("Mayor's / Business Permit", $payload['category']);
        $this->assertSame('pdf', $payload['file_type']);
        $this->assertSame('PDF', $payload['extension']);
        $this->assertSame('1.4 MB', $payload['file_size']);
        $this->assertSame('Approved', $payload['status']);
        $this->assertSame('Jan 15, 2026', $payload['issued_date']);
        $this->assertSame('Dec 31, 2026', $payload['expiry_date']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame($reviewer->name, $payload['reviewed_by']);
        $this->assertSame('Matches the SEC registration.', $payload['review_remarks']);
        $this->assertNotNull($payload['file_url']);
    }

    public function test_it_flags_expired_and_expiring_documents(): void
    {
        $vendor = $this->portalVendor();
        $this->document($vendor, ['expiry_date' => now()->subDay()->toDateString()]);

        $expired = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/documents")
            ->json('documents.0');

        $this->assertTrue($expired['is_expired']);
        $this->assertFalse($expired['is_expiring_soon']);

        VendorDocument::query()->update(['expiry_date' => now()->addDays(10)->toDateString()]);

        $soon = $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/documents")
            ->json('documents.0');

        $this->assertFalse($soon['is_expired']);
        $this->assertTrue($soon['is_expiring_soon']);
    }

    public function test_it_streams_the_file_inline_and_as_a_download(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor);
        $staff = $this->staff();

        $inline = $this->actingAs($staff)->get("/vendors/{$vendor->id}/documents/{$document->id}/file");
        $inline->assertOk();
        $inline->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline;', $inline->headers->get('content-disposition'));
        $this->assertSame('%PDF-1.4 test', $inline->getContent());

        $download = $this->actingAs($staff)->get("/vendors/{$vendor->id}/documents/{$document->id}/file?download=1");
        $this->assertStringContainsString('attachment;', $download->headers->get('content-disposition'));
        $this->assertStringContainsString('Mayors_Permit_2026.pdf', $download->headers->get('content-disposition'));
    }

    public function test_it_falls_back_to_the_portal_over_http_when_no_shared_path_is_configured(): void
    {
        config(['services.linkportal.documents_root' => null]);
        Http::fake(['portal.test/*' => Http::response('remote-bytes', 200)]);

        $vendor = $this->portalVendor();
        $document = $this->document($vendor);

        $response = $this->actingAs($this->staff())
            ->get("/vendors/{$vendor->id}/documents/{$document->id}/file");

        $response->assertOk();
        $this->assertSame('remote-bytes', $response->getContent());
        Http::assertSent(fn ($request) => $request->url() === 'https://portal.test/storage/' . $document->file_path);
    }

    public function test_a_document_cannot_be_read_through_another_vendors_url(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor);

        $otherVendor = Vendor::create(['name' => 'Unrelated Vendor', 'is_active' => true]);

        $this->actingAs($this->staff())
            ->get("/vendors/{$otherVendor->id}/documents/{$document->id}/file")
            ->assertNotFound();
    }

    public function test_a_crafted_file_path_cannot_escape_the_portal_storage_root(): void
    {
        config(['services.linkportal.base_url' => null]);

        $vendor = $this->portalVendor();
        $document = $this->document($vendor);
        // Written straight to the column: no legitimate upload produces this.
        $document->forceFill(['file_path' => '../../../../.env'])->save();

        $this->actingAs($this->staff())
            ->get("/vendors/{$vendor->id}/documents/{$document->id}/file")
            ->assertNotFound();
    }

    public function test_both_endpoints_are_gated_on_the_vendors_permission(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor);
        $outsider = $this->staff([]);

        $this->actingAs($outsider)->getJson("/vendors/{$vendor->id}/documents")->assertForbidden();
        $this->actingAs($outsider)->get("/vendors/{$vendor->id}/documents/{$document->id}/file")->assertForbidden();
    }

    public function test_a_pending_document_can_be_accredited(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor, ['status' => VendorDocument::STATUS_PENDING]);
        $reviewer = $this->staff(['vendors.view', 'vendor-documents.approve']);

        $response = $this->actingAs($reviewer)
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", ['action' => 'approved'])
            ->assertOk();

        $document->refresh();
        $this->assertSame(VendorDocument::STATUS_APPROVED, $document->status);
        $this->assertSame($reviewer->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);
        // The panel swaps in the row the server decided on.
        $this->assertSame('Approved', $response->json('document.status'));
        $this->assertSame($reviewer->name, $response->json('document.reviewed_by'));
    }

    public function test_a_refused_document_must_say_why(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor, ['status' => VendorDocument::STATUS_PENDING]);
        $reviewer = $this->staff(['vendors.view', 'vendor-documents.approve']);

        $this->actingAs($reviewer)
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", ['action' => 'rejected'])
            ->assertJsonValidationErrors('remarks');

        $this->assertSame(VendorDocument::STATUS_PENDING, $document->refresh()->status);

        $this->actingAs($reviewer)
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", [
                'action' => 'rejected',
                'remarks' => 'The permit has expired — upload the 2026 renewal.',
            ])
            ->assertOk();

        $document->refresh();
        $this->assertSame(VendorDocument::STATUS_REJECTED, $document->status);
        $this->assertSame('The permit has expired — upload the 2026 renewal.', $document->review_remarks);
    }

    public function test_a_document_is_decided_on_only_once(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor, ['status' => VendorDocument::STATUS_APPROVED]);

        $this->actingAs($this->staff(['vendors.view', 'vendor-documents.approve']))
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", ['action' => 'rejected', 'remarks' => 'Changed my mind.'])
            ->assertStatus(409);

        $this->assertSame(VendorDocument::STATUS_APPROVED, $document->refresh()->status);
    }

    /**
     * Accrediting a FILE is not deciding on the ACCOUNT: neither the vendor's
     * portal status nor its is_active flag may move.
     */
    public function test_reviewing_a_document_never_touches_the_vendor_account(): void
    {
        $vendor = $this->portalVendor();
        $vendor->forceFill(['status' => 'pending', 'is_active' => false])->save();
        $document = $this->document($vendor, ['status' => VendorDocument::STATUS_PENDING]);

        $this->actingAs($this->staff(['vendors.view', 'vendor-documents.approve']))
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", ['action' => 'approved'])
            ->assertOk();

        $vendor->refresh();
        $this->assertSame('pending', $vendor->status);
        $this->assertFalse($vendor->is_active);
        $this->assertNull($vendor->approved_at);
    }

    public function test_reviewing_needs_the_document_permission_not_merely_vendor_access(): void
    {
        $vendor = $this->portalVendor();
        $document = $this->document($vendor, ['status' => VendorDocument::STATUS_PENDING]);

        // vendors.approve decides on ACCOUNTS — it must not carry documents too.
        $this->actingAs($this->staff(['vendors.view', 'vendors.approve']))
            ->putJson("/vendors/{$vendor->id}/documents/{$document->id}/review", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertSame(VendorDocument::STATUS_PENDING, $document->refresh()->status);
    }

    public function test_the_listing_says_whether_this_account_may_review(): void
    {
        $vendor = $this->portalVendor();
        $this->document($vendor);

        $this->actingAs($this->staff(['vendors.view']))
            ->getJson("/vendors/{$vendor->id}/documents")
            ->assertJsonPath('can_review', false);

        $this->actingAs($this->staff(['vendors.view', 'vendor-documents.approve']))
            ->getJson("/vendors/{$vendor->id}/documents")
            ->assertJsonPath('can_review', true);
    }

    public function test_a_vendor_with_no_uploads_returns_an_empty_list(): void
    {
        $vendor = $this->portalVendor();

        $this->actingAs($this->staff())
            ->getJson("/vendors/{$vendor->id}/documents")
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }
}
