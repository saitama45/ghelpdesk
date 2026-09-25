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
 * Tells a member their account has ALREADY been closed, with the ticket number
 * to quote if it was not them.
 *
 * Deliberately not `AccountDeletionRequestedMail`: that one acknowledges a
 * request the desk has yet to act on and offers to cancel it while the account
 * is still open. An in-app deletion has already happened by the time this is
 * sent, so promising a cancellation window would be untrue.
 */
class AccountClosedMail extends Mailable
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
            // a follow-up quoting the key threads onto this record.
            replyTo: array_filter([
                config('imap.accounts.default.username') ?: null,
            ]),
            subject: "Your account has been closed [{$this->ticketKey}]",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-closed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
