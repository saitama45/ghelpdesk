<?php

namespace Tests\Feature;

use App\Mail\NewTicketCreated;
use App\Mail\TicketCommentAdded;
use App\Models\Company;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EmailTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
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

        // Subject stays on-topic on purpose: this test is about resolving a
        // reference to a COMMENT's Message-ID. An unrelated subject is a different
        // case entirely — see
        // test_reply_with_an_unrelated_subject_opens_a_new_ticket_instead_of_hijacking_the_thread.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<third@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Back office concern - one more detail',
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

    public function test_child_resolves_registered_parent_requester_without_changing_child_reporter(): void
    {
        $requester = User::factory()->create(['email' => 'requester@example.test']);
        $creator = User::factory()->create(['email' => 'creator@example.test']);
        $company = Company::firstOrFail();
        $parent = Ticket::create([
            'ticket_key' => 'TBG-100',
            'title' => 'Parent concern',
            'status' => 'open',
            'reporter_id' => $requester->id,
            'company_id' => $company->id,
        ]);
        $child = Ticket::create([
            'ticket_key' => 'TBG-101',
            'title' => 'Child concern',
            'status' => 'open',
            'reporter_id' => $creator->id,
            'parent_id' => $parent->id,
            'company_id' => $company->id,
        ]);

        $this->assertSame($creator->id, $child->reporter_id);
        $this->assertSame('requester@example.test', $child->effectiveRequesterRecipient()['email']);
    }

    public function test_vendor_reply_on_child_is_forwarded_to_parent_requester_and_child_staff(): void
    {
        [$child, $creator, $vendor] = $this->makeVendorChild();
        Mail::fake();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<vendor-reply@example.test>',
            senderEmail: $vendor->email,
            senderName: $vendor->name,
            subject: "Re: [{$child->ticket_key}] {$child->title}",
            body: 'The replacement equipment will arrive tomorrow.',
            references: [$child->source_message_id],
        ));

        Mail::assertSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo('customer@example.test'));
        Mail::assertSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo($creator->email));
        Mail::assertNotSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo($vendor->email));
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $child->id,
            'sender_email' => $vendor->email,
        ]);
    }

    public function test_child_reply_is_not_forwarded_to_requester_who_was_already_copied_directly(): void
    {
        [$child, $creator, $vendor] = $this->makeVendorChild();
        Mail::fake();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<vendor-reply-all@example.test>',
            senderEmail: $vendor->email,
            senderName: $vendor->name,
            subject: "Re: [{$child->ticket_key}] {$child->title}",
            body: 'Reply-all update for everyone already on the thread.',
            references: [$child->source_message_id],
            ccRecipients: ['customer@example.test'],
        ));

        Mail::assertNotSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo('customer@example.test'));
        Mail::assertSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo($creator->email));
    }

    public function test_parent_requester_reply_on_child_is_forwarded_to_vendor(): void
    {
        [$child, $creator, $vendor] = $this->makeVendorChild();
        Mail::fake();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<requester-child-reply@example.test>',
            senderEmail: 'customer@example.test',
            senderName: 'Original Customer',
            subject: "Re: [{$child->ticket_key}] {$child->title}",
            body: 'Please coordinate the delivery schedule directly with our branch.',
            references: [$child->source_message_id],
        ));

        Mail::assertSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo($vendor->email));
        Mail::assertSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo($creator->email));
        Mail::assertNotSent(TicketCommentAdded::class, fn ($mail) => $mail->hasTo('customer@example.test'));
    }

    // ---------------------------------------------------------------------
    // Department mail routing — departmental addresses aliased into the one
    // support mailbox. The app matches whole addresses, so it does not care
    // whether the department name leads (scm@domain, needing a real alias) or
    // trails (support+scm@domain, riding the mailbox as a sub-address).
    // ---------------------------------------------------------------------

    public function test_mail_to_a_department_address_routes_the_ticket_to_that_department(): void
    {
        $department = $this->makeDepartment('SCM', 'scm@example.test');

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<scm-request@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Purchase order not reflecting in the system',
            body: 'The purchase order we submitted last week is still not visible in the portal.',
            toRecipients: ['scm@example.test'],
        ));

        $this->assertSame($department->id, Ticket::firstOrFail()->serving_department_id);
    }

    public function test_mail_to_the_base_support_address_stays_in_the_shared_intake_pool(): void
    {
        // Enforcement is off by default, so the shared mailbox still accepts new
        // requests and pools them for manual assignment.
        $this->makeDepartment('SCM', 'scm@example.test');

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<general-request@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'General question about our account',
            body: 'We would like to understand how to request additional equipment.',
            toRecipients: ['support@example.test'],
        ));

        $this->assertNull(Ticket::firstOrFail()->serving_department_id);
    }

    public function test_a_sub_addressed_department_address_routes_the_same_way(): void
    {
        // The other valid shape: department name AFTER the "+", delivered as a
        // sub-address of the existing mailbox instead of needing its own alias.
        $department = $this->makeDepartment('Facilities', 'support+fm@example.test');

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<subaddressed@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Broken door handle at the stockroom',
            body: 'The stockroom door handle has come loose and no longer latches shut.',
            toRecipients: ['support+fm@example.test'],
        ));

        $this->assertSame($department->id, Ticket::firstOrFail()->serving_department_id);
    }

    public function test_mail_to_an_unknown_address_is_skipped_and_marked_seen(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');

        $message = new FakeEmailMessage(
            messageId: '<stranger@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Unrelated mail',
            body: 'This message was never addressed to the helpdesk at all.',
            toRecipients: ['nosuchdesk@example.test'],
        );

        $processed = $this->service->processFake($message);

        $this->assertFalse($processed);
        $this->assertTrue($message->seen);
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_department_address_wins_over_the_base_address_on_the_same_message(): void
    {
        $department = $this->makeDepartment('Facilities', 'fm@example.test');

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<both-addresses@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Aircon leaking in the stockroom',
            body: 'Water is dripping from the aircon unit above the stockroom shelves.',
            toRecipients: ['fm@example.test'],
            ccRecipients: ['support@example.test'],
        ));

        $this->assertSame($department->id, Ticket::firstOrFail()->serving_department_id);
    }

    public function test_a_reply_adopts_a_department_only_when_the_ticket_has_none(): void
    {
        $scm = $this->makeDepartment('SCM', 'scm@example.test');
        $facilities = $this->makeDepartment('Facilities', 'fm@example.test');
        $body = 'The delivery van has not arrived at the branch as scheduled today.';

        // An unrouted ticket, as the web form / POS / kiosk paths still produce:
        // raised without a serving department, sitting in the shared intake pool.
        $ticket = Ticket::create([
            'ticket_key' => 'TBG-500',
            'title' => 'Delivery delay',
            'status' => 'open',
            'sender_email' => 'customer@example.test',
            'company_id' => Company::firstOrFail()->id,
        ]);
        $this->assertNull($ticket->serving_department_id);

        // A reply on SCM's address claims it (matched by ticket key in the subject).
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<claimed@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: [TBG-500] Delivery delay',
            body: $body,
            toRecipients: ['scm@example.test'],
        ));

        $this->assertSame($scm->id, $ticket->fresh()->serving_department_id);

        // A later reply on another desk's address must NOT hand the ticket away.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<hijack@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: [TBG-500] Delivery delay',
            body: 'Following up again on the delayed delivery to our branch this week.',
            toRecipients: ['fm@example.test'],
        ));

        $this->assertSame($scm->id, $ticket->fresh()->serving_department_id);
        $this->assertNotSame($facilities->id, $ticket->fresh()->serving_department_id);
    }

    public function test_departmental_addresses_are_never_auto_added_as_ticket_ccs(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<cc-loop@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Stock discrepancy at the branch',
            body: 'The stock count in the system does not match what is physically on the shelf.',
            toRecipients: ['scm@example.test'],
            ccRecipients: ['support@example.test', 'colleague@example.test'],
        ));

        $ccs = Ticket::firstOrFail()->ccs()->pluck('email')->all();

        // Our own addresses would mail the inbox back on every notification.
        $this->assertNotContains('scm@example.test', $ccs);
        $this->assertNotContains('support@example.test', $ccs);
        $this->assertContains('colleague@example.test', $ccs);
    }

    public function test_outbound_mail_uses_the_serving_departments_identity(): void
    {
        $department = $this->makeDepartment('SCM', 'scm@example.test', fromName: 'SCM Service Desk');
        Setting::set('mail_from_name', 'TAS Service Center', 'mail');
        config(['mail.from.address' => 'noreply@example.test']);

        $ticket = Ticket::create([
            'ticket_key' => 'TBG-900',
            'title' => 'Outbound identity check',
            'status' => 'open',
            'serving_department_id' => $department->id,
            'company_id' => Company::firstOrFail()->id,
        ]);

        // Sent for real through the array transport (phpunit.xml sets MAIL_MAILER=array)
        // rather than Mail::fake(), because the From/Reply-To under test are applied
        // when the Symfony message is built — which a fake never does.
        Mail::to('customer@example.test')->send(new NewTicketCreated($ticket, 'Customer'));

        $sent = $this->lastSentMessage();

        // Address stays global (DKIM alignment); only the display name is the desk's.
        $this->assertSame('noreply@example.test', $sent->getFrom()[0]->getAddress());
        $this->assertSame('SCM Service Desk', $sent->getFrom()[0]->getName());

        // Exactly one From and one Reply-To — appending instead of replacing would
        // produce an invalid message and split where replies land.
        $this->assertCount(1, $sent->getFrom());
        $this->assertCount(1, $sent->getReplyTo());
        $this->assertSame('scm@example.test', $sent->getReplyTo()[0]->getAddress());
    }

    public function test_outbound_mail_for_an_unrouted_ticket_uses_the_global_identity(): void
    {
        Setting::set('mail_from_name', 'TAS Service Center', 'mail');
        config(['mail.from.address' => 'noreply@example.test']);

        $ticket = Ticket::create([
            'ticket_key' => 'TBG-901',
            'title' => 'Unrouted identity check',
            'status' => 'open',
            'company_id' => Company::firstOrFail()->id,
        ]);

        Mail::to('customer@example.test')->send(new NewTicketCreated($ticket, 'Customer'));

        $sent = $this->lastSentMessage();

        $this->assertSame('TAS Service Center', $sent->getFrom()[0]->getName());
        $this->assertSame('support@example.test', $sent->getReplyTo()[0]->getAddress());
    }

    private function lastSentMessage(): \Symfony\Component\Mime\Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'No message was sent through the array transport.');

        return $messages[count($messages) - 1]->getOriginalMessage();
    }

    public function test_a_department_without_an_address_falls_back_to_the_global_identity(): void
    {
        $department = $this->makeDepartment('Legal', null);
        Setting::set('mail_from_name', 'TAS Service Center', 'mail');

        $router = app(\App\Services\DepartmentMailRouter::class);

        $this->assertNull($router->addressFor($department->id));
        $this->assertSame('support@example.test', $router->replyToFor($department->id));
        $this->assertSame('TAS Service Center', $router->fromNameFor($department->id));
    }

    // ---------------------------------------------------------------------
    // Requiring a departmental address for NEW requests
    // ---------------------------------------------------------------------

    public function test_new_request_on_the_shared_mailbox_is_rejected_with_a_directory_reply(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');
        $this->makeDepartment('Facilities', 'fm@example.test');
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        $message = new FakeEmailMessage(
            messageId: '<misaddressed@example.test>',
            senderEmail: 'customer@example.test',
            senderName: 'Store Manager',
            subject: 'Aircon not working',
            body: 'The aircon in our branch stockroom has stopped cooling since this morning.',
            toRecipients: ['support@example.test'],
        );

        $processed = $this->service->processFake($message);

        $this->assertFalse($processed);
        $this->assertTrue($message->seen);
        $this->assertSame(0, Ticket::count(), 'No ticket may be raised for a rejected message.');

        Mail::assertSent(\App\Mail\DepartmentAddressDirectory::class, function ($mail) {
            return $mail->hasTo('customer@example.test')
                && $mail->departments === ['Facilities' => 'fm@example.test', 'SCM' => 'scm@example.test']
                && $mail->sharedAddress === 'support@example.test';
        });
    }

    public function test_a_reply_to_an_existing_ticket_on_the_shared_mailbox_still_threads(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');
        $body = 'The delivery for our branch has still not arrived as scheduled.';

        // Raised properly on SCM's address before enforcement is switched on.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<properly-addressed@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Delivery delay',
            body: $body,
            toRecipients: ['scm@example.test'],
        ));

        $ticket = Ticket::firstOrFail();
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        // The requester hits Reply, which goes to the shared address.
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<reply-to-shared@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Delivery delay',
            body: $body,
            toRecipients: ['support@example.test'],
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'message_id' => 'reply-to-shared@example.test',
        ]);
        Mail::assertNotSent(\App\Mail\DepartmentAddressDirectory::class);
    }

    public function test_enforcement_is_ignored_when_no_department_has_an_address(): void
    {
        // The safety interlock: enforcing with an empty directory would reject
        // every message and point the sender at nothing, so the setting alone is
        // not enough to switch it on.
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<no-directory@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Printer jam at the counter',
            body: 'The receipt printer keeps jamming whenever we print a long order.',
            toRecipients: ['support@example.test'],
        ));

        $this->assertSame(1, Ticket::count());
        Mail::assertNotSent(\App\Mail\DepartmentAddressDirectory::class);
    }

    public function test_directory_reply_is_sent_once_per_sender_per_day(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        foreach (['<first@example.test>', '<second@example.test>'] as $id) {
            $this->service->processFake(new FakeEmailMessage(
                messageId: $id,
                senderEmail: 'customer@example.test',
                subject: 'Another misaddressed request ' . $id,
                body: 'This is a distinct message body for ' . $id . ' so it cannot match by hash.',
                toRecipients: ['support@example.test'],
            ));
        }

        // Second one is throttled: an auto-responder on the far end would otherwise
        // keep this exchange going indefinitely.
        Mail::assertSentCount(1);
        $this->assertSame(0, Ticket::count());
    }

    public function test_enforcement_off_still_pools_shared_mailbox_requests(): void
    {
        $this->makeDepartment('SCM', 'scm@example.test');
        Setting::set('mail_require_department_address', '0', 'mail');
        Mail::fake();

        $this->service->processFake(new FakeEmailMessage(
            messageId: '<pooled@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'General question about equipment',
            body: 'We would like to know the process for requesting additional equipment.',
            toRecipients: ['support@example.test'],
        ));

        $this->assertSame(1, Ticket::count());
        $this->assertNull(Ticket::firstOrFail()->serving_department_id);
        Mail::assertNotSent(\App\Mail\DepartmentAddressDirectory::class);
    }

    public function test_a_department_owning_the_shared_mailbox_still_gets_tickets_while_enforcement_is_on(): void
    {
        // The shared mailbox is the address requesters actually know. When a desk
        // claims it, mail sent there must become that desk's ticket rather than be
        // answered with the directory notice — otherwise switching enforcement on
        // silently stops email intake (2026-08-26: 59 real requests turned away).
        $owner = $this->makeDepartment('Technology and Solutions', 'support@example.test');
        $this->makeDepartment('SCM', 'scm@example.test');
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        $processed = $this->service->processFake(new FakeEmailMessage(
            messageId: '<claimed-shared-mailbox@example.test>',
            senderEmail: 'customer@example.test',
            senderName: 'Store Manager',
            subject: 'Cannot perform Zread',
            body: 'The terminal will not let us run the Zread at closing time.',
            toRecipients: ['support@example.test'],
        ));

        $this->assertTrue($processed);
        $this->assertSame(1, Ticket::count());
        $this->assertSame($owner->id, Ticket::firstOrFail()->serving_department_id);
        Mail::assertNotSent(\App\Mail\DepartmentAddressDirectory::class);
    }

    public function test_claiming_the_shared_mailbox_does_not_make_us_accept_mail_addressed_elsewhere(): void
    {
        // Claiming the shared mailbox removes the "wrong address" outcome for mail
        // sent to it, but must not widen what counts as ours: a message addressed
        // to nobody we own is still ignored, ticket or notice alike.
        $this->makeDepartment('Technology and Solutions', 'support@example.test');
        $this->makeDepartment('SCM', 'scm@example.test');
        Setting::set('mail_require_department_address', '1', 'mail');
        Mail::fake();

        $processed = $this->service->processFake(new FakeEmailMessage(
            messageId: '<not-ours@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Aircon not working',
            body: 'The aircon in our branch stockroom has stopped cooling since this morning.',
            toRecipients: ['someone-else@example.test'],
        ));

        $this->assertFalse($processed);
        $this->assertSame(0, Ticket::count());
        Mail::assertNotSent(\App\Mail\DepartmentAddressDirectory::class);
    }

    private function makeDepartment(string $name, ?string $address, ?string $fromName = null): \App\Models\Department
    {
        return \App\Models\Department::create([
            'name' => $name,
            'code' => strtoupper(substr(str_replace(' ', '', $name), 0, 4)),
            'mail_address' => $address,
            'mail_from_name' => $fromName,
            'is_active' => true,
        ]);
    }

    private function makeVendorChild(): array
    {
        $this->service->processFake(new FakeEmailMessage(
            messageId: '<customer-root@example.test>',
            senderEmail: 'customer@example.test',
            senderName: 'Original Customer',
            subject: 'Original customer concern',
            body: 'Please repair the affected equipment at our branch.',
        ));

        $parent = Ticket::firstOrFail();
        $creator = User::factory()->create(['email' => 'child-creator@example.test']);
        $vendor = Vendor::create([
            'code' => 'VENDOR-1',
            'name' => 'External Vendor',
            'email' => 'vendor@example.test',
            'is_active' => true,
        ]);
        $child = Ticket::create([
            'ticket_key' => 'TBG-200',
            'title' => 'Vendor Escalation: Original customer concern',
            'status' => 'open',
            'reporter_id' => $creator->id,
            'vendor_id' => $vendor->id,
            'parent_id' => $parent->id,
            'company_id' => $parent->company_id,
            'message_id' => 'ticket-tbg-200@example.test',
            'source_message_id' => '<ticket-tbg-200@example.test>',
        ]);

        return [$child, $creator, $vendor];
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
