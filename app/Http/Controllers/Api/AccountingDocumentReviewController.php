<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcctDocumentReview;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Inbound handoff endpoint for linkportal's OCR intake pipeline.
 * Idempotent on idempotency_key: replays return the existing review.
 */
class AccountingDocumentReviewController extends Controller
{
    public function store(Request $request, NotificationService $notifications)
    {
        $validated = $request->validate([
            'idempotency_key' => 'required|string|max:100',
            'source_document_id' => 'required|integer',
            'reference_no' => 'required|string|max:30',
            'document_type' => 'required|in:invoice,purchase_order,quotation',
            'vendor' => 'nullable|array',
            'vendor.code' => 'nullable|string|max:50',
            'vendor.name' => 'nullable|string|max:255',
            'vendor.company' => 'nullable|string|max:255',
            'fields' => 'nullable|array',
            'line_items' => 'nullable|array',
            'confidence' => 'nullable|array',
            'exceptions' => 'nullable|array',
            'file_url' => 'nullable|string',
            'file_url_expires_at' => 'nullable|date',
            'callback_url' => 'nullable|string',
            'submitted_at' => 'nullable|date',
        ]);

        // Exact replay of the same submission → return unchanged (idempotent).
        $existing = AcctDocumentReview::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing) {
            return response()->json([
                'status' => 'ok',
                'replayed' => true,
                'review' => ['id' => $existing->id, 'status' => $existing->status],
            ]);
        }

        $attributes = [
            'idempotency_key' => $validated['idempotency_key'],
            'source_document_id' => $validated['source_document_id'],
            'source_reference_no' => $validated['reference_no'],
            'document_type' => $validated['document_type'],
            'vendor_code' => $validated['vendor']['code'] ?? null,
            'vendor_name' => $validated['vendor']['name'] ?? null,
            'company_name' => $validated['vendor']['company'] ?? null,
            'fields' => $validated['fields'] ?? null,
            'line_items' => $validated['line_items'] ?? null,
            'confidence' => $validated['confidence'] ?? null,
            'exceptions_summary' => $validated['exceptions'] ?? null,
            'file_url' => $validated['file_url'] ?? null,
            'file_url_expires_at' => $validated['file_url_expires_at'] ?? null,
            'callback_url' => $validated['callback_url'] ?? null,
            'status' => AcctDocumentReview::STATUS_PENDING,
            'received_at' => now(),
            'due_at' => now()->addDays((int) config('services.linkportal.review_sla_days', 3)),
        ];

        // A resubmission (new idempotency_key for a document already handed off)
        // supersedes the prior review in place instead of stacking a duplicate,
        // resetting it to a fresh pending review with the latest data.
        $review = AcctDocumentReview::where('source_document_id', $validated['source_document_id'])->first();
        $isResubmission = (bool) $review;

        if ($isResubmission) {
            $review->update($attributes + [
                'decision' => null,
                'decision_remarks' => null,
                'decided_by' => null,
                'decided_at' => null,
                'callback_status' => null,
                'callback_attempts' => 0,
                'callback_error' => null,
            ]);
            $review->recordEvent('resubmitted', null, null, ['submitted_at' => $validated['submitted_at'] ?? null]);
        } else {
            $review = AcctDocumentReview::create($attributes);
            $review->recordEvent('received', null, null, ['submitted_at' => $validated['submitted_at'] ?? null]);
        }

        $notifications->notifyApproval(
            $notifications->usersWithPermission('accounting-documents.review'),
            null,
            'received',
            $isResubmission ? 'Vendor document resubmitted for review' : 'Vendor document for review',
            "{$review->vendor_name}: {$review->source_reference_no} ({$review->document_type})",
            $notifications->relativeRoute('accounting-documents.show', $review->id),
            "Document {$review->source_reference_no}",
        );

        return response()->json([
            'status' => 'ok',
            'review' => ['id' => $review->id, 'status' => $review->status],
        ], $isResubmission ? 200 : 201);
    }
}
