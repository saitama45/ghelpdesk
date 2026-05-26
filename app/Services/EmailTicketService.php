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
            // 2. Configure IMAP from Database Settings
            $imapConfig = [
                'imap.accounts.default.host' => Setting::get('imap_host', config('imap.accounts.default.host')),
                'imap.accounts.default.port' => Setting::get('imap_port', config('imap.accounts.default.port')),
                'imap.accounts.default.encryption' => Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
                'imap.accounts.default.username' => Setting::get('imap_username', config('imap.accounts.default.username')),
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
        $isDirectlySent = false;
        $to = $message->getTo();
        $cc = $message->getCc();
        $bcc = $message->getBcc();

        foreach ([$to, $cc, $bcc] as $recipients) {
            if ($recipients) {
                foreach ($recipients as $recipient) {
                    if (isset($recipient->mail) && $this->normalizeEmailAddress($recipient->mail) === $supportEmail) {
                        $isDirectlySent = true;
                        break 2;
                    }
                }
            }
        }

        if (!$isDirectlySent) {
            Log::debug("EmailTicketService: Message {$messageId} not directly sent to {$supportEmail}. Checking fallback...");
            
            // Fallback
            if ($supportEmail) {
                $headers = $message->getHeaders();
                if (str_contains(strtolower((string)$headers->get('to')), $supportEmail) ||
                    str_contains(strtolower((string)$headers->get('cc')), $supportEmail)) {
                    $isDirectlySent = true;
                }
            }
            
            // Final Fallback (If it's in the inbox, we usually want it)
            if (!$isDirectlySent) {
                Log::info("EmailTicketService: Message {$messageId} hit final fallback to TRUE.");
                $isDirectlySent = true; 
            }
        }

        if (!$isDirectlySent) {
            Log::info("EmailTicketService: Skipping message {$messageId} - Not for support email {$supportEmail}.");
            $message->setFlag('Seen');
            return false;
        }


        $subject = $this->decodeMimeHeader($message->getSubject());
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);
        $user = User::where('email', $senderEmail)->first();
        $cleanBody = $this->extractCleanMessageBody($message);
        $emailBodyHash = $this->emailBodyHash($cleanBody);

        // --- THREADING LOGIC ---
        $existingTicket = $this->findExistingTicketForMessage($message, $subject, $senderEmail, $emailBodyHash);

        if ($existingTicket) {
            return $this->addEmailAsComment($existingTicket, $message, $user, $cleanBody, $emailBodyHash, $messageId);
        }

        return DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user, $cleanBody, $emailBodyHash) {
            $company = Company::where('code', 'TBG')->first() ?? Company::first();
            $companyId = $company ? $company->id : null;
            $companyCode = $company ? $company->code : 'EXT';

            // Generate Ticket Key
            $maxNumber = Ticket::withTrashed()
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
                'type' => 'task',
                'status' => 'open',
                'priority' => 'medium',
                'severity' => 'minor',
                'reporter_id' => $user ? $user->id : null,
                'sender_email' => mb_substr($senderEmail, 0, 255),
                'sender_name' => mb_substr($senderName, 0, 255),
                'message_id' => $messageId ? mb_substr($messageId, 0, 255) : null,
                'email_body_hash' => $emailBodyHash,
                'company_id' => $companyId,
            ]);

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
    protected function addEmailAsComment(Ticket $ticket, $message, $user, ?string $cleanBody = null, ?string $emailBodyHash = null, ?string $messageId = null)
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

        return DB::transaction(function () use ($ticket, $message, $user, $senderEmail, $senderName, $cleanBody, $emailBodyHash, $messageId) {
            // Create the comment
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'comment_text' => $cleanBody,
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

    protected function normalizeEmailSubject(string $subject): string
    {
        $subject = trim($subject);

        do {
            $previous = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $previous);

        return trim($subject);
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
