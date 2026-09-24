<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a member their verified deletion request was filed, with the ticket
 * number to quote in any follow-up — the acknowledgement the public
 * account-deletion page promises.
 */
class AccountDeletionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $ticketKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                'The Coffee Bean & Tea Leaf',
            ),
            // Replies go to the mailbox whose inbound mail becomes tickets, so
            // a follow-up quoting the key threads onto this request.
            replyTo: array_filter([
                config('imap.accounts.default.username') ?: null,
            ]),
            subject: "We received your account deletion request [{$this->ticketKey}]",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-deletion-requested',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
