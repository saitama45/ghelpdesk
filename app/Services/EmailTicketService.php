<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Deduplication
        if (Ticket::where('message_id', $messageId)->exists()) {
            $message->setFlag('Seen');
            return false;
        }

        $subject = $message->getSubject();
        $senderEmail = $message->getFrom()[0]->mail;
        $senderName = $message->getFrom()[0]->full;
        $user = User::where('email', $senderEmail)->first();

        return DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user) {
            $body = $message->getTextBody() ?: $message->getHTMLBody();
            
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
                'description' => substr($body, 0, 65535),
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
                $fileName = time() . '_' . $attachment->getName();
                $filePath = 'ticket-attachments/' . $fileName;
                Storage::disk('public')->put($filePath, $attachment->getContent());

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'file_name' => $attachment->getName(),
                    'file_storage_path' => $filePath,
                    'file_size_bytes' => $attachment->size,
                ]);
            });

            $message->setFlag('Seen');
            return true;
        });
    }
}
