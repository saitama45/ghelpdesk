<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `otp_required` on `POST /api/login`: Google Play rejected the app because
 * its review login stopped at the emailed-code screen, so the store-review
 * accounts must sign in with email and password alone.
 */
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Pass';

    private function member(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function login(string $email)
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => self::PASSWORD,
            'device_name' => 'test-device',
        ]);
    }

    public function test_a_member_must_verify_a_sign_in_code(): void
    {
        config(['services.app_review.email' => 'reviewer@example.com']);
        $this->member('jane@example.com');

        $this->login('jane@example.com')
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'roles'])
            ->assertJsonPath('otp_required', true);
    }

    public function test_a_store_review_account_skips_the_sign_in_code(): void
    {
        config(['services.app_review.email' => 'reviewer@example.com']);
        $this->member('reviewer@example.com');

        $this->login('reviewer@example.com')
            ->assertStatus(200)
            ->assertJsonPath('otp_required', false);
    }

    public function test_every_listed_review_account_skips_it_whatever_its_case(): void
    {
        config(['services.app_review.email' => 'keeper@example.com, Deleter@Example.com']);
        $this->member('keeper@example.com');
        $this->member('deleter@example.com');

        $this->login('keeper@example.com')->assertJsonPath('otp_required', false);
        $this->login('deleter@example.com')->assertJsonPath('otp_required', false);
    }

    public function test_an_address_that_only_contains_a_listed_one_still_needs_the_code(): void
    {
        config(['services.app_review.email' => 'reviewer@example.com']);
        $this->member('reviewer@example.com.ph');

        $this->login('reviewer@example.com.ph')->assertJsonPath('otp_required', true);
    }

    public function test_with_no_review_accounts_configured_everyone_needs_the_code(): void
    {
        config(['services.app_review.email' => null]);
        $this->member('reviewer@example.com');

        $this->login('reviewer@example.com')->assertJsonPath('otp_required', true);
    }

    public function test_a_wrong_password_for_a_review_account_is_still_refused(): void
    {
        config(['services.app_review.email' => 'reviewer@example.com']);
        $this->member('reviewer@example.com');

        $this->postJson('/api/login', [
            'email' => 'reviewer@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonMissingPath('token');
    }
}
