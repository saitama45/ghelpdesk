<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClosedTicketReplyNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $ticket;
    public $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(Ticket $ticket, $recipientName)
    {
        $this->ticket = $ticket;
        $this->recipientName = $recipientName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->ticket->title;
        if (!str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $envelope = new Envelope(
            subject: $subject,
        );

        $envelope->using(function ($message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            
            if ($this->ticket->message_id) {
                $message->getHeaders()->addTextHeader('In-Reply-To', $this->ticket->message_id);
                $message->getHeaders()->addTextHeader('References', $this->ticket->message_id);
            }
        });

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.closed-reply',
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
