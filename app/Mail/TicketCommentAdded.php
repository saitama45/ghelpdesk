<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketMail;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class TicketCommentAdded extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketMail;

    public Collection $commentAttachments;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Ticket $ticket,
        public TicketComment $comment,
        public string $recipientName,
        $commentAttachments = null
    ) {
        $this->commentAttachments = collect($commentAttachments);

        if ($this->commentAttachments->isNotEmpty()) {
            $this->comment->setRelation('attachments', $this->commentAttachments);
        }
    }

    /**
     * Get the message envelope.
     */
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

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $this->syncCommentAttachments();

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
        $attachments = $this->syncCommentAttachments();

        return $attachments
            ->filter(fn ($attachment) => Storage::disk('public')->exists($attachment->file_storage_path))
            ->map(fn ($attachment) => Attachment::fromPath(Storage::disk('public')->path($attachment->file_storage_path))
                ->as($attachment->file_name))
            ->values()
            ->all();
    }

    private function syncCommentAttachments(): Collection
    {
        if ($this->commentAttachments->isEmpty()) {
            $this->comment->loadMissing('attachments');
            $this->commentAttachments = $this->comment->attachments;
        }

        $this->comment->setRelation('attachments', $this->commentAttachments);

        return $this->commentAttachments;
    }
}
