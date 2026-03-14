<?php

namespace App\Console\Commands;

use App\Services\EmailTicketService;
use Illuminate\Console\Command;

class FetchEmails extends Command
{
    protected $signature = 'tickets:fetch-emails';
    protected $description = 'Fetch unread emails from support inbox and convert them into tickets';

    public function handle(EmailTicketService $service)
    {
        \Illuminate\Support\Facades\Log::info("FetchEmails Command: Signature tickets:fetch-emails triggered.");
        $this->info("Connecting to IMAP server via Service...");
        $result = $service->fetchAndProcess();

        if ($result['status'] === 'success') {
            $this->info($result['message']);
        } elseif ($result['status'] === 'skipped') {
            $this->warn($result['message']);
        } else {
            $this->error($result['message']);
        }

        return 0;
    }
}
