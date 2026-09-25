<?php

namespace Tests\Feature;

use App\Mail\AccountClosedMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Mail\OtpCodeMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountArchiveService;
use App\Services\AccountDeletionRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Profile → Delete Account in the mobile app (App Store Review Guideline
 * 5.1.1(v)), and the one thing a store reviewer needs before they can reach it:
 * a demo account that gets past the post-login code without a mailbox.
 *
 * The distinction this pins down is why the endpoint exists at all — the public
 * page FILES a request for the desk to act on, while the app, having just
 * re-authenticated the member, closes the account there and then and leaves the
 * ticket behind only as the record.
 */
class MobileAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Table Group Inc.', 'code' => 'TGI', 'is_active' => true]);
    }

    private function member(string $password = 'Str0ng!pass'): User
    {
        $customer = Customer::create([
            'name' => 'Member One',
            'email' => 'member@example.test',
            'phone' => '09171234567',
            'is_active' => true,
        ]);

        return User::factory()->create([
            'name' => 'Member One',
            'email' => 'member@example.test',
            'password' => Hash::make($password),
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function requestTickets(): int
    {
        return Ticket::where('title', AccountDeletionRequestService::TICKET_TITLE)->count();
    }

    public function test_member_can_delete_their_own_account(): void
    {
        Mail::fake();
        $user = $this->member();
        $customerId = $user->customer_id;
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])
            ->assertOk()
            ->assertJsonPath('message', 'Your account has been closed.');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('customers', ['id' => $customerId]);
        $this->assertNotNull($response->json('reference'));
    }

    public function test_deletion_files_a_request_ticket_saying_it_is_already_closed(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', [
            'password' => 'Str0ng!pass',
            'reason' => 'Moving abroad',
        ])->assertOk();

        $ticket = Ticket::where('title', AccountDeletionRequestService::TICKET_TITLE)->firstOrFail();
        $this->assertSame($user->id, $ticket->reporter_id);
        $this->assertStringContainsString('ALREADY CLOSED', $ticket->description);
        $this->assertStringContainsString('re-entering their password', $ticket->description);
        $this->assertStringContainsString('Moving abroad', $ticket->description);
        // The customer details are read before the archive, not after.
        $this->assertStringContainsString('09171234567', $ticket->description);
    }

    public function test_the_member_is_emailed_that_the_account_is_closed_not_merely_requested(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        Mail::assertSent(AccountClosedMail::class);
        Mail::assertNotSent(AccountDeletionRequestedMail::class);
    }

    public function test_every_token_is_revoked_not_just_the_calling_one(): void
    {
        Mail::fake();
        $user = $this->member();
        $user->createToken('other-handset');
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deletion_requires_the_correct_password(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertSame(0, $this->requestTickets());
    }

    public function test_a_login_with_no_loyalty_record_cannot_be_deleted_from_the_app(): void
    {
        Mail::fake();
        $staff = User::factory()->create([
            'email' => 'staff@example.test',
            'password' => Hash::make('Str0ng!pass'),
            'customer_id' => null,
            'is_active' => true,
        ]);
        Sanctum::actingAs($staff);

        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])
            ->assertStatus(403);

        $this->assertNotSoftDeleted('users', ['id' => $staff->id]);
        $this->assertSame(0, $this->requestTickets());
    }

    /**
     * Regression: the guard used to be "has no roles", which 403'd a real
     * member who also held a role — an unrelated permission grant silently
     * removing the deletion right App Store Review Guideline 5.1.1(v) requires.
     */
    public function test_a_member_who_also_holds_a_role_can_still_delete_their_account(): void
    {
        Mail::fake();
        $user = $this->member();
        $user->assignRole(Role::findOrCreate('Agent'));
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_an_open_web_request_is_reused_rather_than_duplicated(): void
    {
        Mail::fake();
        $user = $this->member();

        // The member filed on the public page first, then deleted in the app.
        app(AccountDeletionRequestService::class)->openFor(
            $user, null, '127.0.0.1', AccountDeletionRequestService::CHANNEL_WEB
        );

        Sanctum::actingAs($user);
        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        $this->assertSame(1, $this->requestTickets());
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    /**
     * `users.email` is UNIQUE and an archive is only a soft delete, so before
     * this the archived row owned the address for ever and registering again
     * answered "The email has already been taken" — which is precisely what an
     * App Store reviewer does straight after testing account deletion.
     */
    public function test_the_email_is_free_to_register_again_after_deletion(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        // The archived row kept the address somewhere it can be read back.
        $archived = User::withTrashed()->find($user->id);
        $this->assertSame('member@example.test', $archived->archived_email);
        $this->assertSame("deleted-{$user->id}@archived.invalid", $archived->email);

        $this->postJson('/api/register', [
            'name' => 'Member One',
            'email' => 'member@example.test',
            'phone' => '09171234567',
            'password' => 'An0ther!pass',
        ])->assertCreated();

        // The new login owns the address; the old one is still archived.
        $this->assertSame(1, User::where('email', 'member@example.test')->count());
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_restoring_an_account_gives_the_address_back(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        $archived = User::withTrashed()->find($user->id);
        $result = app(AccountArchiveService::class)->restoreUser($archived);

        $this->assertTrue($result['email_reclaimed']);
        $this->assertSame('member@example.test', $archived->fresh()->email);
        $this->assertNull($archived->fresh()->archived_email);
    }

    public function test_a_restore_will_not_steal_an_address_someone_else_now_owns(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/account', ['password' => 'Str0ng!pass'])->assertOk();

        // Someone registers again with the freed address before the restore.
        $this->postJson('/api/register', [
            'name' => 'Member One',
            'email' => 'member@example.test',
            'phone' => '09171234567',
            'password' => 'An0ther!pass',
        ])->assertCreated();

        $archived = User::withTrashed()->find($user->id);
        $result = app(AccountArchiveService::class)->restoreUser($archived);

        // Restored, but the tombstone stays rather than breaking the unique index.
        $this->assertFalse($result['email_reclaimed']);
        $this->assertSame("deleted-{$user->id}@archived.invalid", $archived->fresh()->email);
        $this->assertSame(1, User::where('email', 'member@example.test')->count());
    }

    public function test_review_account_gets_the_fixed_code_and_no_email(): void
    {
        Mail::fake();
        config([
            'services.app_review.email' => 'member@example.test',
            'services.app_review.otp' => '424242',
        ]);

        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/otp/send')->assertOk();
        Mail::assertNothingSent();

        $this->postJson('/api/otp/verify', ['code' => '424242'])
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_ordinary_members_still_get_a_mailed_random_code(): void
    {
        Mail::fake();
        config([
            'services.app_review.email' => 'reviewer@example.test',
            'services.app_review.otp' => '424242',
        ]);

        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/api/otp/send')->assertOk();
        Mail::assertSent(OtpCodeMail::class);

        // The allowlisted code must not open somebody else's account.
        $this->postJson('/api/otp/verify', ['code' => '424242'])
            ->assertStatus(422);
    }
}
