<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketMail;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketMail;

    public function __construct(
        public Ticket $ticket,
        public string $recipientName,
        public string $oldStatus,
        public string $newStatus
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->ticketThreadSubject($this->ticket),
        );

        $envelope->using(function ($message) {
            $this->addTicketThreadHeaders($message, $this->ticket);
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
