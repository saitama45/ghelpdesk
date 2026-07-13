<?php

namespace App\Http\Controllers;

use App\Jobs\SendDecisionCallbackJob;
use App\Models\AcctDocumentReview;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountingDocumentReviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:accounting-documents.view', only: ['index', 'show']),
            new Middleware('can:accounting-documents.approve', only: ['approve']),
            new Middleware('can:accounting-documents.return', only: ['returnDocument']),
            new Middleware('can:accounting-documents.reject', only: ['reject']),
            new Middleware('can:accounting-documents.review', only: ['retryCallback']),
        ];
    }

    public function index(Request $request)
    {
        $query = AcctDocumentReview::query()->latest('received_at');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('source_reference_no', 'like', "%{$request->search}%")
                    ->orWhere('vendor_name', 'like', "%{$request->search}%")
                    ->orWhere('vendor_code', 'like', "%{$request->search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', [AcctDocumentReview::STATUS_PENDING, AcctDocumentReview::STATUS_IN_REVIEW]);
        }
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }
        if ($request->filled('has_exceptions')) {
            $request->boolean('has_exceptions')
                ? $query->whereNotNull('exceptions_summary')->where('exceptions_summary', '!=', '[]')
                : $query->where(fn ($q) => $q->whereNull('exceptions_summary')->orWhere('exceptions_summary', '[]'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('received_at', '<=', $request->date_to);
        }
        if ($request->filled('min_confidence')) {
            $minConfidence = (float) $request->min_confidence;
            $query->whereRaw("CAST(JSON_VALUE(confidence, '$.overall') AS float) >= ?", [$minConfidence]);
        }

        return Inertia::render('AccountingDocuments/Index', [
            'reviews' => $query->paginate($request->get('per_page', 10))->withQueryString(),
            'filters' => $request->only(['search', 'status', 'document_type', 'has_exceptions', 'date_from', 'date_to', 'min_confidence']),
        ]);
    }

    public function show(AcctDocumentReview $accounting_document)
    {
        if ($accounting_document->status === AcctDocumentReview::STATUS_PENDING) {
            $accounting_document->update(['status' => AcctDocumentReview::STATUS_IN_REVIEW]);
            $accounting_document->recordEvent('opened', auth()->id());
        }

        return Inertia::render('AccountingDocuments/Review', [
            'review' => $accounting_document->load(['events.user:id,name', 'decidedBy:id,name']),
        ]);
    }

    public function approve(Request $request, AcctDocumentReview $accounting_document, NotificationService $notifications)
    {
        $request->validate(['remarks' => 'nullable|string|max:2000']);

        return $this->decide($accounting_document, 'approve', AcctDocumentReview::STATUS_APPROVED, $request->remarks, $notifications);
    }

    public function returnDocument(Request $request, AcctDocumentReview $accounting_document, NotificationService $notifications)
    {
        $request->validate(['remarks' => 'required|string|max:2000']);

        return $this->decide($accounting_document, 'return', AcctDocumentReview::STATUS_RETURNED, $request->remarks, $notifications);
    }

    public function reject(Request $request, AcctDocumentReview $accounting_document, NotificationService $notifications)
    {
        $request->validate(['remarks' => 'required|string|max:2000']);

        return $this->decide($accounting_document, 'reject', AcctDocumentReview::STATUS_REJECTED, $request->remarks, $notifications);
    }

    public function retryCallback(AcctDocumentReview $accounting_document)
    {
        if (! $accounting_document->isDecided()) {
            return redirect()->back()->with('error', 'Review has no decision to send.');
        }
        if ($accounting_document->callback_status === 'sent') {
            return redirect()->back()->with('info', 'Callback already delivered.');
        }

        SendDecisionCallbackJob::dispatch($accounting_document->id);

        return redirect()->back()->with('success', 'Callback retry queued.');
    }

    private function decide(AcctDocumentReview $review, string $decision, string $status, ?string $remarks, NotificationService $notifications)
    {
        if ($review->isDecided()) {
            return redirect()->back()->with('error', 'This document has already been decided.');
        }

        DB::transaction(function () use ($review, $decision, $status, $remarks) {
            $review->update([
                'status' => $status,
                'decision' => $decision,
                'decision_remarks' => $remarks,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'callback_status' => 'pending',
            ]);
            $review->recordEvent($status, auth()->id(), $remarks);
        });

        // Send the decision back to linkportal immediately so the status updates
        // without depending on a running queue worker. If linkportal is briefly
        // unreachable, fall back to the queue for retry-with-backoff.
        try {
            SendDecisionCallbackJob::dispatchSync($review->id);
        } catch (\Throwable $e) {
            SendDecisionCallbackJob::dispatch($review->id);
        }

        $notifications->notifyApproval(
            $notifications->usersWithPermission('accounting-documents.view'),
            auth()->id(),
            $status,
            "Document {$status}",
            "{$review->source_reference_no} ({$review->vendor_name}) was {$status} by ".auth()->user()->name,
            $notifications->relativeRoute('accounting-documents.show', $review->id),
            "Document {$review->source_reference_no}",
            $decision === 'approve' ? 'success' : 'warning',
        );

        return redirect()->back()->with('success', "Document {$status}. The decision is being sent back to linkportal.");
    }
}
