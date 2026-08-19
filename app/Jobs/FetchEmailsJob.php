<?php

namespace App\Jobs;

use App\Services\EmailTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the IMAP mailbox fetch off the web request.
 *
 * EmailTicketService::fetchAndProcess() opens IMAP and walks the mailbox
 * synchronously — tens of seconds on a full inbox. Doing that inside a web
 * request holds a PHP worker for the whole fetch, which is fatal on the local
 * dev server (`artisan serve` handles one request at a time on Windows, so the
 * user's next click waits behind it) and risky in production (with
 * pm.max_children = 20, twenty simultaneous dashboard opens could occupy every
 * worker at once).
 *
 * ShouldBeUnique keeps the queue from filling with duplicates when many people
 * open the dashboard at once: only one fetch may be queued or running per minute.
 * The scheduler's own `tickets:fetch-emails` run remains the primary intake path.
 */
class FetchEmailsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two attempts, not one. The fetch is idempotent (every decision is recorded in
     * email_intake_logs) and lock-guarded, so a retry is cheap. With tries = 1 any
     * interrupted worker — Ctrl+C, concurrently's --kill-others, an Azure container
     * recycle — leaves the job reserved; once retry_after releases it, the next pop
     * sees attempts > tries and it dies with MaxAttemptsExceededException without ever
     * entering handle(). One retry absorbs that instead of filling failed_jobs.
     */
    public int $tries = 2;

    /** fetchAndProcess() raises its own limit to 180s; stay above it. */
    public int $timeout = 200;

    /** Only one queued/running fetch per minute, however many callers ask. */
    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'tickets-sync';
    }

    public function failed(\Throwable $e): void
    {
        // Intake still happens on the 30s schedule, so a failed opportunistic fetch is
        // not an incident — but it should be visible rather than silent.
        Log::warning(
            'FetchEmailsJob failed: '.$e->getMessage()
        );
    }

    public function handle(EmailTicketService $service): void
    {
        // Shares one lock name with the synchronous route and the scheduler, so a
        // queued fetch can never overlap a manual "Sync Emails" or a scheduled run.
        $lock = Cache::lock('tickets:sync', 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $service->fetchAndProcess();
        } finally {
            $lock->release();
        }
    }
}
