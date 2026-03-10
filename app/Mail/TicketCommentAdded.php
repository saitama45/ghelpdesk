<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketCommentAdded extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Ticket $ticket,
        public TicketComment $comment,
        public string $recipientName
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $status = strtoupper(str_replace('_', ' ', $this->ticket->status));
        $subject = $this->ticket->title;
        
        // Ensure subject starts with Re: if it's a reply to an external email
        if ($this->ticket->message_id && !str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $envelope = new Envelope(
            subject: $subject,
        );

        // If this ticket came from an email, set headers to thread it
        if ($this->ticket->message_id) {
            $envelope->using(function ($message) {
                $message->getHeaders()->addTextHeader('In-Reply-To', $this->ticket->message_id);
                $message->getHeaders()->addTextHeader('References', $this->ticket->message_id);
            });
        }

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.commented',
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
