<?php

namespace App\Mail;

use App\Models\SapRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SapRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SapRequest $sapRequest,
        public string $action = 'created',
        public bool $isRequester = false
    ) {}

    public function envelope(): Envelope
    {
        if ($this->isRequester) {
            $subject = 'Confirmation: Your SAP Request has been received';
        } else {
            $subject = $this->action === 'created' ? 'New SAP Request Submitted' : 'SAP Request Updated';
        }

        return new Envelope(
            subject: "[SAP Request #{$this->sapRequest->id}] {$subject}: {$this->sapRequest->requestType->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sap-requests.notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
