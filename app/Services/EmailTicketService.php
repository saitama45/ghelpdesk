<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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

            // 2. Configure IMAP from Database Settings
            $imapConfig = [
                'imap.accounts.default.host' => Setting::get('imap_host', config('imap.accounts.default.host')),
                'imap.accounts.default.port' => Setting::get('imap_port', config('imap.accounts.default.port')),
                'imap.accounts.default.encryption' => Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
                'imap.accounts.default.username' => $supportEmail,
                'imap.accounts.default.password' => Setting::get('imap_password', config('imap.accounts.default.password')),
                'imap.options.fetch_order' => 'desc',
            ];
            
            config($imapConfig);

            Log::debug("EmailTicketService: Connecting to " . $imapConfig['imap.accounts.default.host'] . " as " . $imapConfig['imap.accounts.default.username']);

            $client = Client::account('default');
            $client->connect();

            Log::debug("EmailTicketService: Connected. Available folders: " . $client->getFolders()->map(fn($f) => $f->name)->implode(', '));

            $folders = $client->getFolders();
            $inbox = null;

            foreach ($folders as $folder) {
                if (strtolower($folder->name) === 'inbox') {
                    $inbox = $folder;
                    break;
                }
            }

            if (!$inbox) {
                Log::error("EmailTicketService: Inbox folder not found.");
                return ['status' => 'error', 'message' => 'Inbox not found. Available: ' . $client->getFolders()->map(fn($f) => $f->name)->implode(', ')];
            }

            // Diagnostic: Count unseen messages only
            $query = $inbox->messages()->unseen();
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
                try {
                    Log::debug("EmailTicketService: Checking message: " . $message->getSubject());
                    if ($this->processMessage($message)) {
                        $count++;
                    }
                } catch (\Throwable $me) {
                    $errorMsg = $me->getMessage();
                    Log::error("EmailTicketService: Message processing error: " . $errorMsg);
                    $errors[] = "Subject '" . mb_substr($message->getSubject(), 0, 50) . "': " . $errorMsg;
                }
            }


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


    protected function processMessage($message)
    {
        $messageId = $this->normalizeMessageId($message->getMessageId());
        $messageIdCandidates = $this->messageIdentifierVariants($message->getMessageId());
        $senderEmail = $this->normalizeEmailAddress($message->getFrom()[0]->mail ?? '');
        Log::debug("EmailTicketService: Processing message {$messageId} from {$senderEmail}");

        // 1. Deduplication
        if ($this->messageAlreadyProcessed($messageIdCandidates)) {
            Log::info("EmailTicketService: Skipping message {$messageId} - Ticket already exists.");
            $message->setFlag('Seen');
            return false;
        }

        $supportEmail = $this->normalizeEmailAddress(Setting::get('imap_username', ''));
        if ($supportEmail === '') {
            Log::warning("EmailTicketService: Skipping message {$messageId} - No inbound support email is configured.");
            return false;
        }

        // 2. Ignore Bounce Messages
        $bannedSenders = ['mailer-daemon', 'postmaster', 'no-reply', 'noreply'];
        foreach ($bannedSenders as $banned) {
            if (str_contains($senderEmail, $banned)) {
                Log::info("EmailTicketService: Skipping message {$messageId} - Banned sender.");
                $message->setFlag('Seen');
                return false;
            }
        }

        // 3. Recipient Check
        if (!$this->messageIsAddressedToSupportEmail($message, $supportEmail)) {
            Log::info("EmailTicketService: Skipping message {$messageId} - Not for support email {$supportEmail}.");
            $message->setFlag('Seen');
            return false;
        }


        $subject = $this->decodeMimeHeader($message->getSubject());
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);
        $user = User::where('email', $senderEmail)->first();
        $cleanBody = $this->extractCleanMessageBody($message);
        $emailBodyHash = $this->emailBodyHash($cleanBody);
        $richBody = $this->extractRichHtmlBody($message);

        // --- THREADING LOGIC ---
        $existingTicket = $this->findExistingTicketForMessage($message, $subject, $senderEmail, $emailBodyHash);

        if ($existingTicket) {
            return $this->addEmailAsComment($existingTicket, $message, $user, $cleanBody, $emailBodyHash, $messageId, $richBody);
        }

        return DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user, $cleanBody, $emailBodyHash, $richBody) {
            $company = Company::where('code', 'TBG')->first() ?? Company::first();
            $companyId = $company ? $company->id : null;
            $companyCode = $company ? $company->code : 'EXT';

            // Generate Ticket Key
            $maxNumber = Ticket::withTrashed()
                ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
                ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                ->get(['ticket_key'])
                ->map(function ($t) {
                    if (preg_match('/-(\d+)$/', $t->ticket_key, $matches)) {
                        return (int) $matches[1];
                    }
                    return 0;
                })
                ->max();

            $nextNumber = ($maxNumber ?? 0) + 1;
            $ticketKey = "{$companyCode}-{$nextNumber}";

            $ticket = Ticket::create([
                'ticket_key' => $ticketKey,
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
            return true;
        });
    }

    /**
     * Add the incoming email content as a comment to an existing ticket.
     */
    protected function addEmailAsComment(Ticket $ticket, $message, $user, ?string $cleanBody = null, ?string $emailBodyHash = null, ?string $messageId = null, ?string $richBody = null)
    {
        $messageId ??= $this->normalizeMessageId($message->getMessageId());
        $senderEmail = $this->normalizeEmailAddress($message->getFrom()[0]->mail ?? '');
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);

        // LOCK-OUT LOGIC: If ticket is closed, do not allow new comments via email.
        // Send a notification to the customer instead.
        if ($ticket->status === 'closed') {
            \Illuminate\Support\Facades\Mail::to($senderEmail)->send(
                new \App\Mail\ClosedTicketReplyNotification($ticket, $senderName)
            );
            
            Log::info("Email stripping: Sent ClosedTicketReplyNotification to {$senderEmail} for ticket {$ticket->ticket_key}");
            $message->setFlag('Seen');
            return true;
        }

        $cleanBody ??= $this->extractCleanMessageBody($message);
        $emailBodyHash ??= $this->emailBodyHash($cleanBody);
        $richBody ??= $this->extractRichHtmlBody($message);

        return DB::transaction(function () use ($ticket, $message, $user, $senderEmail, $senderName, $cleanBody, $emailBodyHash, $messageId, $richBody) {
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

            $message->setFlag('Seen');
            return true;
        });
    }

    protected function findExistingTicketForMessage($message, string $subject, string $senderEmail, ?string $emailBodyHash): ?Ticket
    {
        // 1. Check In-Reply-To and References headers against tickets and email comments.
        $references = $this->messageIdsFromHeaders($message->getReferences(), $message->getInReplyTo());
        $existingTicket = $this->findTicketByMessageIds($references);

        if ($existingTicket && $existingTicket->status === 'closed' && $existingTicket->updated_at->addDays(3)->isPast()) {
            Log::info("EmailTicketService: Matched closed ticket {$existingTicket->ticket_key} via message IDs, but it was closed more than 3 days ago. Bypassing to create a new ticket.");
            $existingTicket = null;
        }

        if ($existingTicket) {
            return $existingTicket;
        }

        // 2. Fallback: Check subject for Ticket Key (e.g., [TBG-123] or #TBG-123).
        if (preg_match('/\b([A-Z0-9]+-\d+)\b/i', $subject, $matches)) {
            $existingTicket = Ticket::where('ticket_key', strtoupper($matches[1]))->first();

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
            $existingTicket = Ticket::where('sender_email', $senderEmail)
                ->orderBy('created_at', 'desc')
                ->get()
                ->first(fn (Ticket $ticket) => $this->normalizeEmailSubject($ticket->title ?? '') === $cleanSubject);

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

    protected function findTicketByMessageIds(array $messageIds): ?Ticket
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

        $ticket = Ticket::whereIn('message_id', $messageIds)
            ->orderBy('created_at')
            ->first();

        if ($ticket) {
            return $ticket;
        }

        $comment = TicketComment::with('ticket')
            ->whereIn('message_id', $messageIds)
            ->orderBy('created_at')
            ->first();

        return $comment?->ticket;
    }

    protected function findTicketBySenderAndBodyHash(string $senderEmail, string $emailBodyHash): ?Ticket
    {
        $since = now('Asia/Manila')->subDays(90);
        $candidates = collect();

        $ticketMatches = Ticket::query()
            ->where('sender_email', $senderEmail)
            ->where('email_body_hash', $emailBodyHash)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'closed')
            ->where(function ($query) {
                $query->whereNull('is_deleted')->orWhere('is_deleted', false);
            })
            ->get();

        $commentMatches = TicketComment::with('ticket')
            ->where('sender_email', $senderEmail)
            ->where('email_body_hash', $emailBodyHash)
            ->where('created_at', '>=', $since)
            ->whereHas('ticket', function ($query) {
                $query->where('status', '!=', 'closed')
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
        $ticketMatches = Ticket::query()
            ->where('sender_email', $senderEmail)
            ->whereNull('email_body_hash')
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'closed')
            ->where(function ($query) {
                $query->whereNull('is_deleted')->orWhere('is_deleted', false);
            })
            ->get()
            ->filter(fn (Ticket $ticket) => $this->emailBodyHash($ticket->description ?? '') === $emailBodyHash);

        $commentMatches = TicketComment::with('ticket')
            ->where('sender_email', $senderEmail)
            ->whereNull('email_body_hash')
            ->where('created_at', '>=', $since)
            ->whereHas('ticket', function ($query) {
                $query->where('status', '!=', 'closed')
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

        return Ticket::whereIn('message_id', $messageIds)->exists()
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

        $supportEmail = $this->normalizeEmailAddress(Setting::get('imap_username', config('imap.accounts.default.username')));
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
            if ($email === $supportEmail || $email === $senderEmail || $email === $assigneeEmail) {
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

    protected function messageIsAddressedToSupportEmail($message, string $supportEmail): bool
    {
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $recipients) {
            foreach ($recipients ?: [] as $recipient) {
                if (isset($recipient->mail) && $this->normalizeEmailAddress($recipient->mail) === $supportEmail) {
                    return true;
                }
            }
        }

        $headers = $this->messageHeaders($message);
        if (!$headers) {
            return false;
        }

        foreach ($this->supportRecipientHeaderNames() as $headerName) {
            if ($this->headerContainsEmailAddress($headers->get($headerName), $supportEmail)) {
                return true;
            }
        }

        return false;
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

    protected function headerContainsEmailAddress($header, string $expectedEmail): bool
    {
        foreach ($this->flattenHeaderValues($header) as $value) {
            foreach ($this->extractEmailAddresses((string) $value) as $email) {
                if ($this->normalizeEmailAddress($email) === $expectedEmail) {
                    return true;
                }
            }
        }

        return false;
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
