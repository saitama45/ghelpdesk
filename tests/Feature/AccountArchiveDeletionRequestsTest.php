<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicAccountDeletionController;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Settings → Account Archive → Deletion Requests: members who filed a request on
 * the public /account-deletion page show up for staff to archive.
 *
 * The archive itself (a soft delete of the pair) is deliberately not executed
 * here; only the listing and the authorization in front of it are.
 */
class AccountArchiveDeletionRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Table Group Inc.', 'code' => 'TGI', 'is_active' => true]);
    }

    public function test_a_member_with_an_open_request_is_listed(): void
    {
        $member = $this->member('jane@example.com');
        $ticket = $this->requestTicket($member);

        $other = $this->member('bob@example.com');
        $this->requestTicket($other, 'closed');

        $this->actingAs($this->staff(['settings.view']))
            ->get('/settings/account-archive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AccountArchive')
                ->where('tab', 'requests')
                ->where('counts.requests', 1)
                ->where('can.archive_requests', false)
                ->has('records.data', 1)
                ->where('records.data.0.id', $member->id)
                ->where('records.data.0.ticket.key', $ticket->refresh()->ticket_key)
                ->where('records.data.0.linked.name', 'Member jane@example.com'));
    }

    public function test_archiving_a_request_needs_the_stamps_delete_permission(): void
    {
        $member = $this->member('jane@example.com');
        $this->requestTicket($member);

        $this->actingAs($this->staff(['settings.view']))
            ->post('/settings/account-archive/archive', ['ids' => [$member->id]])
            ->assertForbidden();

        $this->assertFalse($member->fresh()->trashed());
    }

    public function test_the_permission_is_reported_to_the_page(): void
    {
        $this->actingAs($this->staff(['settings.view', 'stamps.delete']))
            ->get('/settings/account-archive?tab=requests')
            ->assertInertia(fn (Assert $page) => $page->where('can.archive_requests', true));
    }

    public function test_a_restore_returns_to_the_tab_it_was_made_on_not_the_referer(): void
    {
        // Nothing is archived, so the restore finds no record and only its
        // redirect is exercised. The Referer points at Deletion Requests, which
        // is where back() used to bounce a Loyalty Customers restore.
        $this->actingAs($this->staff(['settings.view', 'stamps.edit']))
            ->withHeader('Referer', url('/settings/account-archive?tab=requests'))
            ->post('/settings/account-archive/restore', [
                'type' => 'customers',
                'ids' => [999999],
                'search' => 'harold',
                'per_page' => 25,
                'page' => 1,
            ])
            ->assertRedirect(route('account-archive.index', ['tab' => 'customers', 'search' => 'harold', 'per_page' => 25]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function member(string $email): User
    {
        $customer = Customer::create([
            'name' => "Member {$email}",
            'email' => $email,
            'is_active' => true,
        ]);

        return User::factory()->create([
            'email' => $email,
            'customer_id' => $customer->id,
        ]);
    }

    private function requestTicket(User $member, string $status = 'open'): Ticket
    {
        return Ticket::create([
            'title' => PublicAccountDeletionController::TICKET_TITLE,
            'description' => 'test',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'reporter_id' => $member->id,
            'sender_email' => $member->email,
            'company_id' => Company::value('id'),
        ]);
    }
}
