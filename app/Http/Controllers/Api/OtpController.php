<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Server-issued email one-time codes for the mobile app's post-login
 * verification step.
 *
 * Both routes sit behind `auth:sanctum` — the caller already has a valid
 * session token from `/api/login`, so neither request needs to name the
 * member. See the client contract in the Flutter app's
 * `lib/data/datasources/remote/otp_remote_datasource.dart`, which this
 * mirrors exactly (status codes and response shapes).
 */
class OtpController extends Controller
{
    private const CODE_LENGTH = 6;
    private const VALID_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const HOURLY_SEND_LIMIT = 10;

    public function send(Request $request)
    {
        $user = $request->user();

        $cooldownKey = "otp-send-cooldown:{$user->id}";
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'retry_after' => RateLimiter::availableIn($cooldownKey),
            ], 429);
        }

        $hourlyKey = "otp-send-hourly:{$user->id}";
        if (RateLimiter::tooManyAttempts($hourlyKey, self::HOURLY_SEND_LIMIT)) {
            return response()->json([
                'message' => 'Too many code requests. Please try again later.',
                'retry_after' => RateLimiter::availableIn($hourlyKey),
            ], 429);
        }

        // Only the newest code a member requested is ever valid — issuing a
        // fresh one retires whatever was sent before it.
        OtpCode::where('user_id', $user->id)->whereNull('consumed_at')->delete();

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpCodeMail($user, $code, self::VALID_MINUTES));

        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($hourlyKey, 3600);

        return response()->json([
            'destination' => $this->maskEmail($user->email),
            'expires_in' => self::VALID_MINUTES * 60,
            'resend_after' => self::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        $otp = OtpCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $otp) {
            return response()->json([
                'message' => 'That code has expired. Request a new one.',
            ], 410);
        }

        if ($otp->isExpired()) {
            $otp->delete();

            return response()->json([
                'message' => 'That code has expired. Request a new one.',
            ], 410);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return response()->json([
                'message' => 'Too many attempts. Request a new code.',
            ], 429);
        }

        if (! Hash::check($request->string('code'), $otp->code_hash)) {
            $otp->increment('attempts');
            $remaining = max(0, self::MAX_ATTEMPTS - $otp->attempts);

            if ($remaining <= 0) {
                return response()->json([
                    'message' => 'Too many attempts. Request a new code.',
                ], 429);
            }

            return response()->json([
                'message' => 'Incorrect code.',
                'attempts_remaining' => $remaining,
            ], 422);
        }

        $otp->update(['consumed_at' => now()]);

        return response()->json(['verified' => true]);
    }

    /**
     * "j***@example.com" — enough for a member to recognise their own
     * inbox without echoing a full address back over the wire.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '') {
            return $email;
        }

        $visible = mb_substr($local, 0, 1);

        return $domain === '' ? "{$visible}***" : "{$visible}***@{$domain}";
    }
}
