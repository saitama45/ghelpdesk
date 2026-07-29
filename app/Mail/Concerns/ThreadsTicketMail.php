<?php

namespace App\Mail\Concerns;

use App\Models\Ticket;
use App\Services\DepartmentMailRouter;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;

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

        $this->applyTicketDepartmentIdentity($message, $ticket);
    }

    /**
     * Stamps the serving department's outbound identity on the message.
     *
     * Both values are configured per department in Settings (departments
     * .mail_from_name / .mail_alias) and read at send time — nothing here is
     * hard-coded, and a department with neither falls back to the global sender.
     *
     * Only the DISPLAY NAME and Reply-To vary. The From ADDRESS stays the one
     * global sender so SPF/DKIM/DMARC alignment holds and there is a single
     * credential to rotate — the reason this app routes by address instead of
     * giving each department its own mail configuration.
     *
     * $message here is a Symfony\Component\Mime\Email — Laravel unwraps the
     * Illuminate message before invoking Envelope::using callbacks. That matters:
     * Symfony's from()/replyTo() REPLACE, where Illuminate\Mail\Message's append.
     * A second From would make the message invalid and a second Reply-To would
     * split where replies land.
     */
    protected function applyTicketDepartmentIdentity($message, Ticket $ticket): void
    {
        $router = app(DepartmentMailRouter::class);
        $departmentId = $ticket->serving_department_id;

        $fromAddress = (string) config('mail.from.address', '');
        $fromName = $router->fromNameFor($departmentId);

        if ($fromAddress !== '') {
            $message->from(new Address($fromAddress, $fromName));
        }

        // Replies land on the desk that owns the ticket, which is what lets the
        // inbound router put the response back on the same department.
        $replyTo = $router->replyToFor($departmentId);

        if ($replyTo !== '') {
            $message->replyTo(new Address($replyTo));
        }
    }

    protected function ticketThreadMessageId(Ticket $ticket): string
    {
        // Prefer the customer's original Message-ID with its case preserved — mail
        // clients (Gmail) match In-Reply-To / References case-sensitively, so the
        // lowercased `message_id` (kept for dedup) would not thread.
        if ($ticket->source_message_id) {
            return $this->formatMessageId($ticket->source_message_id);
        }

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
