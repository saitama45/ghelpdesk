<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $recipientName,
        public string $oldStatus,
        public string $newStatus
    ) {}

    public function envelope(): Envelope
    {
        $status = strtoupper(str_replace('_', ' ', $this->newStatus));
        $envelope = new Envelope(
            subject: "[{$this->ticket->ticket_key}] [{$status}] Status Changed: {$this->ticket->title}",
        );

        $envelope->using(function ($message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        });

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.status-changed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
