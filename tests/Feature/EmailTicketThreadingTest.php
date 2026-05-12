<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\EmailTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EmailTicketThreadingTest extends TestCase
{
    use RefreshDatabase;

    private TestableEmailTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'The Bistro Group',
            'code' => 'TBG',
            'is_active' => true,
        ]);

        Setting::set('imap_username', 'support@example.test', 'email');
        $this->service = new TestableEmailTicketService();
    }

    public function test_same_sender_and_same_body_with_modified_subject_adds_comment_to_existing_ticket(): void
    {
        $body = 'The POS terminal is showing a connection error when processing card payments.';

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<first@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'POS terminal issue',
            body: $body,
        ));

        $ticket = Ticket::firstOrFail();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<second@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Please check this today',
            body: $body,
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'message_id' => 'second@example.test',
        ]);
    }

    public function test_message_id_already_stored_on_comment_is_not_processed_again(): void
    {
        $body = 'The login screen is stuck after submitting the account credentials.';

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<ticket@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Login issue',
            body: $body,
        ));

        $reply = new FakeEmailMessage(
            messageId: '<reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Login issue',
            body: 'Please also check the browser session because the error continues.',
        );

        $this->service->processFake($reply);
        $this->assertSame(1, TicketComment::count());

        $duplicate = new FakeEmailMessage(
            messageId: '<reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Login issue',
            body: 'Please also check the browser session because the error continues.',
        );

        $processed = $this->service->processFake($duplicate);

        $this->assertFalse($processed);
        $this->assertTrue($duplicate->seen);
        $this->assertSame(1, TicketComment::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_same_body_from_different_sender_creates_new_ticket(): void
    {
        $body = 'The branch printer cannot print receipts after the latest workstation restart.';

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<sender-one@example.test>',
            senderEmail: 'first-customer@example.test',
            subject: 'Printer issue',
            body: $body,
        ));

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<sender-two@example.test>',
            senderEmail: 'second-customer@example.test',
            subject: 'Different subject',
            body: $body,
        ));

        $this->assertSame(2, Ticket::count());
        $this->assertSame(0, TicketComment::count());
    }

    public function test_short_generic_body_does_not_match_by_body_hash(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<short-one@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Original short email',
            body: 'Thanks',
        ));

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<short-two@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Different short email subject',
            body: 'Thanks',
        ));

        $this->assertSame(2, Ticket::count());
        $this->assertSame(0, TicketComment::count());
    }

    public function test_references_matching_comment_message_id_attach_to_comment_ticket(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Back office concern',
            body: 'The back office application is not loading reports after the update.',
        ));

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<comment@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Back office concern',
            body: 'The same application problem still happens after clearing cache.',
        ));

        $ticket = Ticket::firstOrFail();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<third@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Subject changed again',
            body: 'Adding another detail: this only happens on the cashier profile.',
            references: ['<comment@example.test>'],
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertSame(2, TicketComment::count());
        $this->assertTrue(
            TicketComment::where('ticket_id', $ticket->id)
                ->where('message_id', 'third@example.test')
                ->exists()
        );
    }

    public function test_reply_history_is_preserved_when_email_becomes_comment(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<root-reply-history@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Kitchen printer issue',
            body: 'The kitchen printer does not print new orders from the POS terminal.',
        ));

        $replyBody = "Please also check the network cable.\n\n"
            . "On Tue, May 12, 2026 at 10:15 AM Support <support@example.test> wrote:\n"
            . "> We received your original kitchen printer concern.\n"
            . "> Please send the branch details.";

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<reply-history@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'RE: Kitchen printer issue',
            body: $replyBody,
        ));

        $comment = TicketComment::latest('created_at')->firstOrFail();

        $this->assertStringContainsString('Please also check the network cable.', $comment->comment_text);
        $this->assertStringContainsString('On Tue, May 12, 2026 at 10:15 AM Support', $comment->comment_text);
        $this->assertStringContainsString('We received your original kitchen printer concern.', $comment->comment_text);
        $this->assertStringContainsString('Please send the branch details.', $comment->comment_text);
    }

    public function test_forwarded_history_is_preserved_when_email_creates_ticket(): void
    {
        $forwardedBody = "Kindly create a ticket for the concern below.\n\n"
            . "---------- Forwarded message ---------\n"
            . "From: Store Manager <manager@example.test>\n"
            . "Subject: POS concern\n\n"
            . "Original message details should remain visible in the ticket description.";

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<forwarded@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'FW: POS concern',
            body: $forwardedBody,
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertStringContainsString('Kindly create a ticket for the concern below.', $ticket->description);
        $this->assertStringContainsString('Forwarded message', $ticket->description);
        $this->assertStringContainsString('Original message details should remain visible', $ticket->description);
    }

    public function test_nested_re_and_fw_subject_matches_existing_ticket_for_same_sender(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<subject-root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Store router issue',
            body: 'The store router disconnects every hour and affects POS transactions.',
        ));

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<subject-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'FW: RE: Store router issue',
            body: 'Forwarding the same concern again with additional details from the branch.',
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TicketComment::count());
        $this->assertDatabaseHas('ticket_comments', [
            'message_id' => 'subject-reply@example.test',
        ]);
    }
}

class TestableEmailTicketService extends EmailTicketService
{
    public function processFake(FakeEmailMessage $message): bool
    {
        return $this->processMessage($message);
    }
}

class FakeEmailMessage
{
    public bool $seen = false;

    public function __construct(
        private string $messageId,
        private string $senderEmail,
        private string $subject,
        private string $body,
        private array $references = [],
        private array $inReplyTo = [],
        private string $senderName = 'Customer',
        private string $supportEmail = 'support@example.test',
    ) {}

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getFrom(): array
    {
        return [(object) [
            'mail' => $this->senderEmail,
            'full' => $this->senderName . ' <' . $this->senderEmail . '>',
        ]];
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getReferences(): array
    {
        return $this->references;
    }

    public function getInReplyTo(): array
    {
        return $this->inReplyTo;
    }

    public function getTo(): array
    {
        return [(object) ['mail' => $this->supportEmail]];
    }

    public function getCc(): array
    {
        return [];
    }

    public function getBcc(): array
    {
        return [];
    }

    public function getHeaders(): FakeEmailHeaders
    {
        return new FakeEmailHeaders($this->supportEmail);
    }

    public function getTextBody(): string
    {
        return $this->body;
    }

    public function getHTMLBody(): string
    {
        return '';
    }

    public function getAttachments(): Collection
    {
        return collect();
    }

    public function setFlag(string $flag): void
    {
        if ($flag === 'Seen') {
            $this->seen = true;
        }
    }
}

class FakeEmailHeaders
{
    public function __construct(private string $supportEmail) {}

    public function get(string $key): string
    {
        return in_array(strtolower($key), ['to', 'cc'], true) ? $this->supportEmail : '';
    }
}
