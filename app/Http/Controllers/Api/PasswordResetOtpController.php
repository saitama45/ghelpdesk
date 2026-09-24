<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\EmailCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

/**
 * Signed-out "Forgot Password" for the mobile loyalty app, done entirely
 * in-app with an emailed one-time code instead of the web reset link.
 *
 *   POST /api/password/forgot  { email }
 *   POST /api/password/verify  { email, code }
 *   POST /api/password/reset   { email, code, password, password_confirmation }
 *
 * `/forgot` answers identically whether or not the email belongs to an
 * active account, so the endpoint cannot be used to discover who is a
 * member. `/verify` only checks the code (so the app can move to the
 * new-password step); `/reset` checks it again and consumes it, and that is
 * the only call that changes anything. Codes come from [EmailCodeService]
 * under their own purpose, so neither this flow nor the post-login step can
 * retire the other's code. Mirrors the Flutter client in
 * `lib/data/datasources/remote/password_reset_remote_datasource.dart`.
 */
class PasswordResetOtpController extends Controller
{
    private const VALID_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const HOURLY_SEND_LIMIT = 5;

    public function __construct(private readonly EmailCodeService $codes) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($validated['email']));

        // Keyed on the address, not the account, so an unknown email is
        // throttled exactly like a real one and the two stay indistinguishable.
        $emailKey = sha1($email);

        $cooldownKey = "pw-reset-send-cooldown:{$emailKey}";
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'retry_after' => RateLimiter::availableIn($cooldownKey),
            ], 429);
        }

        $hourlyKey = "pw-reset-send-hourly:{$emailKey}";
        if (RateLimiter::tooManyAttempts($hourlyKey, self::HOURLY_SEND_LIMIT)) {
            return response()->json([
                'message' => 'Too many code requests. Please try again later.',
                'retry_after' => RateLimiter::availableIn($hourlyKey),
            ], 429);
        }

        $user = $this->findActiveUser($email);

        if ($user) {
            $code = $this->codes->issue($user, OtpCode::PURPOSE_PASSWORD_RESET, self::VALID_MINUTES);

            Mail::to($user->email)->send(new PasswordResetCodeMail($user, $code, self::VALID_MINUTES));
        }

        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($hourlyKey, 3600);

        return response()->json([
            'message' => 'If an account uses that email, a reset code is on its way.',
            'expires_in' => self::VALID_MINUTES * 60,
            'resend_after' => self::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string'],
        ]);

        [, $error] = $this->checkCode($validated['email'], $validated['code']);

        return $error ?? response()->json(['verified' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string'],
            // Same rule as /api/register, which mirrors BcryptUtil.isStrong
            // in the Flutter app.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        [$otp, $error] = $this->checkCode($validated['email'], $validated['code']);
        if ($error) {
            return $error;
        }

        DB::transaction(function () use ($otp, $validated) {
            $otp->update(['consumed_at' => now()]);

            $user = $otp->user;
            $user->forceFill(['password' => Hash::make($validated['password'])])->save();

            // Whoever knew the old password may still hold a session on
            // another device — a reset signs every device out.
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Your password has been reset. Sign in with your new password.',
        ]);
    }

    /**
     * @return array{0: ?OtpCode, 1: ?JsonResponse}
     */
    private function checkCode(string $email, string $code): array
    {
        $user = $this->findActiveUser(mb_strtolower(trim($email)));
        $result = $this->codes->check($user, OtpCode::PURPOSE_PASSWORD_RESET, $code);

        return match ($result['status']) {
            EmailCodeService::OK => [$result['otp'], null],
            EmailCodeService::WRONG => [null, response()->json([
                'message' => 'Incorrect code.',
                'attempts_remaining' => $result['remaining'],
            ], 422)],
            EmailCodeService::LOCKED => [null, response()->json([
                'message' => 'Too many attempts. Request a new code.',
            ], 429)],
            default => [null, response()->json([
                'message' => 'That code has expired. Request a new one.',
            ], 410)],
        };
    }

    private function findActiveUser(string $email): ?User
    {
        return User::where('email', $email)->where('is_active', true)->first();
    }
}
