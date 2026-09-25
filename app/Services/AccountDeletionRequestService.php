<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CompanyContext;

/**
 * Files the support ticket that records a member asking for their loyalty
 * account to be deleted.
 *
 * There are two ways to ask, and they are NOT the same request:
 *
 *  - [self::CHANNEL_WEB] — the public /account-deletion page. The requester is
 *    anonymous, so a one-time code emailed to the address is what proves they
 *    own the account, and the account is left open for the desk to archive
 *    from Settings → Account Archive → Deletion Requests.
 *  - [self::CHANNEL_APP] — Profile → Delete Account in the mobile app. The
 *    caller already holds a valid session token AND has just re-entered their
 *    password, which is stronger proof than the emailed code, so there is
 *    nothing left for a human to verify: `Api\AccountController` archives the
 *    account itself and this ticket is the record of it, not a work item.
 *
 * Either way the desk ends up with one ticket per member to reply on, which is
 * why both channels come through here instead of each writing their own.
 */
class AccountDeletionRequestService
{
    public const TICKET_TITLE = 'Account Deletion Request';

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_APP = 'app';

    public function __construct(private AutoAssigneeService $assignees) {}

    /**
     * The member's still-open request, or a new one.
     *
     * Reusing an open ticket instead of stacking duplicates is deliberate: a
     * member who submits twice — or who files on the web and then deletes in
     * the app — should show up once on the desk.
     */
    public function openFor(
        User $member,
        ?string $reason,
        ?string $ip,
        string $channel = self::CHANNEL_WEB,
    ): Ticket {
        $existing = Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->where('sender_email', $member->email)
            ->where('title', self::TICKET_TITLE)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Same defaults an emailed request to the support mailbox gets
        // (EmailTicketService): TGI entity, shared intake pool, the sender's
        // auto-assign rules. Key generation is left to TicketObserver.
        $companyId = Company::where('code', CompanyContext::DEFAULT_COMPANY_CODE)->value('id')
            ?? Company::orderBy('id')->value('id');

        $ticket = Ticket::create([
            'title' => self::TICKET_TITLE,
            'description' => $this->describe($member, $reason, $ip, $channel),
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'reporter_id' => $member->id,
            'sender_email' => $member->email,
            'sender_name' => mb_substr($member->name, 0, 255),
            'company_id' => $companyId,
        ]);

        $resolved = $this->assignees->resolveAssignee($member->email);
        $update = [];
        if ($resolved['assignee_id'] && User::whereKey($resolved['assignee_id'])->exists()) {
            $update['assignee_id'] = $resolved['assignee_id'];
        }
        if ($resolved['company_id']) {
            $update['company_id'] = $resolved['company_id'];
        }
        if ($update) {
            $ticket->update($update);
        }

        return $ticket->refresh();
    }

    /**
     * The ticket body. Read by whoever picks the ticket up, so it has to say
     * plainly whether the account is still open or already closed — the two
     * channels leave the desk with completely different work to do.
     */
    private function describe(User $member, ?string $reason, ?string $ip, string $channel): string
    {
        $inApp = $channel === self::CHANNEL_APP;
        $customer = $member->customer;

        $lines = [
            'A member requested deletion of their loyalty app account and all associated data.',
            $inApp
                ? 'The member deleted their account from inside the mobile app, re-entering their password to confirm.'
                : 'The request was verified with a one-time code sent to the registered email address.',
            '',
            'Name: '.$member->name,
            'Email: '.$member->email,
            'Mobile: '.($customer?->phone ?: '—'),
            'User ID: '.$member->id,
            'Customer ID: '.($customer?->id ?? '—'),
            'Submitted: '.now('Asia/Manila')->format('M j, Y g:i A').' (Asia/Manila) from '.($ip ?: 'unknown IP'),
        ];

        if ($reason !== null && trim($reason) !== '') {
            $lines[] = '';
            $lines[] = 'Reason given: '.trim($reason);
        }

        $lines[] = '';
        $lines[] = $inApp
            ? 'ALREADY CLOSED — the account and its loyalty customer record were archived and every session revoked at the moment the member confirmed. Nothing to archive here. It becomes purge-eligible after the retention period, from Settings → Account Archive → Loyalty Customers.'
            : 'Process it on Settings → Account Archive → Deletion Requests (archive now, purge after the retention period).';

        return implode("\n", $lines);
    }
}
