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

    public function test_email_ccd_to_support_address_is_processed(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<cc-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Branch internet concern',
            body: 'The branch internet connection keeps disconnecting during lunch operations.',
            toRecipients: ['manager@example.test'],
            ccRecipients: [' SUPPORT@example.test '],
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertSame('Branch internet concern', $ticket->title);
        $this->assertTrue($ticket->is(Ticket::first()));
    }

    public function test_email_bccd_to_support_address_is_processed(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<bcc-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Back office internet concern',
            body: 'The back office internet connection keeps disconnecting during lunch operations.',
            toRecipients: ['manager@example.test'],
            bccRecipients: [' SUPPORT@example.test '],
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertSame('Back office internet concern', $ticket->title);
    }

    public function test_email_not_addressed_to_support_address_is_skipped_and_marked_seen(): void
    {
        $message = new FakeEmailMessage(
            messageId: '<not-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Wrong mailbox concern',
            body: 'This message landed in the inbox but was sent to a different address.',
            toRecipients: ['other@example.test'],
            ccRecipients: ['manager@example.test'],
        );

        $processed = $this->service->processFake($message);

        $this->assertFalse($processed);
        $this->assertTrue($message->seen);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketComment::count());
    }

    public function test_header_fallback_requires_exact_support_email_match(): void
    {
        $message = new FakeEmailMessage(
            messageId: '<substring-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Lookalike address concern',
            body: 'This should not become a ticket because the recipient is only a lookalike address.',
            toRecipients: ['other-support@example.test'],
            headerToRecipients: ['other-support@example.test'],
        );

        $processed = $this->service->processFake($message);

        $this->assertFalse($processed);
        $this->assertTrue($message->seen);
        $this->assertSame(0, Ticket::count());
    }

    public function test_header_fallback_processes_exact_support_email_match(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<header-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Header recipient concern',
            body: 'This should become a ticket because the raw header contains the support address.',
            toRecipients: ['undisclosed-recipients:;'],
            headerToRecipients: ['Support Desk <support@example.test>'],
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertSame('Header recipient concern', $ticket->title);
    }

    public function test_delivery_header_fallback_processes_gmail_style_to_me_delivery(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<delivered-to-support@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'SBD_DRIVE THRU POS ERROR',
            body: 'Need assistance with our DT pos. Please see the attached images.',
            toRecipients: ['me'],
            extraHeaders: [
                'delivered_to' => ['Support Desk <support@example.test>'],
            ],
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertSame('SBD_DRIVE THRU POS ERROR', $ticket->title);
    }

    public function test_delivery_header_fallback_still_requires_exact_support_email_match(): void
    {
        $message = new FakeEmailMessage(
            messageId: '<wrong-delivered-to@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Wrong delivered recipient',
            body: 'This should not become a ticket because delivery was for a different mailbox.',
            toRecipients: ['me'],
            extraHeaders: [
                'delivered_to' => ['other-support@example.test'],
                'x_original_to' => ['other-support@example.test'],
            ],
        );

        $processed = $this->service->processFake($message);

        $this->assertFalse($processed);
        $this->assertTrue($message->seen);
        $this->assertSame(0, Ticket::count());
    }

    public function test_html_reply_history_is_preserved_when_plain_text_is_shorter(): void
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<root-html-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Cashier workstation issue',
            body: 'The cashier workstation cannot open the POS application after restart.',
        ));

        $htmlBody = '<div>Please check this also.</div>'
            . '<blockquote>'
            . '<div>On Tue, May 12, 2026 at 10:15 AM Support &lt;support@example.test&gt; wrote:</div>'
            . '<div>We already asked for the workstation number.</div>'
            . '<div>Please include the error screenshot.</div>'
            . '</blockquote>';

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<html-reply-history@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'RE: Cashier workstation issue',
            body: 'Please check this also.',
            htmlBody: $htmlBody,
        ));

        $comment = TicketComment::latest('created_at')->firstOrFail();

        $this->assertStringContainsString('Please check this also.', $comment->comment_text);
        $this->assertStringContainsString('On Tue, May 12, 2026 at 10:15 AM Support', $comment->comment_text);
        $this->assertStringContainsString('We already asked for the workstation number.', $comment->comment_text);
        $this->assertStringContainsString('Please include the error screenshot.', $comment->comment_text);
    }

    public function test_html_forwarded_history_is_preserved_when_plain_text_is_shorter(): void
    {
        $htmlBody = '<div>Kindly create a ticket for the concern below.</div>'
            . '<div>---------- Forwarded message ---------</div>'
            . '<div>From: Store Manager &lt;manager@example.test&gt;</div>'
            . '<div>Subject: POS concern</div>'
            . '<blockquote><div>Original forwarded details should remain visible.</div></blockquote>';

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<html-forwarded@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'FW: POS concern',
            body: 'Kindly create a ticket for the concern below.',
            htmlBody: $htmlBody,
        ));

        $ticket = Ticket::firstOrFail();

        $this->assertStringContainsString('Kindly create a ticket for the concern below.', $ticket->description);
        $this->assertStringContainsString('Forwarded message', $ticket->description);
        $this->assertStringContainsString('Store Manager', $ticket->description);
        $this->assertStringContainsString('Original forwarded details should remain visible.', $ticket->description);
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

    public function test_reply_carrying_a_retired_ticket_key_matches_the_renumbered_ticket(): void
    {
        // Ticket is created via email under the default TBG company -> TBG-1.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<cctv-root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'REQUESTING FOR CCTV FOOTAGE',
            body: 'Please assist us with the store request for CCTV footage for the investigation.',
        ));

        $ticket = Ticket::firstOrFail();
        $this->assertSame('TBG-1', $ticket->ticket_key);

        // A staff member moves it to another company — it is renumbered and the
        // old key TBG-1 is remembered as an alias.
        $nono = Company::create(['name' => "Nono's", 'code' => 'NONO', 'is_active' => true]);
        $ticket->update(['company_id' => $nono->id]);
        $this->assertSame('NONO-1', $ticket->fresh()->ticket_key);
        $this->assertDatabaseHas('ticket_key_aliases', ['ticket_key' => 'TBG-1']);

        // The customer replies to the ORIGINAL thread, whose subject still says
        // [TBG-1], and without any threading headers. It must land on the same
        // (now renumbered) ticket, not create a new one.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<cctv-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: [TBG-1] REQUESTING FOR CCTV FOOTAGE',
            body: 'Following up on the CCTV footage request, adding the exact time window needed.',
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'message_id' => 'cctv-reply@example.test',
        ]);
    }

    public function test_closed_ticket_older_than_three_days_creates_new_ticket(): void
    {
        // 1. Create a ticket and mark it closed, with updated_at set to 4 days ago
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'UOM issue',
            body: 'Please change the uom of horseradish.',
        ));

        $ticket = Ticket::firstOrFail();
        $ticket->status = 'closed';
        $ticket->save();

        // Artificially age the ticket's updated_at timestamp to 4 days ago
        $ticket->updated_at = now()->subDays(4);
        $ticket->save();

        // 2. Receive a reply/matching email
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'UOM issue',
            body: 'Another completely unrelated uom change request.',
        ));

        // It should bypass the closed ticket and create a brand new ticket!
        $this->assertSame(2, Ticket::count());
        $this->assertSame(0, TicketComment::count());
    }

    public function test_closed_ticket_newer_than_three_days_sends_lockout_notification(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        // 1. Create a ticket and mark it closed
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<root2@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'POS issue',
            body: 'The POS terminal is broken.',
        ));

        $ticket = Ticket::firstOrFail();
        $ticket->status = 'closed';
        $ticket->save();

        // 2. Receive a reply/matching email within 3 days (e.g. today)
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<reply2@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'POS issue',
            body: 'Please check this POS issue.',
        ));

        // It should trigger the lockout notification and not create any comments/new tickets
        $this->assertSame(1, Ticket::count());
        $this->assertSame(0, TicketComment::count());

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\ClosedTicketReplyNotification::class);
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
        private string $htmlBody = '',
        private array $toRecipients = [],
        private array $ccRecipients = [],
        private array $bccRecipients = [],
        private ?array $headerToRecipients = null,
        private ?array $headerCcRecipients = null,
        private ?array $headerBccRecipients = null,
        private array $extraHeaders = [],
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
        $recipients = $this->toRecipients ?: [$this->supportEmail];

        return array_map(fn ($email) => (object) ['mail' => $email], $recipients);
    }

    public function getCc(): array
    {
        return array_map(fn ($email) => (object) ['mail' => $email], $this->ccRecipients);
    }

    public function getBcc(): array
    {
        return array_map(fn ($email) => (object) ['mail' => $email], $this->bccRecipients);
    }

    public function getHeaders(): FakeEmailHeaders
    {
        return $this->buildHeaders();
    }

    public function getHeader(): FakeEmailHeaders
    {
        return $this->buildHeaders();
    }

    private function buildHeaders(): FakeEmailHeaders
    {
        return new FakeEmailHeaders(
            $this->headerToRecipients ?? ($this->toRecipients ?: [$this->supportEmail]),
            $this->headerCcRecipients ?? $this->ccRecipients,
            $this->headerBccRecipients ?? $this->bccRecipients,
            $this->extraHeaders,
        );
    }

    public function getTextBody(): string
    {
        return $this->body;
    }

    public function getHTMLBody(): string
    {
        return $this->htmlBody;
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
    public function __construct(
        private array $toRecipients,
        private array $ccRecipients,
        private array $bccRecipients = [],
        private array $extraHeaders = [],
    ) {}

    public function get(string $key): string
    {
        $normalizedKey = strtolower(str_replace(['-', ' '], '_', $key));

        if (array_key_exists($normalizedKey, $this->extraHeaders)) {
            return implode(', ', $this->extraHeaders[$normalizedKey]);
        }

        return match ($normalizedKey) {
            'to' => implode(', ', $this->toRecipients),
            'cc' => implode(', ', $this->ccRecipients),
            'bcc' => implode(', ', $this->bccRecipients),
            default => '',
        };
    }
}
