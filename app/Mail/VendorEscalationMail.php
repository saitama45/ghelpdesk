<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketMail;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * First email sent to a vendor when a child ticket is escalated to them.
 *
 * The outgoing Message-ID is pinned to a stable, child-ticket-derived id
 * (the same value stored on the child's message_id / source_message_id), so
 * the vendor's reply — whose In-Reply-To references this Message-ID — threads
 * back into the child ticket via EmailTicketService::findTicketByMessageIds().
 */
class VendorEscalationMail extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketMail;

    public Collection $ticketAttachments;

    public function __construct(
        public Ticket $ticket,
        public string $recipientName,
        public string $bodyMessage,
        public string $escalationReason,
        public string $threadMessageId,
        $ticketAttachments = null
    ) {
        $this->ticketAttachments = collect($ticketAttachments);
    }

    public function envelope(): Envelope
    {
        // First message in the vendor thread — no "Re:" prefix.
        $envelope = new Envelope(
            subject: $this->ticketThreadSubject($this->ticket, false),
        );

        $envelope->using(function ($message) {
            $headers = $message->getHeaders();

            // Pin the Message-ID so replies thread back to the child ticket.
            $bareId = trim($this->threadMessageId, " \t\n\r\0\x0B<>");
            if ($headers->has('Message-ID')) {
                $headers->remove('Message-ID');
            }
            $headers->addIdHeader('Message-ID', $bareId);

            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        });

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.vendor-escalation',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return $this->ticketAttachments
            ->filter(fn ($attachment) => $attachment->file_storage_path
                && Storage::disk('public')->exists($attachment->file_storage_path))
            ->map(fn ($attachment) => Attachment::fromPath(Storage::disk('public')->path($attachment->file_storage_path))
                ->as($attachment->file_name))
            ->values()
            ->all();
    }
}
