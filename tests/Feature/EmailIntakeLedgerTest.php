<?php

namespace Tests\Feature;

use App\Mail\ClosedTicketReplyNotification;
use App\Mail\DepartmentAddressDirectory;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmailIntakeLog;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketKeyAlias;
use App\Models\User;
use App\Services\EmailTicketService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// FakeEmailMessage / FakeEmailHeaders live with the threading tests; reuse them
// rather than maintaining a second mail double.
require_once __DIR__ . '/EmailTicketThreadingTest.php';

/**
 * Covers the intake ledger and the catch-up pass — the fetcher's own memory of
 * which messages it has decided about, independent of the mailbox \Seen flag.
 */
class EmailIntakeLedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerTestableEmailTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSettingCache();

        Company::create(['name' => 'The Group Inc', 'code' => 'TGI', 'is_active' => true]);
        Setting::set('imap_username', 'support@example.test', 'email');

        // Setting keeps a process-wide static cache, so the catch-up throttle would
        // otherwise leak between tests. 0 = run the pass on every call.
        Setting::set('email_fetch_catchup_interval_seconds', '0', 'system');
        Setting::set('last_email_catchup_at', now()->subDay()->toDateTimeString(), 'system');

        $this->service = new LedgerTestableEmailTicketService();
    }

    protected function tearDown(): void
    {
        // Setting caches values in a process-wide static, so anything this class
        // writes (mail_require_department_address, the catch-up watermark) would
        // otherwise survive RefreshDatabase and reach later test classes.
        $this->resetSettingCache();

        parent::tearDown();
    }

    private function resetSettingCache(): void
    {
        $cache = new \ReflectionProperty(Setting::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    public function test_a_created_ticket_is_recorded_in_the_intake_ledger(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<new-request@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)',
            body: 'Seeking your assistance to change the item code and uom for this item in DAVID System.',
        ));

        $ticket = Ticket::firstOrFail();
        $log = EmailIntakeLog::firstOrFail();

        $this->assertSame('new-request@example.test', $log->message_key);
        $this->assertSame(EmailIntakeLog::OUTCOME_CREATED, $log->outcome);
        $this->assertSame($ticket->id, $log->ticket_id);
        $this->assertFalse($log->is_recovered);
        $this->assertSame('DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)', $log->subject);
    }

    public function test_a_skipped_message_is_recorded_instead_of_vanishing(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<stranger@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Not for us',
            body: 'This message was addressed to a completely unrelated mailbox.',
            toRecipients: ['someone-else@example.test'],
        ));

        $this->assertSame(0, Ticket::count());
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'stranger@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_NOT_ADDRESSED_TO_US,
        ]);
    }

    public function test_catch_up_keeps_only_messages_the_ledger_has_no_decision_for(): void
    {
        // Already decided: skipped as a stranger on an earlier pass.
        EmailIntakeLog::create([
            'message_key' => 'settled@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_NOT_ADDRESSED_TO_US,
            'processed_at' => now(),
        ]);

        // Attempted before but failed — an error is not a decision, so it must be retried.
        EmailIntakeLog::create([
            'message_key' => 'failed@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_ERROR,
            'error' => 'SQLSTATE deadlock',
            'processed_at' => now(),
        ]);

        // Already a ticket, but from before this table existed — no ledger row.
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<pre-ledger-ticket@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Existing request',
            body: 'The card terminal at the branch stopped printing receipts this morning.',
        ));
        EmailIntakeLog::where('message_key', 'pre-ledger-ticket@example.test')->delete();

        $pending = $this->service->unaccountedFor([
            new FakeEmailMessage(messageId: '<settled@example.test>', senderEmail: 'customer@example.test', subject: 'a', body: 'b'),
            new FakeEmailMessage(messageId: '<failed@example.test>', senderEmail: 'customer@example.test', subject: 'a', body: 'b'),
            new FakeEmailMessage(messageId: '<pre-ledger-ticket@example.test>', senderEmail: 'customer@example.test', subject: 'a', body: 'b'),
            new FakeEmailMessage(messageId: '<never-seen@example.test>', senderEmail: 'customer@example.test', subject: 'a', body: 'b'),
        ]);

        $keys = $pending->map(fn ($message) => trim($message->getMessageId(), '<>'))->all();

        $this->assertEqualsCanonicalizing(['failed@example.test', 'never-seen@example.test'], $keys);

        // Recognised from headers alone, so it stops costing a lookup every pass —
        // and is never ingested twice.
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'pre-ledger-ticket@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_DUPLICATE,
        ]);
        $this->assertSame(1, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
    }

    public function test_catch_up_ingestion_does_not_send_the_closed_ticket_notice(): void
    {
        Mail::fake();

        $body = 'The signage at the mall entrance is still switched off after the power interruption.';

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Signage is off',
            body: $body,
        ));

        $ticket = Ticket::firstOrFail();
        $ticket->forceFill(['status' => 'closed'])->saveQuietly();

        $reply = new FakeEmailMessage(
            messageId: '<late-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Signage is off',
            body: 'Following up on this because the signage is still off today.',
            references: ['<root@example.test>'],
        );

        // Real time: the requester is told the ticket is closed.
        $this->service->handleFake($reply, false);
        Mail::assertSent(ClosedTicketReplyNotification::class);

        Mail::fake();

        $lateReply = new FakeEmailMessage(
            messageId: '<later-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Signage is off',
            body: 'Adding a photo of the signage taken this afternoon for reference.',
            references: ['<root@example.test>'],
        );

        // Catch-up: days late, so the courtesy reply is suppressed — but the
        // decision is still on record.
        $this->service->handleFake($lateReply, true);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'later-reply@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_CLOSED_TICKET,
            'is_recovered' => true,
        ]);
    }

    public function test_catch_up_ingestion_does_not_send_the_department_directory(): void
    {
        Mail::fake();

        Department::create(['name' => 'Supply Chain', 'mail_address' => 'scm@example.test', 'is_active' => true]);
        Setting::set('mail_require_department_address', '1', 'email');

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<misaddressed@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'New request on the shared mailbox',
            body: 'Requesting assistance with the new item codes for the warehouse transfer.',
        ), true);

        Mail::assertNotSent(DepartmentAddressDirectory::class);
        $this->assertSame(0, Ticket::count());
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'misaddressed@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_DEPARTMENT_DIRECTORY,
            'is_recovered' => true,
        ]);
    }

    public function test_first_catch_up_run_sets_a_watermark_and_back_fills_nothing(): void
    {
        $inbox = new FakeInbox([
            CatchUpFakeMessage::make(1, '<old-newsletter@example.test>', now()->subDays(2)->toDateTimeString(), 'Old newsletter', 'A newsletter nobody wanted a ticket for, read and left alone.'),
        ]);

        $this->assertSame(0, $this->service->catchUp($inbox));
        $this->assertSame(0, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
        $this->assertNotNull(Setting::get('email_catchup_started_at'));
    }

    public function test_catch_up_ingests_mail_that_arrived_after_the_watermark_only(): void
    {
        Setting::set('email_catchup_started_at', now()->subHour()->toDateTimeString(), 'system');

        $inbox = new FakeInbox([
            CatchUpFakeMessage::make(1, '<before@example.test>', now()->subDays(2)->toDateTimeString(), 'Old newsletter', 'Read and ignored long before the catch-up pass existed.'),
            CatchUpFakeMessage::make(2, '<after@example.test>', now()->subMinutes(5)->toDateTimeString(), 'CHANGE UOM request', 'Seeking your assistance to change the item code and uom for this item.'),
        ]);

        $this->assertSame(1, $this->service->catchUp($inbox));

        $titles = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->pluck('title')->all();

        $this->assertSame(['CHANGE UOM request'], $titles);
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'after@example.test',
            'outcome' => EmailIntakeLog::OUTCOME_CREATED,
            'is_recovered' => true,
        ]);
        $this->assertDatabaseMissing('email_intake_logs', ['message_key' => 'before@example.test']);
    }

    /**
     * The 2026-07-29 incident: a new request raised by replying to an unrelated old
     * thread was filed as a comment on that ticket and never appeared as a ticket.
     */
    public function test_reply_with_an_unrelated_subject_opens_a_new_ticket_instead_of_hijacking_the_thread(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<item-code-thread@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'DAVID – ORDERING – CHANGE ITEM CODE/ITEM NAME 620001',
            body: 'Seeking your assistance to change the item code and item name for this item in DAVID System.',
        ));

        $original = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->firstOrFail();

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<change-uom@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)',
            body: 'Seeking your assistance to change the item code and uom for this item in DAVID System starting August 3, 2026 delivery.',
            references: ['<item-code-thread@mail.gmail.com>'],
            inReplyTo: ['<item-code-thread@mail.gmail.com>'],
        ));

        $tickets = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->pluck('title')->all();

        $this->assertCount(2, $tickets);
        $this->assertContains('DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)', $tickets);
        $this->assertSame(0, $original->comments()->count());
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'change-uom@mail.gmail.com',
            'outcome' => EmailIntakeLog::OUTCOME_CREATED,
        ]);
    }

    public function test_a_genuine_reply_whose_subject_was_lightly_edited_still_threads(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<uom-root@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)',
            body: 'Seeking your assistance to change the item code and uom for this item in DAVID System.',
        ));

        $ticket = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->firstOrFail();

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<uom-followup@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'RE: DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP) - URGENT',
            body: 'Following up because the delivery schedule moved earlier than planned.',
            references: ['<uom-root@mail.gmail.com>'],
        ));

        $this->assertSame(1, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'message_id' => 'uom-followup@mail.gmail.com',
        ]);
    }

    public function test_follow_up_in_the_hijacked_thread_lands_on_the_new_ticket(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<old-topic@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'DAVID – ORDERING – CHANGE ITEM CODE/ITEM NAME 620001',
            body: 'Seeking your assistance to change the item code and item name for this item.',
        ));

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<new-topic@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)',
            body: 'Seeking your assistance to change the uom for this item starting August 3, 2026 delivery.',
            references: ['<old-topic@mail.gmail.com>'],
        ));

        $newTicket = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('message_id', 'new-topic@mail.gmail.com')
            ->firstOrFail();

        // The chain now references BOTH threads; the on-topic ticket must win.
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<new-topic-reply@mail.gmail.com>',
            senderEmail: 'leonel.azur@example.test',
            subject: 'Re: DAVID – ORDERING – CHANGE UOM (MAPLE SYRUP)',
            body: 'Adding the supplier confirmation for the new pack size.',
            references: ['<old-topic@mail.gmail.com>', '<new-topic@mail.gmail.com>'],
        ));

        $this->assertSame(2, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $newTicket->id,
            'message_id' => 'new-topic-reply@mail.gmail.com',
        ]);
    }

    public function test_a_reply_threads_onto_a_ticket_owned_by_another_entity(): void
    {
        $brand = Company::create(['name' => 'Brand Two', 'code' => 'BR2', 'is_active' => true]);

        $body = 'The stockroom air conditioning unit is leaking water onto the shelves.';

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<entity-root@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Aircon leak',
            body: $body,
        ));

        $ticket = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->firstOrFail();

        // The fetcher also runs inside a web request (the Dashboard posts
        // tickets/sync), so an active entity is in play — here one that does not
        // own the ticket above.
        $staff = User::factory()->create(['company_id' => $brand->id]);
        $this->actingAs($staff);
        session([CompanyContext::SESSION_KEY => $brand->id]);
        CompanyContext::flushMemo();

        $this->assertSame($brand->id, CompanyContext::activeCompanyId());

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<entity-reply@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Re: Aircon leak',
            body: 'It is still leaking this morning, please send someone today.',
            references: ['<entity-root@example.test>'],
        ));

        $this->assertSame(1, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'message_id' => 'entity-reply@example.test',
        ]);
    }

    public function test_email_tickets_never_reuse_a_ticket_number_retired_by_a_renumber(): void
    {
        $ticket = Ticket::create([
            'title' => 'Existing ticket',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
        ]);

        // A renumber left TGI-9 retired: it must never be handed to another ticket,
        // or old mail carrying that number would thread onto the wrong conversation.
        TicketKeyAlias::create(['ticket_key' => 'TGI-9', 'ticket_id' => $ticket->id]);

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<after-renumber@example.test>',
            senderEmail: 'customer@example.test',
            subject: 'Brand new request',
            body: 'Please replace the receipt printer at the counter as it no longer feeds paper.',
        ));

        $created = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('message_id', 'after-renumber@example.test')
            ->firstOrFail();

        $this->assertSame('TGI-10', $created->ticket_key);
    }
}

class LedgerTestableEmailTicketService extends EmailTicketService
{
    public array $errors = [];

    public function handleFake($message, bool $recovery = false): bool
    {
        return $this->handleFetchedMessage($message, $recovery, $this->errors);
    }

    public function unaccountedFor(array $headers): \Illuminate\Support\Collection
    {
        return $this->unaccountedCatchUpMessages($headers);
    }

    public function catchUp($inbox): int
    {
        return $this->processCatchUpMessages($inbox, $this->errors);
    }
}

/** Message double carrying the UID and date the catch-up pass reads. */
class CatchUpFakeMessage extends FakeEmailMessage
{
    public int $uid = 0;

    private ?string $sentAt = null;

    public static function make(int $uid, string $messageId, string $sentAt, string $subject, string $body): self
    {
        $message = new self(
            messageId: $messageId,
            senderEmail: 'customer@example.test',
            subject: $subject,
            body: $body,
        );

        $message->uid = $uid;
        $message->sentAt = $sentAt;

        return $message;
    }

    public function getDate(): ?string
    {
        return $this->sentAt;
    }
}

/** Minimal stand-in for a Webklex folder + query, for the catch-up pass. */
class FakeInbox
{
    public function __construct(private array $messages) {}

    public function messages(): FakeInboxQuery
    {
        return new FakeInboxQuery($this->messages);
    }
}

class FakeInboxQuery
{
    public function __construct(private array $messages) {}

    public function whereSince($date): static
    {
        return $this;
    }

    public function unseen(): static
    {
        return $this;
    }

    public function setFetchBody(bool $fetchBody): static
    {
        return $this;
    }

    public function leaveUnread(): static
    {
        return $this;
    }

    public function get(): \Illuminate\Support\Collection
    {
        return collect($this->messages);
    }

    public function hasErrors(): bool
    {
        return false;
    }

    public function getErrors(): array
    {
        return [];
    }

    public function getMessageByUid(int $uid)
    {
        return collect($this->messages)->first(fn ($message) => $message->uid === $uid);
    }
}
