<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when someone opens a NEW request on the shared support address while
 * departmental addressing is required: no ticket is raised, and the sender is
 * told which address to use instead.
 *
 * Deliberately not a ticket mailable — there is no ticket. It carries the
 * auto-response suppression headers itself so a vacation responder on the far
 * end cannot bounce this back and forth with us.
 */
class DepartmentAddressDirectory extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $departments  name => inbound address
     */
    public function __construct(
        public string $recipientName,
        public string $originalSubject,
        public array $departments,
        public string $sharedAddress
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: 'Action needed: send your request to the right department',
        );

        $envelope->using(function ($message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            // No In-Reply-To/References: this must NOT thread into anything, or a
            // mail client will file it under a conversation that has no ticket.
        });

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets.department-directory',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
