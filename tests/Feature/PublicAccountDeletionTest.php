<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicAccountDeletionController;
use App\Mail\AccountDeletionCodeMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tickets need an entity for their key — same default an emailed
        // request gets.
        Company::create(['name' => 'Table Group Inc.', 'code' => 'TGI', 'is_active' => true]);
    }

    public function test_the_page_offers_the_request_form_without_javascript(): void
    {
        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Request deletion')
            ->assertSee('action="'.route('public.account-deletion.code').'"', false)
            ->assertDontSee('Open your email app');
    }

    public function test_sending_a_code_emails_the_member_and_moves_to_the_code_step(): void
    {
        Mail::fake();
        $member = $this->member();

        $this->post('/account-deletion/code', ['email' => 'JANE@example.com'])
            ->assertRedirect();

        Mail::assertSent(AccountDeletionCodeMail::class, fn ($mail) => $mail->hasTo($member->email));
        $this->get('/account-deletion')
            ->assertSee('Confirm your request')
            ->assertSee('jane@example.com');
    }

    public function test_an_unknown_email_looks_identical_but_nothing_is_sent(): void
    {
        Mail::fake();

        $this->post('/account-deletion/code', ['email' => 'nobody@example.com'])->assertRedirect();

        Mail::assertNothingSent();
        $this->get('/account-deletion')->assertSee('Confirm your request');
    }

    public function test_a_staff_account_cannot_be_closed_from_this_page(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'staff@example.com']); // no customer_id

        $this->post('/account-deletion/code', ['email' => 'staff@example.com']);

        Mail::assertNothingSent();
    }

    public function test_the_right_code_files_a_ticket_and_confirms_it_to_the_member(): void
    {
        Mail::fake();
        $member = $this->member();

        $this->post('/account-deletion/code', ['email' => 'jane@example.com']);
        $this->post('/account-deletion/confirm', [
            'code' => $this->capturedCode(),
            'reason' => 'Moving abroad.',
            'confirm' => '1',
        ])->assertRedirect();

        $ticket = $this->deletionTickets()->sole();
        $this->assertSame('jane@example.com', $ticket->sender_email);
        $this->assertSame($member->id, $ticket->reporter_id);
        $this->assertSame('open', $ticket->status);
        $this->assertStringContainsString('Moving abroad.', $ticket->description);
        $this->assertStringContainsString('0917 000 0000', $ticket->description);
        $this->assertNotNull($ticket->ticket_key);

        Mail::assertSent(AccountDeletionRequestedMail::class, fn ($mail) => $mail->hasTo($member->email) && $mail->ticketKey === $ticket->ticket_key);
        $this->get('/account-deletion')
            ->assertSee('Your request has been filed.')
            ->assertSee($ticket->ticket_key);
    }

    public function test_a_wrong_code_files_nothing(): void
    {
        Mail::fake();
        $this->member();

        $this->post('/account-deletion/code', ['email' => 'jane@example.com']);
        $wrong = $this->capturedCode() === '111111' ? '222222' : '111111';

        $this->post('/account-deletion/confirm', ['code' => $wrong, 'confirm' => '1'])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, $this->deletionTickets()->count());
    }

    public function test_the_confirmation_box_is_required_and_the_code_survives_forgetting_it(): void
    {
        Mail::fake();
        $this->member();

        $this->post('/account-deletion/code', ['email' => 'jane@example.com']);
        $code = $this->capturedCode();

        $this->post('/account-deletion/confirm', ['code' => $code])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(0, $this->deletionTickets()->count());

        $this->post('/account-deletion/confirm', ['code' => $code, 'confirm' => '1']);
        $this->assertSame(1, $this->deletionTickets()->count());
    }

    public function test_asking_twice_reuses_the_open_ticket(): void
    {
        Mail::fake();
        $this->member();

        foreach ([1, 2] as $round) {
            $this->post('/account-deletion/restart');
            \Illuminate\Support\Facades\RateLimiter::clear('account-deletion-send-cooldown:'.sha1('jane@example.com'));
            Mail::fake();
            $this->post('/account-deletion/code', ['email' => 'jane@example.com']);
            $this->post('/account-deletion/confirm', ['code' => $this->capturedCode(), 'confirm' => '1']);
        }

        $this->assertSame(1, $this->deletionTickets()->count());
    }

    public function test_a_password_reset_code_cannot_confirm_a_deletion(): void
    {
        Mail::fake();
        $this->member();

        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $resetCode = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$resetCode) {
            $resetCode = $mail->code;

            return true;
        });

        // Reach the code step without a deletion code ever being issued.
        $this->post('/account-deletion/code', ['email' => 'jane@example.com']);
        OtpCode::purpose(OtpCode::PURPOSE_ACCOUNT_DELETION)->update(['expires_at' => now()->subMinute()]);

        $this->post('/account-deletion/confirm', ['code' => $resetCode, 'confirm' => '1'])
            ->assertSessionHasErrors('code');
        $this->assertSame(0, $this->deletionTickets()->count());
    }

    public function test_confirming_without_requesting_a_code_starts_over(): void
    {
        $this->post('/account-deletion/confirm', ['code' => '123456', 'confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertSame(0, $this->deletionTickets()->count());
        $this->get('/account-deletion')->assertSee('Send code')->assertSee('session expired');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function member(): User
    {
        $customer = Customer::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '0917 000 0000',
            'is_active' => true,
        ]);

        return User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'customer_id' => $customer->id,
        ]);
    }

    private function deletionTickets()
    {
        return Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->where('title', PublicAccountDeletionController::TICKET_TITLE);
    }

    private function capturedCode(): string
    {
        $code = null;
        Mail::assertSent(AccountDeletionCodeMail::class, function (AccountDeletionCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No AccountDeletionCodeMail was captured.');

        return $code;
    }
}
