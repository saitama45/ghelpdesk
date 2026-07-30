<?php

namespace App\Console\Commands;

use App\Models\EmailIntakeLog;
use App\Models\Setting;
use App\Services\EmailTicketService;
use Illuminate\Console\Command;

/**
 * Answers "what happened to this email?" against the live mailbox, and can
 * ingest a message the fetcher missed.
 *
 * Read-only unless --ingest is passed.
 */
class DiagnoseEmailFetch extends Command
{
    protected $signature = 'tickets:diagnose-email
        {--subject= : Match messages whose subject contains this text}
        {--from= : Match messages from this sender address (substring)}
        {--message-id= : Match one exact Message-ID}
        {--days=7 : How far back to scan}
        {--limit=10 : Maximum matches to report}
        {--ingest : Process the matched messages now (suppresses courtesy auto-replies)}';

    protected $description = 'Explain why an inbound email did or did not become a ticket, and optionally ingest it';

    public function handle(EmailTicketService $service): int
    {
        $subject = (string) $this->option('subject');
        $from = (string) $this->option('from');
        $messageId = strtolower(trim((string) $this->option('message-id'), " \t<>"));

        if ($subject === '' && $from === '' && $messageId === '') {
            $this->error('Give at least one of --subject, --from or --message-id.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $this->line('Mailbox: <info>' . Setting::get('imap_username', '(not configured)') . '</info>');
        $this->line('Catch-up lookback: <info>' . Setting::get('email_fetch_lookback_days', 3) . ' day(s)</info>'
            . ' | last sync: <info>' . (Setting::get('last_email_sync_at') ?: 'never') . '</info>'
            . ' | last catch-up: <info>' . (Setting::get('last_email_catchup_at') ?: 'never') . '</info>');
        $this->line('Catch-up watermark: <info>' . (Setting::get('email_catchup_started_at') ?: 'not set yet')
            . '</info> (mail older than this is only ingested with --ingest)');

        try {
            [$client, $inbox] = $service->openInbox();
        } catch (\Throwable $e) {
            $this->error('Could not open the inbox: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line("Scanning INBOX for the last {$days} day(s) (headers only)...");

        // Header-only and PEEK: scanning must never change the read state, or the
        // diagnosis would alter the thing being diagnosed.
        $headers = $inbox->messages()
            ->whereSince(now()->subDays($days))
            ->setFetchBody(false)
            ->leaveUnread()
            ->get();

        $matches = collect($headers)->filter(function ($message) use ($subject, $from, $messageId) {
            if ($messageId !== '') {
                return strtolower(trim((string) $message->getMessageId(), " \t<>")) === $messageId;
            }

            $ok = true;

            if ($subject !== '') {
                $ok = $ok && mb_stripos((string) $message->getSubject(), $subject) !== false;
            }

            if ($from !== '') {
                $sender = (string) ($message->getFrom()[0]->mail ?? '');
                $ok = $ok && mb_stripos($sender, $from) !== false;
            }

            return $ok;
        })->take($limit)->values();

        if ($matches->isEmpty()) {
            $this->warn('No message in that window matched. Scanned ' . count($headers) . ' message(s).');
            $this->line('If you expected one, widen --days, or check whether it was archived out of INBOX.');
            $client->disconnect();

            return self::SUCCESS;
        }

        foreach ($matches as $header) {
            $uid = (int) $header->uid;

            // The body is needed for the threading/dedup verdict.
            $full = $inbox->messages()->leaveUnread()->getMessageByUid($uid);
            $facts = $service->inspectMessage($full);

            $this->newLine();
            $this->line('<comment>─────────────────────────────────────────────</comment>');
            $this->line("UID {$facts['uid']} | {$facts['date']}");
            $this->line("Subject : {$facts['subject']}");
            $this->line("From    : {$facts['sender']}");
            $this->line("Msg-ID  : {$facts['message_id']}");
            $this->line('Flags   : ' . ($facts['flags'] !== '' ? $facts['flags'] : '(none)'));
            $this->line('To/CC   : ' . implode(', ', $facts['recipients']));
            $this->line('Ours?   : ' . ($facts['matched_our_address'] ? 'yes' : 'NO — would be ignored')
                . ' | department: ' . ($facts['routed_department_id'] ?? 'shared inbox'));
            $this->line('Ledger  : ' . ($facts['ledger_outcome']
                ? $facts['ledger_outcome'] . ' at ' . $facts['ledger_processed_at'] . ($facts['ledger_error'] ? ' (' . $facts['ledger_error'] . ')' : '')
                : 'no record — the fetcher never decided about this message'));
            $this->line('In DB?  : ' . ($facts['already_processed'] ? 'yes — a ticket or comment already carries this Message-ID' : 'no'));
            $this->line('Threads : ' . ($facts['threaded_onto']
                ? $facts['threaded_onto'] . ' (' . $facts['threaded_onto_status'] . ')'
                : 'no existing ticket — would open a new one'));

            $this->line('<info>Verdict : ' . $this->verdict($facts) . '</info>');

            if ($this->option('ingest')) {
                $result = $service->ingestMessage($full);
                $this->line('Ingested: outcome=' . ($result['outcome'] ?? 'error')
                    . ($result['ticket_id'] ? ' ticket_id=' . $result['ticket_id'] : '')
                    . (empty($result['errors']) ? '' : ' errors=' . implode(' | ', $result['errors'])));
            }
        }

        $client->disconnect();

        if (! $this->option('ingest')) {
            $this->newLine();
            $this->line('Re-run with <info>--ingest</info> to process the matched message(s) now.');
        }

        return self::SUCCESS;
    }

    private function verdict(array $facts): string
    {
        if (! $facts['matched_our_address']) {
            return 'Ignored: none of the recipients is a helpdesk address.';
        }

        if ($facts['already_processed']) {
            return 'Already in the helpdesk (ticket or comment carries this Message-ID).';
        }

        if ($facts['ledger_outcome'] === EmailIntakeLog::OUTCOME_BANNED_SENDER) {
            return 'Ignored: automated sender.';
        }

        if ($facts['threaded_onto'] && $facts['threaded_onto_status'] === 'closed') {
            return "Would attach to closed ticket {$facts['threaded_onto']} — closed tickets refuse email comments.";
        }

        if ($facts['threaded_onto']) {
            return "Would be added as a comment on {$facts['threaded_onto']}.";
        }

        if ($facts['requires_department_address'] && $facts['routed_department_id'] === null) {
            return 'Would be refused: new requests must use a departmental address (shared-mailbox enforcement is ON).';
        }

        return 'Would open a NEW ticket. Nothing in the ledger means the fetcher never saw it — most likely it was already read in the mailbox before a fetch pass ran.';
    }
}
