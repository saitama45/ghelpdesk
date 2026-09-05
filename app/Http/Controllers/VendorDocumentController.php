<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Services\PortalDocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accreditation files a vendor submitted through the portal
 * (/vendor/documents). The back office never uploads or edits them — the portal
 * owns that — but it reads them and decides on them: each document is accredited
 * or refused here, which is a different decision from the vendor ACCOUNT's
 * approval matrix in VendorController.
 */
class VendorDocumentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:vendors.view'),
            // The portal's own permission for this decision — the permissions
            // table is shared, so the same reviewers govern both apps.
            new Middleware('can:vendor-documents.approve', only: ['review']),
        ];
    }

    /**
     * The vendor's documents, newest first. Fetched when the edit modal opens
     * rather than eager-loaded into the index: the listing paginates and most
     * vendors are reference records with nothing to show.
     */
    public function index(Request $request, Vendor $vendor, PortalDocumentStorage $storage)
    {
        $documents = VendorDocument::query()
            ->where('vendor_id', $vendor->id)
            ->with(['documentType:id,label', 'reviewer:id,name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'documents' => $documents->map(fn (VendorDocument $document) => $this->present($document, $storage))->all(),
            'can_review' => $request->user()->can('vendor-documents.approve'),
        ]);
    }

    /**
     * Streams the file through the back office instead of linking to the
     * portal: the response is same-origin (so "Download" actually downloads),
     * it stays behind `vendors.view`, and the portal's URL is never exposed.
     */
    public function file(Request $request, Vendor $vendor, VendorDocument $document, PortalDocumentStorage $storage)
    {
        // Route-model binding resolves the document on its own id, so the
        // vendor it belongs to has to be checked explicitly.
        abort_unless((int) $document->vendor_id === (int) $vendor->id, 404);

        $contents = $storage->contents($document);

        abort_if($contents === null, Response::HTTP_NOT_FOUND, 'This document is no longer available from the vendor portal.');

        $fileName = $document->file_name ?: 'document';

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')
                . '; filename="' . addslashes($fileName) . '"',
            // These are compliance records, and the URL is permission-gated.
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Accredits or refuses ONE uploaded document. Deliberately separate from the
     * vendor account's approval matrix: a vendor can be an approved account with
     * a rejected permit, and an accredited permit does not activate an account.
     *
     * Mirrors the portal's own review (Admin\VendorController@reviewDocument) so
     * a document reviewed here reads identically in the portal.
     */
    public function review(Request $request, Vendor $vendor, VendorDocument $document)
    {
        abort_unless((int) $document->vendor_id === (int) $vendor->id, 404);

        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            // A refusal must say why: the vendor is shown this and has to know
            // what to re-upload.
            'remarks' => 'nullable|string|max:1000|required_if:action,rejected',
        ]);

        // Same guard as the portal: a decision is made once, on a pending file.
        // Re-accrediting means uploading a new version.
        if ($document->status !== VendorDocument::STATUS_PENDING) {
            return response()->json([
                'message' => 'This document is not pending review.',
            ], Response::HTTP_CONFLICT);
        }

        $document->forceFill([
            'status' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['remarks'] ?? null,
        ])->save();

        $this->notifyVendor($document, $validated['action'], $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Document ' . $validated['action'] . '.',
            'document' => $this->present($document->fresh(['documentType', 'reviewer']), app(PortalDocumentStorage::class)),
        ]);
    }

    /**
     * Tells the vendor in the portal's own notification list, exactly as the
     * portal's reviewer would. In-app only — the back office sends no mail.
     */
    private function notifyVendor(VendorDocument $document, string $action, ?string $remarks): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            return;
        }

        DB::table('portal_notifications')->insert([
            'notifiable_type' => 'vendor',
            'notifiable_id' => $document->vendor_id,
            'type' => 'document_' . $action,
            'title' => 'Document ' . $action,
            'message' => "Your document \"{$document->title}\" was {$action}" . ($remarks ? ': ' . $remarks : '.'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Shapes one row for the documents panel. Everything the portal captures on
     * upload is surfaced — type, title, dates, version, review outcome — since
     * an expired or rejected document is exactly what an approver must notice.
     */
    private function present(VendorDocument $document, PortalDocumentStorage $storage): array
    {
        $kind = $document->fileKind();

        return [
            'id' => $document->id,
            'name' => $document->title,
            'file_name' => $document->file_name,
            'file_type' => $kind,
            'extension' => strtoupper(pathinfo((string) $document->file_name, PATHINFO_EXTENSION)) ?: 'FILE',
            'category' => $document->documentType?->label ?? 'Uncategorised',
            'status' => ucfirst($document->status),
            'file_size' => $document->humanFileSize(),
            'uploaded_at' => $document->created_at?->format('M j, Y'),
            'issued_date' => $document->issued_date?->format('M j, Y'),
            'expiry_date' => $document->expiry_date?->format('M j, Y'),
            'is_expired' => $document->isExpired(),
            'is_expiring_soon' => $document->isExpiringSoon(),
            'version' => $document->version,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M j, Y g:i A'),
            'review_remarks' => $document->review_remarks,
            // Null when neither the shared path nor the portal can produce the
            // file, so the panel can say so instead of offering a dead button.
            'file_url' => $storage->isAvailable($document)
                ? route('vendors.documents.file', ['vendor' => $document->vendor_id, 'document' => $document->id])
                : null,
            'download_url' => $storage->isAvailable($document)
                ? route('vendors.documents.file', ['vendor' => $document->vendor_id, 'document' => $document->id, 'download' => 1])
                : null,
        ];
    }
}
