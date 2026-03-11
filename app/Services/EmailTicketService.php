<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;

class EmailTicketService
{
    public function fetchAndProcess()
    {
        // 1. Check if we should sync (Optional: throttle to 30 seconds to avoid IMAP overhead)
        $lastSync = Setting::get('last_email_sync_at');
        if ($lastSync && now()->parse($lastSync)->addSeconds(30)->isFuture()) {
            return ['status' => 'skipped', 'message' => 'Synced recently.'];
        }

        try {
            // 2. Configure IMAP from Database Settings
            config([
                'imap.accounts.default.host' => Setting::get('imap_host', config('imap.accounts.default.host')),
                'imap.accounts.default.port' => Setting::get('imap_port', config('imap.accounts.default.port')),
                'imap.accounts.default.encryption' => Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
                'imap.accounts.default.username' => Setting::get('imap_username', config('imap.accounts.default.username')),
                'imap.accounts.default.password' => Setting::get('imap_password', config('imap.accounts.default.password')),
                'imap.options.fetch_order' => 'desc',
            ]);

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

            if (!$inbox) {
                return ['status' => 'error', 'message' => 'Inbox not found.'];
            }

            // Process unseen messages - using DESC order via config to get newest first
            $messages = $inbox->messages()->unseen()->get();
            $count = 0;

            foreach ($messages as $message) {
                if ($this->processMessage($message)) {
                    $count++;
                }
            }

            // 3. Update Last Sync Time
            Setting::set('last_email_sync_at', now()->toDateTimeString(), 'system');

            $client->disconnect();

            return [
                'status' => 'success',
                'message' => "Processed {$count} new tickets.",
                'count' => $count
            ];

        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function processMessage($message)
    {
        $messageId = $message->getMessageId();

        // 1. Deduplication
        if (Ticket::where('message_id', $messageId)->exists()) {
            $message->setFlag('Seen');
            return false;
        }

        $senderEmail = strtolower($message->getFrom()[0]->mail ?? '');
        $supportEmail = strtolower(Setting::get('imap_username', ''));

        // 2. Ignore Bounce Messages (Mailer-Daemon, Postmaster, etc.)
        $bannedSenders = ['mailer-daemon', 'postmaster', 'no-reply', 'noreply'];
        foreach ($bannedSenders as $banned) {
            if (str_contains($senderEmail, $banned)) {
                $message->setFlag('Seen');
                return false;
            }
        }

        // 3. Recipient Check (Ensure the email was actually sent TO/CC/BCC the support account)
        // Check TO, CC, and BCC fields
        $isDirectlySent = false;
        
        $to = $message->getTo();
        $cc = $message->getCc();
        $bcc = $message->getBcc();

        foreach ([$to, $cc, $bcc] as $recipients) {
            if ($recipients) {
                foreach ($recipients as $recipient) {
                    if (isset($recipient->mail) && strtolower($recipient->mail) === $supportEmail) {
                        $isDirectlySent = true;
                        break 2;
                    }
                }
            }
        }

        // Fallback: If headers are empty or recipient not found but it's in the INBOX,
        // we might want to process it anyway if it's not obviously spam/bounce.
        if (!$isDirectlySent && $supportEmail) {
            // Attempt to check raw headers as a last resort
            $headers = $message->getHeaders();
            if (str_contains(strtolower((string)$headers->get('to')), $supportEmail) || 
                str_contains(strtolower((string)$headers->get('cc')), $supportEmail)) {
                $isDirectlySent = true;
            }
            
            // Final Fallback: If we are in the INBOX and it's not a bounce, process it.
            // This handles cases where Gmail/IMAP might not return all header fields correctly.
            if (!$isDirectlySent) {
                $isDirectlySent = true; 
            }
        }

        if (!$isDirectlySent) {
            $message->setFlag('Seen');
            return false;
        }

        $subject = $this->decodeMimeHeader($message->getSubject());
        $senderName = $this->decodeMimeHeader($message->getFrom()[0]->full ?? $senderEmail);
        $user = User::where('email', $senderEmail)->first();

        // --- THREADING LOGIC ---
        $existingTicket = null;

        // 1. Check In-Reply-To and References headers
        $references = collect($message->getReferences())->merge($message->getInReplyTo())->filter()->unique();
        if ($references->isNotEmpty()) {
            $existingTicket = Ticket::whereIn('message_id', $references)->first();
        }

        // 2. Fallback: Check subject for Ticket Key (e.g., [TBG-123])
        if (!$existingTicket && preg_match('/\[([A-Z0-9]+-\d+)\]/', $subject, $matches)) {
            $existingTicket = Ticket::where('ticket_key', $matches[1])->first();
        }

        // 3. Fallback: Exact subject match (ignoring Re:)
        if (!$existingTicket) {
            $cleanSubject = preg_replace('/^(Re|Fwd):\s+/i', '', $subject);
            $existingTicket = Ticket::where('title', $cleanSubject)
                ->where('sender_email', $senderEmail)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($existingTicket) {
            return $this->addEmailAsComment($existingTicket, $message, $user);
        }

        return DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user) {
            $body = $message->getTextBody();
            
            // If text body is empty, extract from HTML body carefully
            if (!$body) {
                $html = $message->getHTMLBody();
                // Replace common block tags with newlines to preserve structure before stripping tags
                $html = preg_replace('/<(br|p|div|li|tr|h1|h2|h3|h4|h5|h6)[^>]*>/i', "\n$0", $html);
                $body = strip_tags($html);
                // Decode HTML entities after stripping tags
                $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            
            $cleanBody = $this->stripQuotedText($body);
            
            $company = Company::where('code', 'TBG')->first() ?? Company::first();
            $companyId = $company ? $company->id : null;
            $companyCode = $company ? $company->code : 'EXT';

            // Generate Ticket Key
            $maxNumber = Ticket::where('company_id', $companyId)
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
                'title' => $subject,
                'description' => substr($cleanBody, 0, 65535),
                'type' => 'task',
                'status' => 'open',
                'priority' => 'medium',
                'severity' => 'minor',
                'reporter_id' => $user ? $user->id : null,
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'message_id' => $messageId,
                'company_id' => $companyId,
            ]);

            // Attachments
            $message->getAttachments()->each(function ($attachment) use ($ticket) {
                $fileName = time() . '_' . $this->decodeMimeHeader($attachment->getName());
                $filePath = 'ticket-attachments/' . $fileName;
                Storage::disk('public')->put($filePath, $attachment->getContent());

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'file_name' => $this->decodeMimeHeader($attachment->getName()),
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
    protected function addEmailAsComment(Ticket $ticket, $message, $user)
    {
        $senderEmail = strtolower($message->getFrom()[0]->mail ?? '');
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

        return DB::transaction(function () use ($ticket, $message, $user, $senderEmail, $senderName) {
            $body = $message->getTextBody();
            
            // If text body is empty, extract from HTML body carefully
            if (!$body) {
                $html = $message->getHTMLBody();
                // Replace common block tags with newlines to preserve structure before stripping tags
                $html = preg_replace('/<(br|p|div|li|tr|h1|h2|h3|h4|h5|h6)[^>]*>/i', "\n$0", $html);
                $body = strip_tags($html);
                // Decode HTML entities after stripping tags
                $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            
            $cleanBody = $this->stripQuotedText($body);

            // Create the comment
            $comment = \App\Models\TicketComment::create([
                'ticket_id' => $ticket->id,
                'comment_text' => substr($cleanBody, 0, 65535),
                'user_id' => $user ? $user->id : null,
                'sender_email' => $user ? null : $senderEmail,
                'sender_name' => $user ? null : $senderName,
                'created_at' => now('Asia/Manila'),
            ]);

            // RE-OPEN TRIGGER: If a customer replies to an Open, Waiting, or Resolved ticket,
            // set status to Open to alert the staff.
            if (in_array($ticket->status, ['waiting', 'resolved'])) {
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
                $fileName = time() . '_' . $this->decodeMimeHeader($attachment->getName());
                $filePath = 'ticket-attachments/' . $fileName;
                Storage::disk('public')->put($filePath, $attachment->getContent());

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'comment_id' => $comment->id,
                    'file_name' => $this->decodeMimeHeader($attachment->getName()),
                    'file_storage_path' => $filePath,
                    'file_size_bytes' => $attachment->size,
                ]);
            });

            $message->setFlag('Seen');
            return true;
        });
    }

    /**
     * Strip quoted text and reply headers from an email body.
     */
    protected function stripQuotedText($body)
    {
        // 1. Decode HTML entities first
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 2. Normalize line endings and whitespace
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        
        // Replace various whitespace characters with regular spaces
        $body = str_replace(["\xe2\x80\xaf", "\xc2\xa0", "\t"], " ", $body);
        
        // 3. Custom separator (Hard cut)
        $separator = "### Please type your reply above this line ###";
        if (str_contains($body, $separator)) {
            $parts = explode($separator, $body);
            $body = $parts[0]; // Truncate but CONTINUE to clean with regex
        }

        // 4. Regex markers for reply headers
        $markers = [
            // Standard "On [date], [name] <[email]> wrote:"
            // Aggressive multi-line check to catch 'On' followed by 'wrote' or 'sent'
            '/\n\s*On\s+.{1,150}?(?:\d{4}|\d{1,2}:\d{2}).{1,100}?(?:wrote|sent):?/is',
            '/\bOn\s+.{1,150}?(?:\d{4}|\d{1,2}:\d{2}).{1,100}?(?:wrote|sent):?/is',
            
            // "From: [name] [mailto:email] Sent: [date] To: [name]"
            '/\n\s*From:\s+.{1,150}?\n?(?:Sent|To|Subject):/is',
            '/\bFrom:\s+.{1,150}?\n?(?:Sent|To|Subject):/is',
            
            // Delimiter lines
            '/-+\s*Original Message\s*-+/i',
            '/________________________________/i',
            '/-+\s*Forwarded message\s*-+/i',
        ];

        $earliest = strlen($body);

        foreach ($markers as $marker) {
            if (preg_match($marker, $body, $matches, PREG_OFFSET_CAPTURE)) {
                $pos = $matches[0][1];
                if ($pos < $earliest) {
                    $earliest = $pos;
                }
            }
        }

        // Truncate at the earliest marker found
        if ($earliest < strlen($body)) {
            $body = substr($body, 0, $earliest);
        }

        // 5. Line-by-line cleanup for standard signatures and loose quotes
        $lines = explode("\n", $body);
        $cleanLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Signature marker (standard --)
            if ($trimmed === '--' || $trimmed === '-- ') {
                break;
            }

            // Mobile app signature markers
            if (preg_match('/^Sent from my (?:iPhone|Android|Samsung|iPad|device)/i', $trimmed)) {
                break;
            }

            // If we hit a line that starts with a quote character '>', 
            // it's a clear sign we've entered the quoted history
            if (str_starts_with($trimmed, '>')) {
                break;
            }

            $cleanLines[] = $line;
        }

        $result = trim(implode("\n", $cleanLines));

        // 6. Fallback: If we stripped everything, return original body trimmed
        if (empty($result)) {
             return trim($body);
        }

        return $result;
    }

    /**
     * Decode MIME-encoded string (e.g. =?UTF-8?Q?...?=)
     */
    protected function decodeMimeHeader($string)
    {
        if (!$string) return '';
        
        // iconv_mime_decode is robust for handling various charsets and malformed strings.
        return iconv_mime_decode($string, 0, 'UTF-8') ?: $string;
    }
}
