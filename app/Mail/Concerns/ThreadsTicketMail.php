<?php

namespace App\Mail\Concerns;

use App\Models\Ticket;
use Illuminate\Support\Str;

trait ThreadsTicketMail
{
    protected function ticketThreadSubject(Ticket $ticket, bool $reply = true): string
    {
        $subject = "[{$ticket->ticket_key}] {$ticket->title}";

        return $reply ? "Re: {$subject}" : $subject;
    }

    protected function addTicketThreadHeaders($message, Ticket $ticket): void
    {
        $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');

        $messageId = $this->ticketThreadMessageId($ticket);
        $message->getHeaders()->addTextHeader('In-Reply-To', $messageId);
        $message->getHeaders()->addTextHeader('References', $messageId);
    }

    protected function ticketThreadMessageId(Ticket $ticket): string
    {
        if (! $ticket->message_id) {
            $ticket->forceFill([
                'message_id' => $this->makeTicketMessageId($ticket),
            ])->saveQuietly();
        }

        return $this->formatMessageId($ticket->message_id);
    }

    protected function makeTicketMessageId(Ticket $ticket): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST)
            ?: Str::slug((string) config('app.name', 'ghelpdesk')) . '.local';

        $ticketKey = Str::slug((string) ($ticket->ticket_key ?: $ticket->id), '-');

        return "ticket-{$ticketKey}@{$host}";
    }

    protected function formatMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            return $messageId;
        }

        return "<{$messageId}>";
    }
}
