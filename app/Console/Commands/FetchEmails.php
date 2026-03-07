<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webklex\IMAP\Facades\Client;

class FetchEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:fetch-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch unread emails from support inbox and convert them into tickets';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Connecting to IMAP server...");

        try {
            // Override config with database settings
            config([
                'imap.accounts.default.host' => \App\Models\Setting::get('imap_host', config('imap.accounts.default.host')),
                'imap.accounts.default.port' => \App\Models\Setting::get('imap_port', config('imap.accounts.default.port')),
                'imap.accounts.default.encryption' => \App\Models\Setting::get('imap_encryption', config('imap.accounts.default.encryption')),
                'imap.accounts.default.username' => \App\Models\Setting::get('imap_username', config('imap.accounts.default.username')),
                'imap.accounts.default.password' => \App\Models\Setting::get('imap_password', config('imap.accounts.default.password')),
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
                $this->error("Inbox folder not found.");
                return 1;
            }

            // Fetch only unseen messages for production
            $messages = $inbox->messages()->unseen()->get();
            $this->info("Found " . $messages->count() . " new messages.");

            foreach ($messages as $message) {
                $this->processMessage($message);
            }

            $client->disconnect();
            $this->info("Done.");
        } catch (\Exception $e) {
            $this->error("IMAP Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function processMessage($message)
    {
        $subject = $message->getSubject();
        $senderEmail = $message->getFrom()[0]->mail;
        $senderName = $message->getFrom()[0]->full;
        $messageId = $message->getMessageId();

        $this->info("Processing: {$subject} from {$senderEmail}");

        // Check if ticket already exists for this message ID (Deduplication)
        if (Ticket::where('message_id', $messageId)->exists()) {
            $this->warn("Ticket already exists for message ID: {$messageId}. Skipping.");
            $message->setFlag('Seen');
            return;
        }

        // Try to find a matching user in our system
        $user = User::where('email', $senderEmail)->first();

        try {
            DB::transaction(function () use ($message, $subject, $senderEmail, $senderName, $messageId, $user) {
                $body = $message->getTextBody() ?: $message->getHTMLBody();
                
                // Default company (TBG)
                $company = Company::where('code', 'TBG')->first() ?? Company::first();
                $companyId = $company ? $company->id : null;
                $companyCode = $company ? $company->code : 'EXT';

                // Generate Ticket Key (Consistent with TicketController)
                $maxNumber = Ticket::where('company_id', $companyId)
                    ->where('ticket_key', 'LIKE', "{$companyCode}-%")
                    ->get(['ticket_key'])
                    ->map(function ($ticket) {
                        if (preg_match('/-(\d+)$/', $ticket->ticket_key, $matches)) {
                            return (int) $matches[1];
                        }
                        return 0;
                    })
                    ->max();

                $nextNumber = ($maxNumber ?? 0) + 1;
                $ticketKey = "{$companyCode}-{$nextNumber}";

                // Create the ticket record
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

                // Handle Attachments
                $message->getAttachments()->each(function ($attachment) use ($ticket) {
                    $fileName = time() . '_' . $attachment->getName();
                    $filePath = 'ticket-attachments/' . $fileName;
                    
                    \Illuminate\Support\Facades\Storage::disk('public')->put($filePath, $attachment->getContent());

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_name' => $attachment->getName(),
                        'file_storage_path' => $filePath,
                        'file_size_bytes' => $attachment->size,
                    ]);
                });

                // Mark email as seen so it's not processed again
                $message->setFlag('Seen');
            });
        } catch (\Exception $e) {
            $this->error("Failed to process message: " . $e->getMessage());
        }
    }
}
