<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppReviewAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle a login request for the mobile app.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact support.'],
            ]);
        }

        $deviceName = $request->device_name ?: 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_photo' => $user->profile_photo,
            ],
            'roles' => $user->getRoleNames(),
            'otp_required' => self::otpRequiredFor($user),
        ]);
    }

    /**
     * Whether the mobile app must run its emailed-code step after this sign-in.
     *
     * False only for the store-review demo accounts (`AppReviewAccounts`).
     * Google Play rejects an app whose review login stops at a code screen,
     * even when the review notes supply a fixed code (rejected 2026-11-25):
     * its reviewers must get in with the email and password alone. The app
     * skips the code step when this is false. Builds from before the flag
     * ignore it and still receive the fixed `APP_REVIEW_OTP` from
     * `Api\OtpController`.
     *
     * `Api\RegisterController` sends it too, so a review account that signs up
     * again after the deletion test skips the step as well.
     */
    public static function otpRequiredFor(User $user): bool
    {
        return ! AppReviewAccounts::includes($user->email);
    }

    /**
     * Log the user out (revoke token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out and token revoked.'
        ]);
    }
}
