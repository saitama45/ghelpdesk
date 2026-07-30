<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailIntakeLog;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Services\EmailTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__ . '/EmailTicketThreadingTest.php';

/**
 * Bounces (delivery-status reports) are the only way the helpdesk learns that a CC
 * address is dead. Before this they were dropped at the banned-sender gate, so a
 * bad address was re-mailed on every ticket update forever and nobody was told.
 */
class EmailBounceHandlingTest extends TestCase
{
    use RefreshDatabase;

    private BounceTestableEmailTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSettingCache();

        Company::create(['name' => 'The Group Inc', 'code' => 'TGI', 'is_active' => true]);
        Setting::set('imap_username', 'support@example.test', 'email');

        $this->service = new BounceTestableEmailTicketService();
    }

    protected function tearDown(): void
    {
        $this->resetSettingCache();

        parent::tearDown();
    }

    private function resetSettingCache(): void
    {
        $cache = new \ReflectionProperty(Setting::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    private function ticketWithCc(string $ccEmail): Ticket
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<request-root@mail.gmail.com>',
            senderEmail: 'requester@example.test',
            subject: 'Printer at the counter is offline',
            body: 'The receipt printer at the counter stopped responding after the power interruption.',
            ccRecipients: [$ccEmail],
        ));

        $ticket = Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->firstOrFail();

        $this->assertDatabaseHas('ticket_ccs', ['ticket_id' => $ticket->id, 'email' => $ccEmail]);

        return $ticket;
    }

    /** A permanent Gmail DSN naming the failed CC, quoting the original headers. */
    private function permanentBounce(string $failedEmail, string $originalMessageId = '<request-root@mail.gmail.com>'): FakeEmailMessage
    {
        $report = <<<REPORT
        Your message wasn't delivered to {$failedEmail} because the address couldn't be found.

        Final-Recipient: rfc822; {$failedEmail}
        Action: failed
        Status: 5.1.1
        Diagnostic-Code: smtp; 550 5.1.1 The email account that you tried to reach does not exist.

        ----- Original message -----
        References: {$originalMessageId}
        Subject: Printer at the counter is offline
        REPORT;

        return new FakeEmailMessage(
            messageId: '<bounce-1@mail.googlemail.com>',
            senderEmail: 'mailer-daemon@googlemail.com',
            subject: 'Delivery Status Notification (Failure)',
            body: $report,
        );
    }

    public function test_a_permanent_bounce_disables_the_cc_and_is_recorded(): void
    {
        $ticket = $this->ticketWithCc('typo.address@example.test');

        $handled = $this->service->handleFake($this->permanentBounce('typo.address@example.test'));

        $this->assertFalse($handled, 'A bounce must not create a ticket or comment.');
        $this->assertSame(1, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());

        $cc = TicketCc::where('ticket_id', $ticket->id)->where('email', 'typo.address@example.test')->firstOrFail();
        $this->assertNotNull($cc->undeliverable_at);
        $this->assertStringContainsString('550', (string) $cc->undeliverable_reason);

        $log = EmailIntakeLog::where('message_key', 'bounce-1@mail.googlemail.com')->firstOrFail();
        $this->assertSame(EmailIntakeLog::OUTCOME_BOUNCE, $log->outcome);
        $this->assertSame($ticket->id, $log->ticket_id);
        $this->assertStringContainsString('typo.address@example.test', (string) $log->error);

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'column_changed' => 'email_delivery',
            'new_value' => 'bounced',
        ]);
    }

    public function test_a_disabled_cc_is_no_longer_emailed_but_stays_visible(): void
    {
        $ticket = $this->ticketWithCc('typo.address@example.test');
        $this->service->handleFake($this->permanentBounce('typo.address@example.test'));

        $ticket->refresh();

        $this->assertSame(
            ['typo.address@example.test'],
            $ticket->effectiveCcs()->pluck('email')->all(),
            'The CC row must remain on the ticket so staff can see and fix it.'
        );
        $this->assertSame([], $ticket->deliverableCcs()->pluck('email')->all());
        $this->assertFalse(
            $ticket->threadEmailRecipients()->pluck('email')->contains('typo.address@example.test')
        );
    }

    public function test_a_delayed_bounce_does_not_disable_the_cc(): void
    {
        $ticket = $this->ticketWithCc('slow.server@example.test');

        $delayReport = <<<REPORT
        Your message is delayed and delivery will be retried.

        Final-Recipient: rfc822; slow.server@example.test
        Action: delayed
        Status: 4.4.1
        Diagnostic-Code: smtp; 421 4.4.1 Connection timed out

        References: <request-root@mail.gmail.com>
        REPORT;

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<bounce-delay@mail.googlemail.com>',
            senderEmail: 'mailer-daemon@googlemail.com',
            subject: 'Delivery Status Notification (Delay)',
            body: $delayReport,
        ));

        $cc = TicketCc::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertNull($cc->undeliverable_at, 'A 4.x.x deferral is still being retried by the mail server.');
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'bounce-delay@mail.googlemail.com',
            'outcome' => EmailIntakeLog::OUTCOME_BOUNCE,
        ]);
    }

    public function test_a_bounce_that_matches_no_ticket_is_still_recorded(): void
    {
        $this->service->handleFake($this->permanentBounce('stranger@example.test', '<unknown-thread@mail.gmail.com>'));

        $log = EmailIntakeLog::where('message_key', 'bounce-1@mail.googlemail.com')->firstOrFail();

        $this->assertSame(EmailIntakeLog::OUTCOME_BOUNCE, $log->outcome);
        $this->assertNull($log->ticket_id);
        $this->assertStringContainsString('stranger@example.test', (string) $log->error);
    }

    public function test_a_bounce_is_resolved_by_ticket_key_when_headers_are_missing(): void
    {
        $ticket = $this->ticketWithCc('typo.address@example.test');

        $report = <<<REPORT
        Delivery to the following recipient failed permanently: typo.address@example.test

        Action: failed
        Status: 5.1.1
        Subject: [{$ticket->ticket_key}] Printer at the counter is offline
        REPORT;

        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<bounce-by-key@mail.googlemail.com>',
            senderEmail: 'mailer-daemon@googlemail.com',
            subject: 'Delivery Status Notification (Failure)',
            body: $report,
        ));

        $this->assertNotNull(
            TicketCc::where('ticket_id', $ticket->id)->firstOrFail()->undeliverable_at
        );
    }

    public function test_editing_the_cc_list_keeps_the_bounce_flag_and_requires_tickets_edit(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $ticket = $this->ticketWithCc('typo.address@example.test');
        $this->service->handleFake($this->permanentBounce('typo.address@example.test'));

        $payload = ['ccs' => [
            ['email' => 'typo.address@example.test', 'name' => 'Typo'],
            ['email' => 'someone.new@example.test', 'name' => 'New CC'],
        ]];

        // A viewer without tickets.edit cannot touch the list at all.
        $viewer = $this->userWithPermissions('Viewer', ['tickets.view']);
        $this->actingAs($viewer)
            ->put(route('tickets.sync-ccs', $ticket), $payload)
            ->assertForbidden();

        // An editor may — and the flag must survive the rewrite, or the next update
        // silently starts mailing the dead address again.
        $editor = $this->userWithPermissions('Editor', ['tickets.view', 'tickets.edit']);
        $this->actingAs($editor)
            ->put(route('tickets.sync-ccs', $ticket), $payload)
            ->assertRedirect();

        $ticket->refresh();

        $this->assertNotNull(
            $ticket->ccs()->where('email', 'typo.address@example.test')->firstOrFail()->undeliverable_at
        );
        $this->assertNull(
            $ticket->ccs()->where('email', 'someone.new@example.test')->firstOrFail()->undeliverable_at
        );
        $this->assertSame(['someone.new@example.test'], $ticket->deliverableCcs()->pluck('email')->all());
    }

    private function userWithPermissions(string $name, array $permissions): \App\Models\User
    {
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = \App\Models\Role::firstOrCreate(['name' => "Bounce {$name}", 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = \App\Models\User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    public function test_a_requester_writing_about_undeliverable_mail_still_opens_a_ticket(): void
    {
        $this->service->handleFake(new FakeEmailMessage(
            messageId: '<human-about-bounces@mail.gmail.com>',
            senderEmail: 'requester@example.test',
            subject: 'Undeliverable emails to our branch mailbox',
            body: 'Our branch mailbox keeps returning undeliverable errors, please check the mail settings.',
        ));

        $this->assertSame(1, Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)->count());
        $this->assertDatabaseHas('email_intake_logs', [
            'message_key' => 'human-about-bounces@mail.gmail.com',
            'outcome' => EmailIntakeLog::OUTCOME_CREATED,
        ]);
    }
}

class BounceTestableEmailTicketService extends EmailTicketService
{
    public array $errors = [];

    public function handleFake($message, bool $recovery = false): bool
    {
        return $this->handleFetchedMessage($message, $recovery, $this->errors);
    }
}
