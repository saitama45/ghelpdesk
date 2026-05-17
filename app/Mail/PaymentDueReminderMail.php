<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentDueReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $payableType, // renewal | invoice
        public array $payableData,
        public string $reminderType, // 30d | 7d | 1d | due | overdue
        public ?string $vendorName = null
    ) {}

    public function envelope(): Envelope
    {
        $window = match ($this->reminderType) {
            '30d' => 'due in 30 days',
            '7d' => 'due in 7 days',
            '1d' => 'due tomorrow',
            'due' => 'due TODAY',
            'overdue' => 'OVERDUE',
            default => 'reminder',
        };
        $title = $this->payableData['title'] ?? 'Payment';
        $amount = $this->payableData['amount'] ?? '';
        $vendor = $this->vendorName ?? '';
        $subject = "[Payment Reminder] {$vendor} — {$title} — {$window}" . ($amount ? " — ₱{$amount}" : '');

        $envelope = new Envelope(subject: $subject);
        $envelope->using(function ($message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        });
        return $envelope;
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payments.due-reminder');
    }

    public function attachments(): array
    {
        return [];
    }
}
