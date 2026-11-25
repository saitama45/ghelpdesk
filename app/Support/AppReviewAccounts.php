<?php

namespace App\Support;

/**
 * The App Store / Play Store review accounts named by `services.app_review.email`.
 *
 * `APP_REVIEW_EMAIL` takes one address or several separated by commas. Apple's
 * reviewer is asked to test account deletion (Guideline 5.1.1(v)), so the review
 * notes name two accounts: one pre-loaded with stamps that is never deleted, and
 * one to delete. Every listed account skips the app's emailed-code step
 * (`otp_required: false`, `Api\AuthController::otpRequiredFor`), gets the
 * fixed code `APP_REVIEW_OTP` from older app builds that still ask for one
 * (`Api\OtpController`), and may sign up again after closing
 * (`Api\RegisterController`).
 *
 * A listed account signs in to the app with its password alone, so never list
 * a real member's or staff member's address here.
 */
final class AppReviewAccounts
{
    /**
     * Lower-cased, trimmed addresses; empty when the allowlist is off.
     *
     * @return list<string>
     */
    public static function emails(): array
    {
        $raw = (string) config('services.app_review.email', '');

        return array_values(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', $raw),
        )));
    }

    public static function includes(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return in_array(mb_strtolower(trim($email)), self::emails(), true);
    }
}
