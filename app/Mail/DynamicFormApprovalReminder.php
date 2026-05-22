<?php

namespace App\Mail;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicFormApprovalReminder extends Mailable
{
    use Queueable, SerializesModels;

    public $formDefinition;
    public $record;
    public $approverName;
    public string $viewUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(FormDefinition $formDefinition, FormRecord $record, $approverName = null, string $viewUrl = '')
    {
        $this->formDefinition = $formDefinition;
        $this->record = $record;
        $this->approverName = $approverName;
        $this->viewUrl = $viewUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: Approval Required for ' . $this->formDefinition->name . ' #' . $this->record->id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.dynamic_forms.approval_reminder',
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

