<?php

namespace App\Jobs;

use App\Models\Scopes\ActiveEntityScope;
use App\Models\Ticket;
use App\Services\TicketCreationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Keeps SMTP delivery outside the ticket-creation web request. */
class SendTicketCreationNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $ticketId,
        public bool $notifyRequester = true,
    ) {}

    public function handle(TicketCreationNotifier $notifier): void
    {
        $ticket = Ticket::withoutGlobalScope(ActiveEntityScope::class)->find($this->ticketId);

        if ($ticket) {
            $notifier->send($ticket, $this->notifyRequester);
        }
    }
}
