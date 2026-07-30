<?php

namespace App\Services;

use App\Mail\TicketCommentAdded;
use App\Models\Company;
use App\Models\EmailIntakeLog;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Webklex\IMAP\Facades\Client;

class EmailTicketService
{
    public function fetchAndProcess()
    {
        set_time_limit(180); // Increase to 3 minutes for IMAP sync
        // 1. Check if we should sync (Optional: throttle to 30 seconds to avoid IMAP overhead)
        $lastSync = Setting::get('last_email_sync_at');
        if ($lastSync && now()->parse($lastSync)->addSeconds(20)->isFuture()) {
            return ['status' => 'skipped', 'message' => 'Synced recently (within 20s).'];
        }

        Log::info("EmailTicketService: Starting email fetch process...");

        try {
            $supportEmail = $this->normalizeEmailAddress(Setting::get('imap_username', config('imap.accounts.default.username')));
            if ($supportEmail === '') {
                Log::warning("EmailTicketService: Fetch skipped - No inbound support email is configured.");
                return ['status' => 'skipped', 'message' => 'No inbound support email is configured.'];
            }

            // 2. Configure IMAP from Database Settings and open the inbox
            [$client, $inbox] = $this->openInbox();

            // Pass 1 — unread mail. This is the fast path for everything that
            // arrives while the fetcher is running normally.
            $query = $inbox->messages()->unseen()->leaveUnread();
            $messages = $query->get();
            $unseenCount = count($messages);

            Log::info("EmailTicketService: Inbox stats - Unseen: {$unseenCount}");

            $count = 0;
            $errors = [];

            // Log any messages the library could not parse (soft_fail=true means they are skipped, not thrown)
            if ($query->hasErrors()) {
                foreach ($query->getErrors() as $uid => $error) {
                    Log::warning("EmailTicketService: Library skipped UID {$uid} (parse error): " . $error->getMessage());
                    $errors[] = "UID {$uid} skipped by IMAP library: " . $error->getMessage();
                }
            }
            foreach ($messages as $message) {
                if ($this->handleFetchedMessage($message, false, $errors)) {
                    $count++;
                }
            }

            // Pass 2 — mail that is no longer unread but that we never recorded a
            // decision about. Without this, any message a human opened in the
            // shared mailbox before the fetcher's next pass was lost for good.
            $count += $this->processCatchUpMessages($inbox, $errors);

            // 3. Update Last Sync Time (Using Manila time for display, but Laravel handles the Carbon comparison)
            Setting::set('last_email_sync_at', now()->toDateTimeString(), 'system');

            $client->disconnect();

            Log::info("EmailTicketService: Fetch completed. Processed {$count} tickets.");

            return [
                'status' => empty($errors) ? 'success' : 'warning',
                'message' => "Processed {$count} new tickets." . (empty($errors) ? '' : ' Errors encountered: ' . implode(' | ', $errors)),
                'count' => $count,
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            Log::error("EmailTicketService: Fetch failed: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }


    /**
     * Connect with the stored mail settings and open the INBOX.
     *
     * Shared by the fetcher and `tickets:diagnose-email` so a diagnosis always
     * looks at exactly the mailbox the fetcher reads.
     *
     * @return array{0: \Webklex\PHPIMAP\Client, 1: \Webklex\PHPIMAP\Folder}
     *
     * @throws \RuntimeException
     */
    public function openInbox(): array
    {
        $supportEmail = $this->normalizeEmailAddress(Setting::get('imap_username', config('imap.accounts.default.username')));

        if ($supportEmail === '') {
            throw new \RuntimeException('No inbound support email is configured.');
        }

        $imapConfig = [
            'imap.accounts.default.host' => Setting::get('imap_host', config('imap.accounts.default.host')),
            'imap.accounts.default.port' => Setting::get('imap_port', config('imap.accounts.default.port')),
            'imap.accounts.default.encryption' => Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
            'imap.accounts.default.username' => $supportEmail,
            'imap.accounts.default.password' => Setting::get('imap_password', config('imap.accounts.default.password')),
            'imap.options.fetch_order' => 'desc',
        ];

        config($imapConfig);

        Log::debug('EmailTicketService: Connecting to ' . $imapConfig['imap.accounts.default.host'] . ' as ' . $supportEmail);

        $client = Client::account('default');
        $client->connect();

        $folders = $client->getFolders();
        $inbox = null;

        foreach ($folders as $folder) {
            if (strtolower($folder->name) === 'inbox') {
                $inbox = $folder;
                break;
            }
        }

        if (! $inbox) {
            Log::error('EmailTicketService: Inbox folder not found.');

            throw new \RuntimeException('Inbox not found. Available: ' . $folders->map(fn ($f) => $f->name)->implode(', '));
        }

        return [$client, $inbox];
    }

    /**
     * Everything the fetcher knows about one message, without changing anything —
     * the answer to "why was this email never logged?".
     *
     * @return array<string, mixed>
     */
    public function inspectMessage($message): array
    {
        $messageId = $this->normalizeMessageId($message->getMessageId());
        $variants = $this->messageIdentifierVariants($message->getMessageId());
        $senderEmail = $this->normalizeEmailAddress($message->getFrom()[0]->mail ?? '');
        $subject = $this->decodeMimeHeader((string) $message->getSubject());
        $recipients = array_keys($this->collectRoutableRecipientAddresses($message));
        $route = app(DepartmentMailRouter::class)->resolve($recipients);

        $ledger = $this->intakeLogAvailable()
            ? EmailIntakeLog::where('message_key', $this->intakeKeyFor($message))->first()
            : null;

        $thread = $this->findExistingTicketForMessage(
            $message,
            $subject,
            $senderEmail,
            $this->emailBodyHash($this->extractCleanMessageBody($message))
        );

        return [
            'uid' => (int) ($message->uid ?? 0),
            'subject' => $subject,
            'message_id' => $messageId,
            'sender' => $senderEmail,
            'date' => (string) $message->getDate(),
            'flags' => collect($message->getFlags() ?: [])->values()->implode(', '),
            'recipients' => $recipients,
            'routed_department_id' => $route['department_id'],
            'matched_our_address' => (bool) $route['matched'],
            'requires_department_address' => app(DepartmentMailRouter::class)->requiresDepartmentAddress(),
            'already_processed' => $this->messageAlreadyProcessed($variants),
            'threaded_onto' => $thread?->ticket_key,
            'threaded_onto_status' => $thread?->status,
            'ledger_outcome' => $ledger?->outcome,
            'ledger_processed_at' => (string) $ledger?->processed_at,
            'ledger_error' => $ledger?->error,
        ];
    }

    /**
     * Ingest a single message on demand (recovery of mail the fetcher missed).
     *
     * Runs in catch-up mode by default so a manual backfill never fires courtesy
     * auto-replies at requesters for days-old mail.
     */
    public function ingestMessage($message, bool $recovery = true): array
    {
        $errors = [];
        $handled = $this->handleFetchedMessage($message, $recovery, $errors);

        return [
            'handled' => $handled,
            'outcome' => $this->lastOutcome['outcome'] ?? null,
            'ticket_id' => $this->lastOutcome['ticket_id'] ?? null,
            'errors' => $errors,
        ];
    }

    /**
     * Process one fetched message and record what happened to it.
     *
     * Every decision lands in email_intake_logs — including the skips — so the
     * fetcher has a memory of its own instead of relying on the mailbox's \Seen
     * flag, and so "what happened to this email?" is answerable afterwards.
     *
     * @param  bool  $recovery  true when the message was picked up by the
     *                          catch-up scan (already read by a human)
     */
    protected function handleFetchedMessage($message, bool $recovery, array &$errors): bool
    {
        $subject = '';

        try {
            $subject = (string) $message->getSubject();
            Log::debug('EmailTicketService: Checking message: ' . $subject);

            $this->lastOutcome = null;
            $handled = $this->processMessage($message, $recovery);

            $this->recordIntake(
                $message,
                $this->lastOutcome['outcome'] ?? EmailIntakeLog::OUTCOME_CREATED,
                $this->lastOutcome['ticket_id'] ?? null,
                $recovery
            );

            return $handled;
        } catch (\Throwable $me) {
            $errorMsg = $me->getMessage();
            Log::error('EmailTicketService: Message processing error: ' . $errorMsg);
            $errors[] = "Subject '" . mb_substr($subject, 0, 50) . "': " . $errorMsg;

            // Recorded as an error rather than a decision, so the next pass retries it.
            $this->recordIntake($message, EmailIntakeLog::OUTCOME_ERROR, null, $recovery, $errorMsg);

            return false;
        }
    }

    /**
     * Ingest recent messages that are no longer unread but that the ledger has no
     * decision for.
     *
     * The mailbox \Seen flag is shared, human-writable state: anyone reading the
     * support inbox in a mail client marks messages read, and the unread-only
     * query then skips them permanently. Combined with any gap in the fetcher's
     * uptime (a killed run, a recycled container, an IMAP outage) that silently
     * drops requests. This pass closes that hole.
     *
     * Cheap by construction: headers only for the window, then a full fetch for
     * the (normally zero) messages that turn out to be unaccounted for.
     */
    protected function processCatchUpMessages($inbox, array &$errors): int
    {
        $days = (int) Setting::get('email_fetch_lookback_days', 3);

        // 0 disables the pass; the ledger is what makes it idempotent, so without
        // the table we stay on unread-only behaviour.
        if ($days <= 0 || ! $this->intakeLogAvailable()) {
            return 0;
        }

        $interval = (int) Setting::get('email_fetch_catchup_interval_seconds', 300);
        $lastCatchUp = Setting::get('last_email_catchup_at');

        if ($interval > 0 && $lastCatchUp && now()->parse($lastCatchUp)->addSeconds($interval)->isFuture()) {
            return 0;
        }

        $limit = max(1, (int) Setting::get('email_fetch_catchup_limit', 25));
        $count = 0;

        // Never reach back before this pass existed. Without the watermark the very
        // first run would ticket every read-but-unticketed message in the window —
        // newsletters and mail people had deliberately left alone included. From
        // here on it only rescues messages that arrived while we were watching.
        // (Older mail is still recoverable deliberately: tickets:diagnose-email
        // --ingest.)
        $watermark = Setting::get('email_catchup_started_at');

        if (! $watermark) {
            $watermark = now()->toDateTimeString();
            Setting::set('email_catchup_started_at', $watermark, 'system');
            Log::info("EmailTicketService: catch-up watermark initialised at {$watermark}; older mail is not back-filled.");
        }

        $watermarkAt = now()->parse($watermark);

        try {
            $headerQuery = $inbox->messages()
                ->whereSince(now()->subDays($days))
                ->setFetchBody(false)
                ->leaveUnread();

            $headers = $headerQuery->get();

            // soft_fail is on: unparseable messages are dropped silently, and a
            // dropped message is exactly the failure mode this pass exists to
            // catch — so say so out loud.
            if ($headerQuery->hasErrors()) {
                foreach ($headerQuery->getErrors() as $uid => $error) {
                    Log::warning("EmailTicketService: catch-up could not parse UID {$uid}: " . $error->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::error('EmailTicketService: catch-up scan failed: ' . $e->getMessage());
            $errors[] = 'Catch-up scan failed: ' . $e->getMessage();

            return 0;
        }

        // IMAP SINCE has day granularity, so the watermark is applied here.
        $headers = collect($headers)->filter(function ($header) use ($watermarkAt) {
            try {
                $date = $header->getDate();

                return $date === null || now()->parse((string) $date)->gte($watermarkAt);
            } catch (\Throwable $e) {
                return true;
            }
        });

        $pending = $this->unaccountedCatchUpMessages($headers);

        if ($pending->isEmpty()) {
            Setting::set('last_email_catchup_at', now()->toDateTimeString(), 'system');

            return 0;
        }

        Log::info('EmailTicketService: catch-up scan found ' . $pending->count() . ' unprocessed read message(s).');

        foreach ($pending->take($limit) as $header) {
            $uid = (int) $header->uid;

            try {
                // Headers alone cannot be turned into a ticket — pull the body now.
                $full = $inbox->messages()->leaveUnread()->getMessageByUid($uid);
            } catch (\Throwable $e) {
                Log::error("EmailTicketService: catch-up could not fetch UID {$uid}: " . $e->getMessage());
                $errors[] = "Catch-up UID {$uid}: " . $e->getMessage();
                continue;
            }

            if (! $full) {
                continue;
            }

            if ($this->handleFetchedMessage($full, true, $errors)) {
                $count++;
            }
        }

        Setting::set('last_email_catchup_at', now()->toDateTimeString(), 'system');

        return $count;
    }

    /**
     * Of the scanned headers, the ones with no settled ledger row and no existing
     * ticket/comment carrying their Message-ID. Anything already accounted for is
     * ledgered here (cheaply, from headers alone) so it is never rescanned.
     */
    protected function unaccountedCatchUpMessages($headers): \Illuminate\Support\Collection
    {
        $headers = collect($headers)->filter();

        if ($headers->isEmpty()) {
            return collect();
        }

        $settled = $this->settledIntakeKeys(
            $headers->map(fn ($header) => $this->intakeKeyFor($header))->filter()->unique()->all()
        );

        return $headers->reject(function ($header) use ($settled) {
            $key = $this->intakeKeyFor($header);

            if ($key !== '' && isset($settled[$key])) {
                return true;
            }

            // Already a ticket or a comment (processed before the ledger existed):
            // record the decision so this stops costing a lookup every pass.
            if ($this->messageAlreadyProcessed($this->messageIdentifierVariants($header->getMessageId()))) {
                $this->recordIntake($header, EmailIntakeLog::OUTCOME_DUPLICATE, null, true);

                return true;
            }

            return false;
        })->values();
    }

    /**
     * message_key => true for keys the ledger has a final decision for. Errors are
     * deliberately absent: a failed message must be retried.
     */
    protected function settledIntakeKeys(array $keys): array
    {
        if (empty($keys) || ! $this->intakeLogAvailable()) {
            return [];
        }

        $settled = [];

        // SQL Server caps a statement at 2100 parameters.
        foreach (array_chunk($keys, 500) as $chunk) {
            EmailIntakeLog::whereIn('message_key', $chunk)
                ->where('outcome', '!=', EmailIntakeLog::OUTCOME_ERROR)
                ->pluck('message_key')
                ->each(function ($key) use (&$settled) {
                    $settled[$key] = true;
                });
        }

        return $settled;
    }

    /**
     * The ledger key for a message: its normalized Message-ID, or the mailbox UID
     * when the message carries no Message-ID at all.
     */
    protected function intakeKeyFor($message): string
    {
        $messageId = $this->normalizeMessageId($message->getMessageId());

        if ($messageId !== '') {
            return mb_substr($messageId, 0, 255);
        }

        $uid = (int) ($message->uid ?? 0);

        return $uid > 0 ? "uid:{$uid}" : '';
    }

    protected function recordIntake($message, string $outcome, ?string $ticketId, bool $recovery, ?string $error = null): void
    {
        if (! $this->intakeLogAvailable()) {
            return;
        }

        $key = $this->intakeKeyFor($message);

        if ($key === '') {
            return;
        }

        try {
            EmailIntakeLog::updateOrCreate(
                ['message_key' => $key],
                [
                    'uid' => (int) ($message->uid ?? 0) ?: null,
                    'folder' => 'INBOX',
                    'subject' => mb_substr($this->decodeMimeHeader((string) $message->getSubject()), 0, 500),
                    'sender_email' => mb_substr($this->normalizeEmailAddress($message->getFrom()[0]->mail ?? ''), 0, 255) ?: null,
                    'recipients' => implode(', ', array_keys($this->collectRoutableRecipientAddresses($message))) ?: null,
                    'outcome' => $outcome,
                    'error' => $error ? mb_substr($error, 0, 1000) : null,
                    'ticket_id' => $ticketId,
                    'is_recovered' => $recovery,
                    'processed_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            // The ledger is bookkeeping: never let it break mail intake.
            Log::warning("EmailTicketService: could not record intake for {$key}: " . $e->getMessage());
        }
    }

    private static ?bool $hasIntakeLog = null;

    protected function intakeLogAvailable(): bool
    {
        if (self::$hasIntakeLog === null) {
            self::$hasIntakeLog = \Illuminate\Support\Facades\Schema::hasTable('email_intake_logs');
        }

        return self::$hasIntakeLog;
    }

    /**
     * Test the IMAP connection with provided settings or stored settings.
     */
    public function testConnection($params = null)
    {
        try {
            if ($params) {
                config([
                    'imap.accounts.default.host' => $params['imap_host'] ?? config('imap.accounts.default.host'),
                    'imap.accounts.default.port' => $params['imap_port'] ?? config('imap.accounts.default.port'),
                    'imap.accounts.default.encryption' => $params['imap_encryption'] ?? config('imap.accounts.default.encryption'),
                    'imap.accounts.default.username' => $params['imap_username'] ?? config('imap.accounts.default.username'),
                    'imap.accounts.default.password' => $params['imap_password'] ?? config('imap.accounts.default.password'),
                ]);
            } else {
                config([
                    'imap.accounts.default.host' => Setting::get('imap_host', config('imap.accounts.default.host')),
                    'imap.accounts.default.port' => Setting::get('imap_port', config('imap.accounts.default.port')),
                    'imap.accounts.default.encryption' => Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
                    'imap.accounts.default.username' => Setting::get('imap_username', config('imap.accounts.default.username')),
                    'imap.accounts.default.password' => Setting::get('imap_password', config('imap.accounts.default.password')),
                ]);
            }

            $client = Client::account('default');
            $client->connect();
            
            $folders = $client->getFolders();
            $inboxFound = false;
            foreach ($folders as $folder) {
                if (strtolower($folder->name) === 'inbox') {
                    $inboxFound = true;
                    break;
                }
            }

            $client->disconnect();
            
            return [
                'status' => 'success',
                'message' => 'Connection successful! ' . ($inboxFound ? 'Inbox found.' : 'Connected, but Inbox folder not found.')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }


    /**
     * The decision the last processMessage() call reached, for the intake ledger.
     *
     * @var array{outcome: string, ticket_id: ?string}|null
     */
    protected ?array $lastOutcome = null;

    /**
     * Record the decision reached for the message being processed and return
     * whether it produced a ticket or comment.
     */
    protected function outcome(string $outcome, bool $handled, ?Ticket $ticket = null): bool
    {
        $this->lastOutcome = ['outcome' => $outcome, 'ticket_id' => $ticket?->id];

        return $handled;
    }

    /**
     * @param  bool  $recovery  true when this message is being ingested late by the
     *                          catch-up scan. Courtesy auto-replies (the closed-ticket
     *                          notice, the department directory) are suppressed then:
     *                          they are only meaningful in real time, and re-sending
     *                          them for days-old mail confuses requesters.
     */
    protected function processMessage($message, bool $recovery = false)
    {
        $messageId = $this->normalizeMessageId($message->getMessageId());
        $messageIdCandidates = $this->messageIdentifierVariants($message->getMessageId());
        $senderEmail = $this->normalizeEmailAddress($message->getFrom()[0]->mail ?? '');
        $subjectForLog = mb_substr((string) $message->getSubject(), 0, 80);
        Log::debug("EmailTicketService: Processing message {$messageId} from {$senderEmail}" . ($recovery ? ' (catch-up)' : ''));

        // 1. Deduplication
        if ($this->messageAlreadyProcessed($messageIdCandidates)) {
            Log::info("EmailTicketService: Skipping message {$messageId} '{$subjectForLog}' - Ticket already exists.");
            $message->setFlag('Seen');
            return $this->outcome(EmailIntakeLog::OUTCOME_DUPLICATE, false);
        }

        $supportEmail = $this->normalizeEmailAddress(Setting::get('imap_username', ''));
        if ($supportEmail === '') {
            Log::warning("EmailTicketService: Skipping message {$messageId} - No inbound support email is configured.");
            return $this->outcome(EmailIntakeLog::OUTCOME_NO_SUPPORT_EMAIL, false);
        }

        // 2. Ignore Bounce Messages
        $bannedSenders = ['mailer-daemon', 'postmaster', 'no-reply', 'noreply'];
        foreach ($bannedSenders as $banned) {
            if (str_contains($senderEmail, $banned)) {
                Log::info("EmailTicketService: Skipping message {$messageId} '{$subjectForLog}' - Banned sender {$senderEmail}.");
                $message->setFlag('Seen');
                return $this->outcome(EmailIntakeLog::OUTCOME_BANNED_SENDER, false);
            }
        }

        // 3. Recipient Check — also decides WHICH DEPARTMENT the message routes to.
        // A hit on a departmental plus-address (support+scm@) names the serving
        // department; a hit on the bare support address is the catch-all and
        // leaves it null, exactly as before routing existed.
        $route = app(DepartmentMailRouter::class)->resolve(
            array_keys($this->collectRoutableRecipientAddresses($message))
        );

        if (!$route['matched']) {
            Log::info("EmailTicketService: Skipping message {$messageId} '{$subjectForLog}' - Not for support email {$supportEmail}.");
            $message->setFlag('Seen');
            return $this->outcome(EmailIntakeLog::OUTCOME_NOT_ADDRESSED_TO_US, false);
        }

        $servingDepartmentId = $route['department_id'];


        $subject = $this->decodeMimeHeader($message->getSubject());
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);
        $user = User::where('email', $senderEmail)->first();
        $cleanBody = $this->extractCleanMessageBody($message);
        $emailBodyHash = $this->emailBodyHash($cleanBody);
        $richBody = $this->extractRichHtmlBody($message);

        // --- THREADING LOGIC ---
        $existingTicket = $this->findExistingTicketForMessage($message, $subject, $senderEmail, $emailBodyHash);

        if ($existingTicket) {
            // A reply can adopt a department for a ticket that never had one, but
            // must never re-route one that does: a requester CC'ing another desk
            // on an in-flight thread would otherwise hand the ticket away.
            if ($servingDepartmentId && !$existingTicket->serving_department_id) {
                $existingTicket->update(['serving_department_id' => $servingDepartmentId]);
            }

            return $this->addEmailAsComment($existingTicket, $message, $user, $cleanBody, $emailBodyHash, $messageId, $richBody, $recovery);
        }

        // NEW request on the shared mailbox while departmental addressing is
        // required: raise nothing and tell the sender where to write instead.
        //
        // Deliberately placed AFTER the threading lookup — a reply on an existing
        // conversation must still be accepted here, or every thread would break
        // the moment a requester used Reply on an older message.
        if ($servingDepartmentId === null && app(DepartmentMailRouter::class)->requiresDepartmentAddress()) {
            if ($recovery) {
                Log::info("EmailTicketService: Skipping message {$messageId} '{$subjectForLog}' - shared-mailbox address required (catch-up, no reply sent).");
            } else {
                $this->sendDepartmentDirectory($senderEmail, $senderName, $subject);
            }

            $message->setFlag('Seen');

            return $this->outcome(EmailIntakeLog::OUTCOME_DEPARTMENT_DIRECTORY, false);
        }

        return DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user, $cleanBody, $emailBodyHash, $richBody, $servingDepartmentId) {
            // Email tickets default to the TGI entity (same product decision as
            // dynamic forms). 'TBG' predates the company-code cleanup and matches
            // no row, which used to silently fall through to Company::first().
            $company = Company::where('code', \App\Support\CompanyContext::DEFAULT_COMPANY_CODE)->first()
                ?? Company::first();
            $companyId = $company ? $company->id : null;

            // The key is left to TicketObserver::creating on purpose. Its generator
            // is the only one that also reserves numbers retired by a renumber
            // (ticket_key_aliases) — a locally computed max+1 can hand a retired
            // number to a new ticket, and old mail carrying that number would then
            // thread onto the wrong conversation. It is also a single MAX() instead
            // of pulling every key for the prefix across the remote link.
            $ticket = Ticket::create([
                'title' => mb_substr($subject, 0, 255),
                'description' => $cleanBody,
                'description_html' => $richBody,
                'type' => 'task',
                'status' => 'open',
                'priority' => 'medium',
                'severity' => 'minor',
                'reporter_id' => $user ? $user->id : null,
                'sender_email' => mb_substr($senderEmail, 0, 255),
                'sender_name' => mb_substr($senderName, 0, 255),
                'message_id' => $messageId ? mb_substr($messageId, 0, 255) : null,
                'source_message_id' => $this->originalMessageIdForThreading($message),
                'email_body_hash' => $emailBodyHash,
                'company_id' => $companyId,
                // Null when the mail came to the general inbox: the shared intake
                // pool, claimed as soon as someone from a desk is assigned.
                'serving_department_id' => $servingDepartmentId,
                // The requester's own department, when the sender is a known user.
                // Distinct from the serving department above — this is the
                // internal CUSTOMER side of the axis.
                'department_id' => $user?->department_id,
            ]);

            // Auto-assign based on sender email rules (may also set company/entity)
            $resolved = app(\App\Services\AutoAssigneeService::class)->resolveAssignee($senderEmail);
            $autoUpdateData = [];
            if ($resolved['assignee_id'] && \App\Models\User::where('id', $resolved['assignee_id'])->exists()) {
                $autoUpdateData['assignee_id'] = $resolved['assignee_id'];
            }
            if ($resolved['company_id']) {
                $autoUpdateData['company_id'] = $resolved['company_id'];
            }
            if ($resolved['store_id'] ?? null) {
                $autoUpdateData['store_id'] = $resolved['store_id'];
                // Company auto-follows the resolved Store/Location's owning company
                // first (mirrors the ticket_key rule); falls back to the rule-based
                // company above when the store has no owning company.
                $storeCompanyId = \App\Models\Store::whereKey($resolved['store_id'])->value('company_id');
                if ($storeCompanyId) {
                    $autoUpdateData['company_id'] = $storeCompanyId;
                }
            }
            if (!empty($autoUpdateData)) {
                $ticket->update($autoUpdateData);
            }

            // Add the email's To/CC recipients to the ticket CC list so replies notify them.
            $this->syncCcsFromEmail($ticket, $message, $senderEmail);

            // Attachments
            $message->getAttachments()->each(function ($attachment) use ($ticket) {
                $originalName = $this->decodeMimeHeader((string) $attachment->getName()) ?: 'attachment';
                $filePath = $this->ticketAttachmentStoragePath($originalName);
                Storage::disk('public')->put($filePath, $attachment->getContent());

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'file_name' => $originalName,
                    'file_storage_path' => $filePath,
                    'file_size_bytes' => $attachment->size,
                ]);
            });

            $message->setFlag('Seen');
            return $this->outcome(EmailIntakeLog::OUTCOME_CREATED, true, $ticket);
        });
    }

    /**
     * Add the incoming email content as a comment to an existing ticket.
     */
    protected function addEmailAsComment(Ticket $ticket, $message, $user, ?string $cleanBody = null, ?string $emailBodyHash = null, ?string $messageId = null, ?string $richBody = null, bool $recovery = false)
    {
        $messageId ??= $this->normalizeMessageId($message->getMessageId());
        $senderEmail = $this->normalizeEmailAddress($message->getFrom()[0]->mail ?? '');
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);

        // LOCK-OUT LOGIC: If ticket is closed, do not allow new comments via email.
        // Send a notification to the customer instead.
        if ($ticket->status === 'closed') {
            if ($recovery) {
                // Days-late ingestion: telling the requester now that their reply
                // was refused would be worse than silence — the ledger row is the
                // record that this happened.
                Log::info("Email stripping: closed ticket {$ticket->ticket_key} reply from {$senderEmail} ingested by catch-up, notification suppressed.");
            } else {
                \Illuminate\Support\Facades\Mail::to($senderEmail)->send(
                    new \App\Mail\ClosedTicketReplyNotification($ticket, $senderName)
                );

                Log::info("Email stripping: Sent ClosedTicketReplyNotification to {$senderEmail} for ticket {$ticket->ticket_key}");
            }

            $message->setFlag('Seen');
            return $this->outcome(EmailIntakeLog::OUTCOME_CLOSED_TICKET, true, $ticket);
        }

        $cleanBody ??= $this->extractCleanMessageBody($message);
        $emailBodyHash ??= $this->emailBodyHash($cleanBody);
        $richBody ??= $this->extractRichHtmlBody($message);

        $comment = DB::transaction(function () use ($ticket, $message, $user, $senderEmail, $senderName, $cleanBody, $emailBodyHash, $messageId, $richBody) {
            // Create the comment
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'comment_text' => $cleanBody,
                'comment_html' => $richBody,
                'user_id' => $user ? $user->id : null,
                'sender_email' => mb_substr($senderEmail, 0, 255),
                'sender_name' => mb_substr($senderName, 0, 255),
                'message_id' => $messageId ? mb_substr($messageId, 0, 255) : null,
                'email_body_hash' => $emailBodyHash,
                'created_at' => now('Asia/Manila'),
            ]);

            // RE-OPEN TRIGGER: If a customer replies to an Open, Waiting, or Resolved ticket,
            // set status to Open to alert the staff.
            if (in_array($ticket->status, ['waiting_service_provider', 'waiting_client_feedback', 'resolved'])) {
                $oldStatus = $ticket->status;
                $ticket->update(['status' => 'open']);
                
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user ? $user->id : null,
                    'column_changed' => 'status',
                    'old_value' => $oldStatus,
                    'new_value' => 'open',
                    'changed_at' => now('Asia/Manila'),
                    'remarks' => 'Ticket automatically re-opened due to customer email reply.'
                ]);
            } elseif ($ticket->status === 'open') {
                // Already open, no status change needed but we could log that it's still open if desired.
            }

            // Merge any new To/CC recipients on this reply into the ticket CC list.
            $this->syncCcsFromEmail($ticket, $message, $senderEmail);

            // In-app (bell) notification for staff following this ticket. The actor
            // is the email sender (if matched to a user), so they won't self-notify.
            app(\App\Services\NotificationService::class)->notifyTicket(
                $ticket,
                'comment',
                'New email reply',
                "{$ticket->ticket_key}: " . \Illuminate\Support\Str::limit((string) $cleanBody, 100),
                $user?->id
            );

            // Attachments
            $message->getAttachments()->each(function ($attachment) use ($ticket, $comment) {
                $originalName = $this->decodeMimeHeader((string) $attachment->getName()) ?: 'attachment';
                $filePath = $this->ticketAttachmentStoragePath($originalName);
                Storage::disk('public')->put($filePath, $attachment->getContent());

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'comment_id' => $comment->id,
                    'file_name' => $originalName,
                    'file_storage_path' => $filePath,
                    'file_size_bytes' => $attachment->size,
                ]);
            });

            return $comment;
        });

        $this->forwardChildEmailReply($ticket, $comment, $message, $senderEmail);
        $message->setFlag('Seen');

        return $this->outcome(EmailIntakeLog::OUTCOME_COMMENT, true, $ticket);
    }

    /**
     * Tell a sender their message was not logged and list the departmental
     * addresses they should use instead.
     *
     * Throttled per sender: without it, any far-end auto-responder that replies to
     * this reply produces a fresh "new request on the shared mailbox" every cycle,
     * and the two systems mail each other indefinitely. One per sender per day is
     * enough to inform a human without sustaining a loop.
     *
     * Never lets an SMTP failure abort the sync — the message is already flagged
     * Seen by the caller, and a stuck mailbox is worse than a missed notice.
     */
    protected function sendDepartmentDirectory(string $senderEmail, string $senderName, string $subject): void
    {
        $router = app(DepartmentMailRouter::class);
        $throttleKey = 'department_directory_sent:' . strtolower(trim($senderEmail));

        if (\Illuminate\Support\Facades\Cache::has($throttleKey)) {
            Log::info("EmailTicketService: directory reply already sent to {$senderEmail} today, skipping.");
            return;
        }

        try {
            Mail::to($senderEmail)->send(new \App\Mail\DepartmentAddressDirectory(
                $senderName !== '' ? $senderName : $senderEmail,
                $subject,
                $router->directory(),
                $router->baseAddress()
            ));

            \Illuminate\Support\Facades\Cache::put($throttleKey, true, now()->addDay());
            Log::info("EmailTicketService: sent department directory to {$senderEmail} (no departmental address used).");
        } catch (\Throwable $e) {
            Log::error("EmailTicketService: failed sending department directory to {$senderEmail}: {$e->getMessage()}");
        }
    }

    /**
     * Fan an inbound child-ticket reply back out to thread participants who did
     * not already receive it directly. Import success is never coupled to SMTP.
     */
    protected function forwardChildEmailReply(Ticket $ticket, TicketComment $comment, $message, string $senderEmail): void
    {
        if (!$ticket->parent_id) {
            return;
        }

        $router = app(DepartmentMailRouter::class);

        $excluded = collect(array_keys($this->collectRecipientAddresses($message)))
            ->push($this->normalizeEmailAddress($senderEmail))
            ->merge($router->allAddresses())
            ->filter()
            ->unique()
            ->all();

        $comment->loadMissing(['user', 'attachments']);

        foreach ($ticket->threadEmailRecipients() as $recipient) {
            if (in_array($recipient['email'], $excluded, true) || $this->isAutomatedAddress($recipient['email'])) {
                continue;
            }

            try {
                // From name / Reply-To are stamped by ThreadsTicketMail from the
                // ticket's serving department — see applyTicketDepartmentIdentity.
                $mail = new TicketCommentAdded($ticket, $comment, $recipient['name'], $comment->attachments);

                Mail::to($recipient['email'])->send($mail);
            } catch (\Throwable $e) {
                Log::error(
                    "EmailTicketService: failed forwarding child reply {$comment->id} to {$recipient['email']}: {$e->getMessage()}"
                );
            }
        }
    }

    protected function findExistingTicketForMessage($message, string $subject, string $senderEmail, ?string $emailBodyHash): ?Ticket
    {
        // 1. Check In-Reply-To and References headers against tickets and email comments.
        //    The subject is passed in as a sanity check — see findTicketByMessageIds.
        $references = $this->messageIdsFromHeaders($message->getReferences(), $message->getInReplyTo());
        $existingTicket = $this->findTicketByMessageIds($references, $subject);

        if ($existingTicket && $existingTicket->status === 'closed' && $existingTicket->updated_at->addDays(3)->isPast()) {
            Log::info("EmailTicketService: Matched closed ticket {$existingTicket->ticket_key} via message IDs, but it was closed more than 3 days ago. Bypassing to create a new ticket.");
            $existingTicket = null;
        }

        if ($existingTicket) {
            return $existingTicket;
        }

        // 2. Fallback: Check subject for Ticket Key (e.g., [TBG-123] or #TBG-123).
        if (preg_match('/\b([A-Z0-9]+-\d+)\b/i', $subject, $matches)) {
            $subjectKey = strtoupper($matches[1]);
            // Resolve the live key first; fall back to a retired key (alias) so a
            // reply still carrying an old key after a renumber lands on the same ticket.
            $existingTicket = $this->intakeTicketQuery()->where('ticket_key', $subjectKey)->first()
                ?? $this->findTicketByKeyAlias($subjectKey);

            if ($existingTicket && $existingTicket->status === 'closed' && $existingTicket->updated_at->addDays(3)->isPast()) {
                Log::info("EmailTicketService: Matched closed ticket {$existingTicket->ticket_key} via subject key, but it was closed more than 3 days ago. Bypassing to create a new ticket.");
                $existingTicket = null;
            }

            if ($existingTicket) {
                return $existingTicket;
            }
        }

        if ($senderEmail === '') {
            return null;
        }

        // 3. Fallback: normalized subject match for the same sender.
        $cleanSubject = $this->normalizeEmailSubject($subject);
        if ($cleanSubject !== '') {
            // Columns pinned deliberately: this scans EVERY ticket a sender ever
            // raised, and tickets carries nvarchar(MAX) columns (description,
            // form_data) that would otherwise cross the remote link for each row.
            // The match is re-read in full below.
            $subjectMatch = $this->intakeTicketQuery()
                ->where('sender_email', $senderEmail)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'title'])
                ->first(fn (Ticket $ticket) => $this->normalizeEmailSubject($ticket->title ?? '') === $cleanSubject);

            $existingTicket = $subjectMatch
                ? $this->intakeTicketQuery()->find($subjectMatch->id)
                : null;

            if ($existingTicket && $existingTicket->status === 'closed' && $existingTicket->updated_at->addDays(3)->isPast()) {
                Log::info("EmailTicketService: Matched closed ticket {$existingTicket->ticket_key} via subject fallback, but it was closed more than 3 days ago. Bypassing to create a new ticket.");
                $existingTicket = null;
            }

            if ($existingTicket) {
                return $existingTicket;
            }
        }

        // 4. Last resort: same sender + same meaningful cleaned body.
        if ($emailBodyHash) {
            $existingTicket = $this->findTicketBySenderAndBodyHash($senderEmail, $emailBodyHash);

            if ($existingTicket && $existingTicket->status === 'closed' && $existingTicket->updated_at->addDays(3)->isPast()) {
                Log::info("EmailTicketService: Matched closed ticket {$existingTicket->ticket_key} via body hash fallback, but it was closed more than 3 days ago. Bypassing to create a new ticket.");
                $existingTicket = null;
            }

            return $existingTicket;
        }

        return null;
    }

    /**
     * Ticket query for mail intake: never entity-scoped.
     *
     * The fetcher also runs inside a web request (the Dashboard posts
     * tickets/sync on load), so ActiveEntityScope is live and would hide every
     * ticket outside the browsing user's active entity. That scope is a listing
     * filter, not an auth boundary: applied here it makes dedup miss and turns
     * every cross-entity reply into a brand-new duplicate ticket.
     */
    protected function intakeTicketQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Ticket::withoutGlobalScope(ActiveEntityScope::class);
    }

    /**
     * Comment query for mail intake, with its ticket eager-loaded unscoped — a
     * scoped eager load resolves $comment->ticket to null across entities.
     */
    protected function intakeCommentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return TicketComment::with([
            'ticket' => fn ($query) => $query->withoutGlobalScope(ActiveEntityScope::class),
        ]);
    }

    /**
     * Resolve a ticket by a retired ticket_key (see ticket_key_aliases). Guarded so
     * inbound mail keeps working before the aliases migration has run.
     */
    protected function findTicketByKeyAlias(string $ticketKey): ?Ticket
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('ticket_key_aliases')) {
            return null;
        }

        $ticketId = \App\Models\TicketKeyAlias::where('ticket_key', $ticketKey)->value('ticket_id');

        if (! $ticketId) {
            return null;
        }

        return Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->find($ticketId);
    }

    /**
     * Resolve the ticket a message belongs to from its In-Reply-To / References
     * headers — the strongest threading signal there is, but NOT proof on its own.
     *
     * Requesters routinely raise a NEW request by hitting Reply on an unrelated old
     * thread and typing a new subject. The reference headers still point at the old
     * conversation, so trusting them blindly files the new request as a comment on
     * a stale ticket: it never appears as a ticket, and because the comment now
     * carries the Message-ID, every later re-fetch is skipped as a duplicate. The
     * request disappears with no trace (this is what happened to
     * "DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)" on 2026-07-29, filed onto
     * TGI-1145 "CHANGE ITEM CODE/ITEM NAME").
     *
     * So a header match must also be plausible on subject. When it is not, we
     * return null and let the remaining strategies (explicit ticket key, exact
     * subject, identical body) decide — normally ending in a new ticket, which is
     * the recoverable failure. A visible duplicate beats a swallowed request.
     *
     * @param  string|null  $subject  the incoming subject; null skips the check
     *                                (callers that already know the topic matches)
     */
    protected function findTicketByMessageIds(array $messageIds, ?string $subject = null): ?Ticket
    {
        $messageIds = collect($messageIds)
            ->flatMap(fn ($messageId) => $this->messageIdentifierVariants($messageId))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($messageIds)) {
            return null;
        }

        // Oldest first: the root of the thread is the conversation's ticket.
        $candidates = $this->intakeTicketQuery()
            ->whereIn('message_id', $messageIds)
            ->orderBy('created_at')
            ->get();

        $commentTickets = $this->intakeCommentQuery()
            ->whereIn('message_id', $messageIds)
            ->orderBy('created_at')
            ->get()
            ->pluck('ticket')
            ->filter();

        $candidates = $candidates->concat($commentTickets)->filter()->unique('id');

        foreach ($candidates as $candidate) {
            if ($subject === null || $this->subjectBelongsToTicket($subject, $candidate)) {
                return $candidate;
            }

            Log::info(
                "EmailTicketService: reference headers point at {$candidate->ticket_key} "
                . "('{$candidate->title}') but the subject '{$subject}' is a different topic — "
                . 'treating it as a new request rather than a comment.'
            );
        }

        return null;
    }

    /**
     * Whether an incoming subject plausibly belongs to a ticket's conversation.
     *
     * Deliberately generous — the cost of being too strict is a duplicate ticket,
     * the cost of being too loose is a lost request:
     *  - equal after stripping Re:/Fw: prefixes, or one contained in the other
     *  - carries the ticket's key (or a retired key) explicitly
     *  - shares at least 60% of its words (Jaccard) with the ticket title
     *
     * An unknown subject on either side (empty after normalization) keeps the old
     * trust-the-headers behaviour.
     */
    protected function subjectBelongsToTicket(string $subject, Ticket $ticket): bool
    {
        $incoming = $this->normalizeSubjectForComparison($subject);
        $existing = $this->normalizeSubjectForComparison((string) $ticket->title);

        if ($incoming === '' || $existing === '') {
            return true;
        }

        if ($incoming === $existing
            || str_contains($incoming, $existing)
            || str_contains($existing, $incoming)) {
            return true;
        }

        if ($ticket->ticket_key && stripos($subject, (string) $ticket->ticket_key) !== false) {
            return true;
        }

        return $this->subjectWordOverlap($incoming, $existing) >= 0.6;
    }

    /**
     * Subject reduced to comparable words: reply/forward prefixes removed,
     * lower-cased, punctuation (including the en dashes these senders use) folded
     * to single spaces.
     */
    protected function normalizeSubjectForComparison(string $subject): string
    {
        $subject = $this->normalizeEmailSubject($subject);
        $subject = mb_strtolower($subject, 'UTF-8');
        $subject = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $subject) ?? $subject;

        return trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
    }

    /**
     * Jaccard similarity of the two subjects' word sets (0..1).
     */
    protected function subjectWordOverlap(string $left, string $right): float
    {
        $leftWords = array_unique(explode(' ', $left));
        $rightWords = array_unique(explode(' ', $right));

        $union = count(array_unique(array_merge($leftWords, $rightWords)));

        if ($union === 0) {
            return 1.0;
        }

        return count(array_intersect($leftWords, $rightWords)) / $union;
    }

    protected function findTicketBySenderAndBodyHash(string $senderEmail, string $emailBodyHash): ?Ticket
    {
        $since = now('Asia/Manila')->subDays(90);
        $candidates = collect();

        $ticketMatches = $this->intakeTicketQuery()
            ->where('sender_email', $senderEmail)
            ->where('email_body_hash', $emailBodyHash)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'closed')
            ->where(function ($query) {
                $query->whereNull('is_deleted')->orWhere('is_deleted', false);
            })
            ->get();

        $commentMatches = $this->intakeCommentQuery()
            ->where('sender_email', $senderEmail)
            ->where('email_body_hash', $emailBodyHash)
            ->where('created_at', '>=', $since)
            ->whereHas('ticket', function ($query) {
                $query->withoutGlobalScope(ActiveEntityScope::class)
                    ->where('status', '!=', 'closed')
                    ->where(function ($ticketQuery) {
                        $ticketQuery->whereNull('is_deleted')->orWhere('is_deleted', false);
                    });
            })
            ->get()
            ->pluck('ticket')
            ->filter();

        $candidates = $candidates
            ->merge($ticketMatches)
            ->merge($commentMatches);

        if ($candidates->isEmpty()) {
            $candidates = $this->findLegacyTicketsBySenderAndBodyHash($senderEmail, $emailBodyHash, $since);
        }

        return $this->chooseOriginalTicket($candidates);
    }

    protected function findLegacyTicketsBySenderAndBodyHash(string $senderEmail, string $emailBodyHash, $since)
    {
        $ticketMatches = $this->intakeTicketQuery()
            ->where('sender_email', $senderEmail)
            ->whereNull('email_body_hash')
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'closed')
            ->where(function ($query) {
                $query->whereNull('is_deleted')->orWhere('is_deleted', false);
            })
            ->get()
            ->filter(fn (Ticket $ticket) => $this->emailBodyHash($ticket->description ?? '') === $emailBodyHash);

        $commentMatches = $this->intakeCommentQuery()
            ->where('sender_email', $senderEmail)
            ->whereNull('email_body_hash')
            ->where('created_at', '>=', $since)
            ->whereHas('ticket', function ($query) {
                $query->withoutGlobalScope(ActiveEntityScope::class)
                    ->where('status', '!=', 'closed')
                    ->where(function ($ticketQuery) {
                        $ticketQuery->whereNull('is_deleted')->orWhere('is_deleted', false);
                    });
            })
            ->get()
            ->filter(fn (TicketComment $comment) => $this->emailBodyHash($comment->comment_text ?? '') === $emailBodyHash)
            ->pluck('ticket')
            ->filter();

        return $ticketMatches->merge($commentMatches);
    }

    protected function chooseOriginalTicket($tickets): ?Ticket
    {
        return collect($tickets)
            ->filter()
            ->unique('id')
            ->sort(function (Ticket $left, Ticket $right) {
                $leftIsChild = $left->parent_id ? 1 : 0;
                $rightIsChild = $right->parent_id ? 1 : 0;

                if ($leftIsChild !== $rightIsChild) {
                    return $leftIsChild <=> $rightIsChild;
                }

                return strcmp((string) $left->created_at, (string) $right->created_at);
            })
            ->first();
    }

    protected function messageAlreadyProcessed(array $messageIds): bool
    {
        $messageIds = collect($messageIds)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($messageIds)) {
            return false;
        }

        return $this->intakeTicketQuery()->whereIn('message_id', $messageIds)->exists()
            || TicketComment::whereIn('message_id', $messageIds)->exists();
    }

    protected function messageIdsFromHeaders(...$headers): array
    {
        $messageIds = [];

        foreach ($headers as $header) {
            foreach ($this->flattenHeaderValues($header) as $value) {
                $stringValue = trim((string) $value);
                if ($stringValue === '') {
                    continue;
                }

                if (preg_match_all('/<([^>]+)>/', $stringValue, $matches) && !empty($matches[1])) {
                    foreach ($matches[1] as $match) {
                        $messageIds = array_merge($messageIds, $this->messageIdentifierVariants($match));
                    }
                    continue;
                }

                foreach (preg_split('/[\s,]+/', $stringValue) ?: [] as $part) {
                    $messageIds = array_merge($messageIds, $this->messageIdentifierVariants($part));
                }
            }
        }

        return array_values(array_unique(array_filter($messageIds)));
    }

    protected function flattenHeaderValues($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_iterable($value)) {
            $values = [];
            foreach ($value as $item) {
                $values = array_merge($values, $this->flattenHeaderValues($item));
            }
            return $values;
        }

        return [$value];
    }

    protected function messageIdentifierVariants($messageId): array
    {
        $raw = trim((string) $messageId);
        $normalized = $this->normalizeMessageId($raw);

        return array_values(array_unique(array_filter([$raw, $normalized])));
    }

    protected function normalizeMessageId($messageId): string
    {
        return strtolower(trim((string) $messageId, " \t\n\r\0\x0B<>"));
    }

    /**
     * The original Message-ID with its case and angle brackets preserved, for use
     * in outgoing In-Reply-To / References headers so mail clients thread replies
     * under the original conversation. (The `message_id` column is lowercased for
     * dedup, which would break case-sensitive RFC 5322 message-id matching.)
     */
    protected function originalMessageIdForThreading($message): ?string
    {
        $raw = trim((string) $message->getMessageId(), " \t\n\r\0\x0B");

        return $raw === '' ? null : mb_substr($raw, 0, 255);
    }

    protected function normalizeEmailSubject(string $subject): string
    {
        $subject = trim($subject);

        do {
            $previous = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $previous);

        return trim($subject);
    }

    /**
     * Add the email's To/CC recipients to the ticket's CC list so that future
     * replies notify everyone who was originally looped in.
     *
     * Always excludes: the support inbox, the sender, the ticket assignee, and
     * automated addresses (no-reply / mailer-daemon style). Existing CCs are never
     * removed — this only unions in new addresses, so manual edits are preserved.
     */
    protected function syncCcsFromEmail(Ticket $ticket, $message, string $senderEmail): void
    {
        // CC list lives on the parent ticket; children inherit it (see Ticket::effectiveCcs).
        $owner = $ticket->parent_id ? ($ticket->parent ?? Ticket::find($ticket->parent_id)) : $ticket;
        if (!$owner) {
            return;
        }

        // EVERY address of ours, not just the base inbox. A departmental
        // plus-address (support+scm@) is a recipient like any other as far as the
        // header parser is concerned, so without this it gets auto-CC'd onto its
        // own tickets and each outbound notification mails the inbox back.
        $ourAddresses = app(DepartmentMailRouter::class)->allAddresses();
        $senderEmail = $this->normalizeEmailAddress($senderEmail);
        $assigneeEmail = $owner->assignee_id
            ? $this->normalizeEmailAddress((string) User::where('id', $owner->assignee_id)->value('email'))
            : '';

        $candidates = $this->collectRecipientAddresses($message);

        if (empty($candidates)) {
            return;
        }

        $existing = $owner->ccs()
            ->pluck('email')
            ->map(fn ($e) => $this->normalizeEmailAddress($e))
            ->all();

        foreach ($candidates as $email => $name) {
            if (in_array($email, $ourAddresses, true) || $email === $senderEmail || $email === $assigneeEmail) {
                continue;
            }
            if ($this->isAutomatedAddress($email)) {
                continue;
            }
            if (in_array($email, $existing, true)) {
                continue;
            }

            $owner->ccs()->create([
                'email' => mb_substr($email, 0, 255),
                'name' => $name ? mb_substr($name, 0, 255) : null,
                'user_id' => User::where('email', $email)->value('id'),
                'created_by' => null,
            ]);

            $existing[] = $email;
            Log::debug("EmailTicketService: Auto-added CC {$email} to ticket {$owner->ticket_key}.");
        }
    }

    /**
     * Collect every To/CC recipient from a message as a [normalized email => display name] map.
     *
     * The Webklex parsed address objects (getTo()/getCc()) are unreliable in this IMAP setup —
     * that's why messageIsAddressedToSupportEmail falls back to the raw headers. We do the same
     * here: read the raw 'to'/'cc' headers for the addresses, then overlay any display names the
     * parsed address objects did manage to provide.
     */
    protected function collectRecipientAddresses($message): array
    {
        $candidates = [];

        // 1. Primary source: raw To/CC headers (robust against unparsed address objects).
        $headers = $this->messageHeaders($message);
        if ($headers) {
            foreach (['to', 'cc', 'toaddress', 'ccaddress'] as $headerName) {
                foreach ($this->flattenHeaderValues($headers->get($headerName)) as $value) {
                    foreach ($this->extractEmailAddresses((string) $value) as $email) {
                        $normalized = $this->normalizeEmailAddress($email);
                        if ($normalized !== '' && !array_key_exists($normalized, $candidates)) {
                            $candidates[$normalized] = null;
                        }
                    }
                }
            }
        }

        // 2. Overlay display names from the parsed address objects when available.
        foreach ([$message->getTo(), $message->getCc()] as $recipients) {
            foreach ($recipients ?: [] as $recipient) {
                $email = $this->normalizeEmailAddress($recipient->mail ?? '');
                if ($email === '') {
                    continue;
                }
                $name = $this->decodeMimeHeader($recipient->full ?? $recipient->personal ?? '');
                if (!array_key_exists($email, $candidates) || ($name !== '' && empty($candidates[$email]))) {
                    $candidates[$email] = $name !== '' ? $name : ($candidates[$email] ?? null);
                }
            }
        }

        return $candidates;
    }

    /**
     * Whether an address is an automated/non-deliverable mailbox we should not CC.
     * Mirrors the banned-sender list used when filtering inbound messages.
     */
    protected function isAutomatedAddress(string $email): bool
    {
        foreach (['mailer-daemon', 'postmaster', 'no-reply', 'noreply'] as $banned) {
            if (str_contains($email, $banned)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address the message was delivered to, as a normalized [email => true]
     * set — the input DepartmentMailRouter needs to decide both "is this ours?"
     * and "whose desk is it?".
     *
     * This replaces the old single-address messageIsAddressedToSupportEmail()
     * check: with plus-address routing there is no longer one expected recipient
     * to compare against, so we collect them all and let the router match.
     *
     * Scans the same surfaces the old gate did, and for the same reason — the
     * Webklex parsed address objects are unreliable in this IMAP setup, and the
     * envelope headers (delivered_to, x_original_to) are frequently the ONLY
     * place a plus-address survives when mail has been forwarded or aliased.
     */
    protected function collectRoutableRecipientAddresses($message): array
    {
        $addresses = [];

        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $recipients) {
            foreach ($recipients ?: [] as $recipient) {
                $email = $this->normalizeEmailAddress($recipient->mail ?? '');
                if ($email !== '') {
                    $addresses[$email] = true;
                }
            }
        }

        $headers = $this->messageHeaders($message);
        if (!$headers) {
            return $addresses;
        }

        foreach ($this->supportRecipientHeaderNames() as $headerName) {
            foreach ($this->flattenHeaderValues($headers->get($headerName)) as $value) {
                foreach ($this->extractEmailAddresses((string) $value) as $email) {
                    $email = $this->normalizeEmailAddress($email);
                    if ($email !== '') {
                        $addresses[$email] = true;
                    }
                }
            }
        }

        return $addresses;
    }

    protected function messageHeaders($message)
    {
        if (method_exists($message, 'getHeader')) {
            return $message->getHeader();
        }

        return $message->getHeaders();
    }

    protected function supportRecipientHeaderNames(): array
    {
        return [
            'to',
            'cc',
            'bcc',
            'toaddress',
            'ccaddress',
            'bccaddress',
            'delivered_to',
            'x_original_to',
            'envelope_to',
            'original_to',
        ];
    }

    protected function extractEmailAddresses(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    protected function extractCleanMessageBody($message): string
    {
        $textBody = $this->normalizeFetchedEmailBody((string) $message->getTextBody());
        $htmlBody = $this->normalizeFetchedEmailBody(
            $this->htmlEmailBodyToText((string) $message->getHTMLBody())
        );

        if ($textBody === '') {
            return $htmlBody;
        }

        if ($htmlBody === '') {
            return $textBody;
        }

        return mb_strlen($htmlBody, 'UTF-8') > mb_strlen($textBody, 'UTF-8')
            ? $htmlBody
            : $textBody;
    }

    /**
     * Returns a sanitized, rich-HTML version of the email body when it carries
     * tabular structure — that's the formatting the plain-text pipeline destroys.
     * For simple emails (no tables) we return null and keep the plain-text body.
     */
    protected function extractRichHtmlBody($message): ?string
    {
        $html = (string) $message->getHTMLBody();

        if (trim($html) === '' || stripos($html, '<table') === false) {
            return null;
        }

        $sanitized = $this->sanitizeEmailHtml($html);

        // Only keep it if the table actually survived sanitization.
        return stripos($sanitized, '<table') !== false ? $sanitized : null;
    }

    /**
     * Sanitize raw email HTML down to a safe display subset (tables, lists,
     * basic text formatting, links). Strips scripts/styles/iframes/forms,
     * all event handlers, inline styles, and javascript:/data: URLs so the
     * result is safe to render with v-html on the ticket page.
     */
    protected function sanitizeEmailHtml(string $html): string
    {
        // Remove obviously dangerous / noise blocks before DOM parsing.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|head|title|meta|link|o:p)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        $allowedTags = array_flip([
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
            'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'a',
        ]);
        $allowedAttrs = array_flip(['colspan', 'rowspan', 'href', 'title', 'align', 'valign']);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__email_root__">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        if (!$loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);

        // 1. Drop dangerous nodes entirely, including their subtree.
        foreach (iterator_to_array($xpath->query('//script | //style | //iframe | //object | //embed | //form | //input | //button | //textarea | //select')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $root = $xpath->query('//*[@id="__email_root__"]')->item(0);

        // 2. Walk every element: unwrap disallowed tags (keep their text), strip
        //    disallowed/dangerous attributes from allowed ones.
        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            if (!$el instanceof \DOMElement || $el === $root) {
                continue;
            }

            $tag = strtolower($el->nodeName);

            if (!isset($allowedTags[$tag])) {
                $this->unwrapDomNode($el);
                continue;
            }

            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);

                if (!isset($allowedAttrs[$name])) {
                    $el->removeAttribute($attr->name);
                    continue;
                }

                if ($name === 'href') {
                    $href = trim($attr->value);
                    if (preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
                        $el->removeAttribute('href');
                    } else {
                        $el->setAttribute('target', '_blank');
                        $el->setAttribute('rel', 'noopener noreferrer');
                    }
                }
            }
        }

        if (!$root) {
            return '';
        }

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * Replace an element with its children (keeps content, drops the tag).
     */
    protected function unwrapDomNode(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (!$parent) {
            return;
        }

        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }

    protected function htmlEmailBodyToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|li|tr|table|blockquote|h1|h2|h3|h4|h5|h6)>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<(p|div|li|tr|table|blockquote|h1|h2|h3|h4|h5|h6)\b[^>]*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(td|th)>/i', ' ', $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function normalizeFetchedEmailBody(string $body): string
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace(["\xe2\x80\xaf", "\xc2\xa0", "\t"], ' ', $body);
        $body = preg_replace("/[ \f\v]+/u", ' ', $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return trim($body);
    }

    protected function emailBodyHash(?string $body): ?string
    {
        $normalizedBody = $this->normalizeEmailBodyForHash($body ?? '');

        if (!$this->isMeaningfulEmailBody($normalizedBody)) {
            return null;
        }

        return hash('sha256', $normalizedBody);
    }

    protected function normalizeEmailBodyForHash(string $body): string
    {
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = str_replace(["\r\n", "\r", "\xe2\x80\xaf", "\xc2\xa0", "\t"], ' ', $body);
        $body = preg_replace('/\s+/u', ' ', trim($body)) ?? '';

        return mb_strtolower($body, 'UTF-8');
    }

    protected function normalizeEmailAddress($email): string
    {
        return strtolower(trim((string) $email));
    }

    protected function isMeaningfulEmailBody(string $normalizedBody): bool
    {
        if (mb_strlen($normalizedBody, 'UTF-8') < 25) {
            return false;
        }

        preg_match_all('/[\pL\pN]+/u', $normalizedBody, $matches);

        return count($matches[0] ?? []) >= 3;
    }

    /**
     * Decode MIME-encoded string (e.g. =?UTF-8?Q?...?=)
     */
    protected function decodeMimeHeader($string)
    {
        if (!$string) return '';

        // If no MIME-encoded words are present the string is already plain text —
        // just ensure it is valid UTF-8 and return it directly.
        if (strpos($string, '=?') === false) {
            return mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }

        // ICONV_MIME_DECODE_CONTINUE_ON_ERROR substitutes illegal chars with '?'
        // instead of aborting, so malformed/mixed-charset subjects do not crash the loop.
        $decoded = iconv_mime_decode($string, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        // Final fallback — mb extension handles a wider variety of charsets.
        return mb_decode_mimeheader($string) ?: $string;
    }

    protected function ticketAttachmentStoragePath(string $originalName): string
    {
        $baseName = basename(str_replace('\\', '/', $originalName));
        $safeName = preg_replace('/[^\pL\pN._-]+/u', '_', $baseName) ?: 'attachment';
        $safeName = trim($safeName, '._-');

        if ($safeName === '') {
            $safeName = 'attachment';
        }

        return 'ticket-attachments/'
            . now('Asia/Manila')->format('YmdHisv')
            . '_'
            . Str::uuid()
            . '_'
            . Str::limit($safeName, 160, '');
    }
}
