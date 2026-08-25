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
 * The mobile app's post-login verification code.
 *
 * Carries the plaintext code only for the outbound message — nothing about
 * this class is ever persisted; `OtpController` holds the code in a local
 * variable just long enough to construct this mailable and hash it for
 * storage, then lets it go out of scope.
 */
class OtpCodeMail extends Mailable
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
            // "TAS Service Center" (the global mail.from.name, driven by the
            // `settings` table) is reserved for ticket-related mail. This is
            // the mobile loyalty app, not the helpdesk, so it gets its own
            // sender name — same underlying mailbox, distinct display name,
            // no change to how ticket notifications look.
            from: new Address(
                config('mail.from.address'),
                'Coffee Bean & Tea Leaf',
            ),
            subject: "Your verification code is {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.otp-code',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
