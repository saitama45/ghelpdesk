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
 * The public account-deletion page's verification code. Like [OtpCodeMail], the
 * plaintext code lives only in this outbound message — the database holds
 * its hash.
 */
class AccountDeletionCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $validForMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Same sender identity as OtpCodeMail — the loyalty app, not the
            // helpdesk's "TAS Service Center".
            from: new Address(
                config('mail.from.address'),
                'The Coffee Bean & Tea Leaf',
            ),
            subject: "Your account deletion code is {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-deletion-code',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
