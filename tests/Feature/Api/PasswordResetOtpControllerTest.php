<?php

namespace Tests\Feature\Api;

use App\Mail\OtpCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetOtpControllerTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'N3w-Passw0rd!';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── /api/password/forgot ─────────────────────────────────────────────

    public function test_forgot_emails_a_reset_code_to_an_active_member(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com'])
            ->assertStatus(200)
            ->assertJson(['expires_in' => 600, 'resend_after' => 30]);

        Mail::assertSent(PasswordResetCodeMail::class, fn (PasswordResetCodeMail $mail) => $mail->hasTo($user->email) && strlen($mail->code) === 6);
        $this->assertSame(1, OtpCode::where('user_id', $user->id)->purpose(OtpCode::PURPOSE_PASSWORD_RESET)->count());
    }

    public function test_forgot_answers_an_unknown_email_exactly_like_a_known_one(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jane@example.com']);

        $known = $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com']);

        $unknown->assertStatus(200);
        $this->assertSame($known->json(), $unknown->json());
        Mail::assertSentCount(1);
    }

    public function test_forgot_sends_nothing_to_a_deactivated_account(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'gone@example.com', 'is_active' => false]);

        $this->postJson('/api/password/forgot', ['email' => 'gone@example.com'])->assertStatus(200);

        Mail::assertNothingSent();
    }

    public function test_second_forgot_within_the_cooldown_is_throttled(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com'])->assertStatus(200);

        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com'])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    // ── /api/password/verify ─────────────────────────────────────────────

    public function test_verify_accepts_the_code_without_spending_it(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);

        $this->postJson('/api/password/verify', ['email' => 'jane@example.com', 'code' => $this->capturedCode()])
            ->assertStatus(200)
            ->assertJson(['verified' => true]);

        $this->assertNull(OtpCode::where('user_id', $user->id)->first()->consumed_at);
    }

    public function test_verify_rejects_a_wrong_code_and_reports_attempts_remaining(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $wrong = $this->capturedCode() === '111111' ? '222222' : '111111';

        $this->postJson('/api/password/verify', ['email' => 'jane@example.com', 'code' => $wrong])
            ->assertStatus(422)
            ->assertJson(['message' => 'Incorrect code.', 'attempts_remaining' => 4]);
    }

    public function test_verify_rejects_an_expired_code(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $code = $this->capturedCode();

        Carbon::setTestNow(now()->addMinutes(11));

        $this->postJson('/api/password/verify', ['email' => 'jane@example.com', 'code' => $code])
            ->assertStatus(410);
    }

    // ── /api/password/reset ──────────────────────────────────────────────

    public function test_reset_sets_the_new_password_and_signs_every_device_out(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $user->createToken('old-phone');
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);

        $this->postJson('/api/password/reset', [
            'email' => 'jane@example.com',
            'code' => $this->capturedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertSame(0, $user->tokens()->count());

        // The new password works for the mobile app's sign-in.
        $this->postJson('/api/login', ['email' => 'jane@example.com', 'password' => self::NEW_PASSWORD])
            ->assertStatus(200);
    }

    public function test_reset_code_cannot_be_replayed(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $payload = [
            'email' => 'jane@example.com',
            'code' => $this->capturedCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/api/password/reset', $payload)->assertStatus(200);
        $this->postJson('/api/password/reset', $payload)->assertStatus(410);
    }

    public function test_reset_with_a_wrong_code_leaves_the_password_alone(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $wrong = $this->capturedCode() === '111111' ? '222222' : '111111';

        $this->postJson('/api/password/reset', [
            'email' => 'jane@example.com',
            'code' => $wrong,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_rejects_a_weak_password_without_spending_the_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);

        $this->postJson('/api/password/reset', [
            'email' => 'jane@example.com',
            'code' => $this->capturedCode(),
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(OtpCode::where('user_id', $user->id)->first()->consumed_at);
    }

    // ── Separation from the post-login OTP ───────────────────────────────

    public function test_a_login_code_cannot_reset_a_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $token = $user->createToken('phone')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/otp/send');

        $loginCode = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$loginCode) {
            $loginCode = $mail->code;

            return true;
        });

        $this->postJson('/api/password/reset', [
            'email' => 'jane@example.com',
            'code' => $loginCode,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(410);
    }

    public function test_requesting_a_login_code_does_not_retire_a_pending_reset_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $this->postJson('/api/password/forgot', ['email' => 'jane@example.com']);
        $resetCode = $this->capturedCode();

        $token = $user->createToken('phone')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/api/otp/send');
        auth()->forgetGuards();

        $this->postJson('/api/password/verify', ['email' => 'jane@example.com', 'code' => $resetCode])
            ->assertStatus(200);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function capturedCode(): string
    {
        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No PasswordResetCodeMail was captured by Mail::fake().');

        return $code;
    }
}
