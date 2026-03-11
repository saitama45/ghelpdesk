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
        $subject = $this->ticket->title;
        
        // To maintain threading in Yahoo/Gmail/Outlook, we must keep the original subject 
        // and only prepend "Re: " if it's not already there.
        if (!str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $envelope = new Envelope(
            subject: $subject,
        );

        // Set headers to improve deliverability and threading
        $envelope->using(function ($message) {
            // Auto-Submitted header helps bypass some automated spam filters 
            // without breaking the conversation thread.
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            
            // This is the critical part for "Email Threading"
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
