<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketMail;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketMergedNotification extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketMail;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Ticket $parentTicket,
        public $childTickets,
        public string $recipientName
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->ticketThreadSubject($this->parentTicket),
        );

        $envelope->using(function ($message) {
            $this->addTicketThreadHeaders($message, $this->parentTicket);
        });

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.merged',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
