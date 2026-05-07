<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GoogleRegistrationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your TAS Service Center account has been approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.google-registration-approved',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
