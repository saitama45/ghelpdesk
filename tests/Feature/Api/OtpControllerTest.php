<?php

namespace Tests\Feature\Api;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OtpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── /api/otp/send ────────────────────────────────────────────────────

    public function test_send_emails_a_code_and_returns_the_masked_destination(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/send');

        $response->assertStatus(200)->assertJson([
            'destination' => 'j***@example.com',
            'expires_in' => 300,
            'resend_after' => 30,
        ]);

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use ($user) {
            return $mail->hasTo($user->email) && strlen($mail->code) === 6;
        });
    }

    public function test_send_uses_a_sender_name_distinct_from_ticket_mail(): void
    {
        // "TAS Service Center" is the global mail.from.name (driven by the
        // settings table) used for ticket notifications — the mobile app's
        // OTP mail must not borrow that identity.
        Mail::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) {
            return $mail->hasFrom(config('mail.from.address'), 'Coffee Bean & Tea Leaf')
                && ! $mail->hasFrom(config('mail.from.address'), 'TAS Service Center');
        });
    }

    public function test_send_never_puts_the_plaintext_code_in_the_database(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        $otp = OtpCode::where('user_id', $user->id)->first();
        $this->assertNotNull($otp);
        $this->assertNotEquals(6, strlen($otp->code_hash)); // a bcrypt hash, not a 6-digit code
        $this->assertTrue(Hash::check(
            $this->capturedCode(),
            $otp->code_hash,
        ));
    }

    public function test_send_invalidates_a_previous_unconsumed_code(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');
        $this->assertSame(1, OtpCode::where('user_id', $user->id)->count());

        // RateLimiter's decay is wall-clock (Carbon::setTestNow can't fast
        // forward it), so clear the cooldown key directly to simulate the
        // 30 seconds having genuinely passed.
        RateLimiter::clear("otp-send-cooldown:{$user->id}");
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        // The first code is gone — only the newest one is ever valid.
        $this->assertSame(1, OtpCode::where('user_id', $user->id)->count());
    }

    public function test_send_requires_authentication(): void
    {
        $this->postJson('/api/otp/send')->assertStatus(401);
    }

    public function test_second_send_within_the_cooldown_is_throttled(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send')
            ->assertStatus(200);

        $response = $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        $response->assertStatus(429);
        $response->assertJsonStructure(['message', 'retry_after']);
        $this->assertLessThanOrEqual(30, $response->json('retry_after'));
    }

    public function test_send_throttling_is_scoped_per_user(): void
    {
        Mail::fake();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($userA))->postJson('/api/otp/send')
            ->assertStatus(200);

        // Sanctum's RequestGuard caches the resolved user for the life of the
        // guard instance, so switching Bearer tokens within one test needs a
        // forced re-resolve or the next call silently authenticates as userA
        // again — see DtrOfflineApiTest for the same pattern.
        auth()->forgetGuards();

        // A different member is unaffected by userA's cooldown.
        $this->withHeaders($this->bearerHeaders($userB))->postJson('/api/otp/send')
            ->assertStatus(200);
    }

    public function test_send_is_blocked_after_the_hourly_cap(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        RateLimiter::clear("otp-send-hourly:{$user->id}");
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit("otp-send-hourly:{$user->id}", 3600);
        }

        $response = $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        $response->assertStatus(429);
    }

    // ── /api/otp/verify ──────────────────────────────────────────────────

    public function test_verify_accepts_the_correct_code(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => $this->capturedCode()]);

        $response->assertStatus(200)->assertJson(['verified' => true]);
    }

    public function test_verify_marks_the_code_consumed_so_it_cannot_be_replayed(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');
        $code = $this->capturedCode();

        $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => $code])
            ->assertStatus(200);

        $replay = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => $code]);

        $replay->assertStatus(410);
    }

    public function test_verify_rejects_a_wrong_code_and_reports_attempts_remaining(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => '000000']);

        $response->assertStatus(422)->assertJson([
            'message' => 'Incorrect code.',
            'attempts_remaining' => 4,
        ]);
    }

    public function test_verify_blocks_after_five_wrong_attempts(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');
        $wrongCode = $this->capturedCode() === '111111' ? '222222' : '111111';

        $last = null;
        for ($i = 0; $i < 5; $i++) {
            $last = $this->withHeaders($this->bearerHeaders($user))
                ->postJson('/api/otp/verify', ['code' => $wrongCode]);
        }

        $last->assertStatus(429);

        // The correct code no longer works either — the code itself is spent.
        $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => $this->capturedCode()])
            ->assertStatus(429);
    }

    public function test_verify_rejects_an_expired_code(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->withHeaders($this->bearerHeaders($user))->postJson('/api/otp/send');
        $code = $this->capturedCode();

        Carbon::setTestNow(now()->addMinutes(6));

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => $code]);

        $response->assertStatus(410);
    }

    public function test_verify_with_no_code_ever_requested_reports_expired(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', ['code' => '123456']);

        $response->assertStatus(410);
    }

    public function test_verify_requires_authentication(): void
    {
        $this->postJson('/api/otp/verify', ['code' => '123456'])->assertStatus(401);
    }

    public function test_verify_requires_a_code(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/otp/verify', [])
            ->assertStatus(422);
    }

    public function test_one_members_code_cannot_verify_another_members_session(): void
    {
        Mail::fake();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($userA))->postJson('/api/otp/send');
        $codeForA = $this->capturedCode();

        auth()->forgetGuards();

        // userB has never requested a code, so nothing they submit can match.
        $response = $this->withHeaders($this->bearerHeaders($userB))
            ->postJson('/api/otp/verify', ['code' => $codeForA]);

        $response->assertStatus(410);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function bearerHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken,
        ];
    }

    /**
     * Pulls the plaintext code out of the mail that Mail::fake() captured —
     * the only place it exists once the request finishes.
     */
    private function capturedCode(): string
    {
        $code = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No OtpCodeMail was captured by Mail::fake().');

        return $code;
    }
}
