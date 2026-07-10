<?php

namespace App\Jobs;

use App\Models\AcctDocumentReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Posts an accounting decision back to linkportal. Retried with backoff; a
 * final failure is surfaced on the review for a manual retry.
 */
class SendDecisionCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $reviewId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(): void
    {
        $review = AcctDocumentReview::with('decidedBy:id,name')->findOrFail($this->reviewId);

        if (! $review->isDecided()) {
            return;
        }

        $url = $review->callback_url ?: rtrim((string) config('services.linkportal.base_url'), '/').'/api/integrations/ghelpdesk/document-review-decision';
        if (app()->isProduction() && ! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('linkportal callback URL must be https in production.');
        }

        $review->update(['callback_status' => 'pending', 'callback_attempts' => $review->callback_attempts + 1]);

        $response = Http::withToken((string) config('services.linkportal.token'))
            ->acceptJson()
            ->timeout(30)
            ->post($url, [
                'review_id' => $review->id,
                'source_document_id' => $review->source_document_id,
                'decision' => $review->decision,
                'remarks' => $review->decision_remarks,
                'reviewer' => $review->decidedBy?->name,
                'decided_at' => $review->decided_at?->toIso8601String(),
            ]);

        if (! $response->successful()) {
            $review->update(['callback_status' => 'failed', 'callback_error' => mb_substr($response->body(), 0, 1000)]);
            throw new \RuntimeException("linkportal callback failed with HTTP {$response->status()}");
        }

        $review->update(['callback_status' => 'sent', 'callback_error' => null]);
        $review->recordEvent('callback_sent');
    }

    public function failed(?\Throwable $exception): void
    {
        $review = AcctDocumentReview::find($this->reviewId);
        if (! $review) {
            return;
        }

        $review->update([
            'callback_status' => 'failed',
            'callback_error' => mb_substr($exception?->getMessage() ?? 'unknown error', 0, 1000),
        ]);
        $review->recordEvent('callback_failed', null, $exception?->getMessage());
    }
}
