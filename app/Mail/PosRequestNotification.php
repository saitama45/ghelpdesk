<?php

namespace App\Mail;

use App\Models\PosRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PosRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public PosRequest $posRequest,
        public string $action = 'created',
        public bool $isRequester = false
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        if ($this->isRequester) {
            $subject = 'Confirmation: Your POS Request has been received';
        } else {
            $subject = $this->action === 'created' ? 'New POS Request Submitted' : 'POS Request Updated';
        }
        
        return new Envelope(
            subject: "[POS Request #{$this->posRequest->id}] {$subject}: {$this->posRequest->requestType->name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.pos-requests.notification',
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
