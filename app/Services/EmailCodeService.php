<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the emailed one-time codes that prove a signed-out
 * member owns an inbox — the mobile app's Forgot Password and the public
 * account-deletion page. (The signed-in post-login step keeps its own logic
 * in `Api\OtpController`, whose API contract predates this.)
 *
 * Codes live in `otp_codes` under a purpose, so issuing one kind never
 * retires another kind's outstanding code. Only the hash is stored.
 */
class EmailCodeService
{
    public const CODE_LENGTH = 6;
    public const MAX_ATTEMPTS = 5;

    public const OK = 'ok';
    public const EXPIRED = 'expired';   // none outstanding, expired, or already spent
    public const LOCKED = 'locked';     // attempts exhausted
    public const WRONG = 'wrong';

    /** Retires this purpose's outstanding code and returns a fresh plaintext one. */
    public function issue(User $user, string $purpose, int $validMinutes): string
    {
        OtpCode::where('user_id', $user->id)
            ->purpose($purpose)
            ->whereNull('consumed_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($validMinutes),
        ]);

        return $code;
    }

    /**
     * Checks without spending — the caller consumes the returned code once
     * the action it guards has succeeded. A null user checks like a user
     * with no code outstanding, so an unknown email is indistinguishable.
     *
     * @return array{status: string, otp: ?OtpCode, remaining: ?int}
     */
    public function check(?User $user, string $purpose, string $code): array
    {
        $otp = $user
            ? OtpCode::where('user_id', $user->id)
                ->purpose($purpose)
                ->whereNull('consumed_at')
                ->latest('created_at')
                ->first()
            : null;

        if (! $otp) {
            return ['status' => self::EXPIRED, 'otp' => null, 'remaining' => null];
        }

        if ($otp->isExpired()) {
            $otp->delete();

            return ['status' => self::EXPIRED, 'otp' => null, 'remaining' => null];
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return ['status' => self::LOCKED, 'otp' => null, 'remaining' => 0];
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $remaining = max(0, self::MAX_ATTEMPTS - $otp->attempts);

            return $remaining <= 0
                ? ['status' => self::LOCKED, 'otp' => null, 'remaining' => 0]
                : ['status' => self::WRONG, 'otp' => null, 'remaining' => $remaining];
        }

        return ['status' => self::OK, 'otp' => $otp, 'remaining' => null];
    }
}
