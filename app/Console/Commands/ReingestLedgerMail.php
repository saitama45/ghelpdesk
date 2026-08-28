<?php

namespace App\Console\Commands;

use App\Models\EmailIntakeLog;
use App\Services\EmailTicketService;
use Illuminate\Console\Command;

/**
 * Replays messages the fetcher turned away, addressed by the UID the intake
 * ledger already recorded.
 *
 * tickets:diagnose-email finds mail by scanning a date window's headers, which is
 * the right tool when you do not know what you are looking for. For a KNOWN set of
 * bad decisions it is the wrong shape: the scan dominates the runtime (a mailbox
 * taking a hundred-odd bounces a day makes a two-day window several hundred
 * headers) and it has to be repeated per sender filter.
 *
 * Every ledger row already carries the folder and UID, so this fetches exactly the
 * affected messages and nothing else.
 *
 * Idempotent: ingestMessage() de-duplicates on Message-ID, so a message that
 * already became a ticket or comment is skipped rather than duplicated.
 */
class ReingestLedgerMail extends Command
{
    protected $signature = 'tickets:reingest-ledger
        {--outcome=department_directory : The ledger outcome to replay}
        {--since= : Only rows recorded at or after this datetime}
        {--limit=200 : Maximum rows to replay}
        {--skip= : Comma-separated ledger row ids to leave alone}
        {--dry-run : List what would be replayed without touching the mailbox}';

    protected $description = 'Re-ingest inbound mail the fetcher rejected, by UID from the intake ledger';

    public function handle(EmailTicketService $service): int
    {
        $outcome = (string) $this->option('outcome');

        // Ledger ids to leave alone — the mailbox's own courtesy notice bounced
        // back into the inbox is in this set, and ticketing it would be noise.
        $skip = collect(explode(',', (string) $this->option('skip')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->all();

        $rows = EmailIntakeLog::query()
            ->where('outcome', $outcome)
            ->when($skip, fn ($q) => $q->whereNotIn('id', $skip))
            ->when($this->option('since'), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->whereNotNull('uid')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No ledger rows with outcome '{$outcome}'.");

            return self::SUCCESS;
        }

        $this->line("Replaying <info>{$rows->count()}</info> message(s) recorded as <info>{$outcome}</info>.");

        if ($this->option('dry-run')) {
            foreach ($rows as $row) {
                $this->line("  UID {$row->uid} | {$row->created_at} | {$row->sender_email} | ".mb_substr((string) $row->subject, 0, 60));
            }
            $this->newLine();
            $this->line('Dry run — nothing fetched. Drop <info>--dry-run</info> to replay.');

            return self::SUCCESS;
        }

        try {
            [$client, $inbox] = $service->openInbox();
        } catch (\Throwable $e) {
            $this->error('Could not open the inbox: '.$e->getMessage());

            return self::FAILURE;
        }

        $tally = [];

        foreach ($rows as $row) {
            $label = "UID {$row->uid} ({$row->sender_email})";

            try {
                // leaveUnread: replaying must not change what a human sees as unread.
                $message = $inbox->messages()->leaveUnread()->getMessageByUid((int) $row->uid);
            } catch (\Throwable $e) {
                $this->error("  {$label}: fetch failed — ".$e->getMessage());
                $tally['fetch_failed'] = ($tally['fetch_failed'] ?? 0) + 1;

                continue;
            }

            if (! $message) {
                // Deleted or moved out of INBOX since the ledger row was written.
                $this->warn("  {$label}: no longer in the mailbox.");
                $tally['gone'] = ($tally['gone'] ?? 0) + 1;

                continue;
            }

            $result = $service->ingestMessage($message);
            $newOutcome = $result['outcome'] ?? 'error';
            $tally[$newOutcome] = ($tally[$newOutcome] ?? 0) + 1;

            $this->line("  {$label}: <info>{$newOutcome}</info>"
                .($result['ticket_id'] ? ' → ticket '.$result['ticket_id'] : '')
                .(empty($result['errors']) ? '' : ' | '.implode(' | ', $result['errors'])));
        }

        $client->disconnect();

        $this->newLine();
        foreach ($tally as $key => $count) {
            $this->line(str_pad($key, 26).$count);
        }

        return self::SUCCESS;
    }
}
