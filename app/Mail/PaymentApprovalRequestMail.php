<?php

namespace App\Mail;

use App\Models\PaymentRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PaymentRecord $record,
        public string $approverName
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: "[Payment Approval] Record #{$this->record->id} — Level " . ((int) $this->record->current_approval_level + 1)
        );
        $envelope->using(function ($message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        });
        return $envelope;
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payments.approval-request');
    }

    public function attachments(): array
    {
        return [];
    }
}
