<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service registration for the mobile loyalty app (bms /
 * "Coffee Bean & Tea Leaf") — not staff registration (see the separate
 * web `Auth\RegisteredUserController`, which this deliberately does not
 * touch or reuse).
 *
 * Creates two rows in one transaction:
 *  - a `customers` row (so the member shows up in the Stamps module's
 *    Customers tab exactly like a staff-entered walk-in customer would)
 *  - a `users` row with no role assigned, linked to it via `customer_id`,
 *    so the member can authenticate through the exact same `/api/login`
 *    (and OTP, and offline bcrypt fallback) the mobile app already has
 *    fully built for staff accounts. A roleless user has zero rows in
 *    Spatie's `model_has_roles`, so they don't appear in ordinary
 *    role-filtered staff listings.
 *
 * If a `customers` row with this email already exists (e.g. staff added
 * them manually as a walk-in before they ever installed the app), that
 * row is reused and updated rather than duplicated.
 */
class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            // Mirrors BcryptUtil.isStrong in the Flutter app so the same
            // password is valid (or rejected) on both sides.
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'device_name' => 'nullable|string',
        ]);

        [$token, $user] = DB::transaction(function () use ($validated) {
            $customer = Customer::where('email', $validated['email'])->first()
                ?? new Customer();
            $customer->name = $validated['name'];
            $customer->email = $validated['email'];
            if (!empty($validated['phone'])) {
                $customer->phone = $validated['phone'];
            }
            $customer->is_active = true;
            $customer->save();

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'customer_id' => $customer->id,
                'is_active' => true,
            ]);
            $user->forceFill(['created_by' => $user->id, 'updated_by' => $user->id])->save();

            if (!$customer->created_by) {
                $customer->forceFill(['created_by' => $user->id, 'updated_by' => $user->id])->save();
            }

            $deviceName = $validated['device_name'] ?: 'mobile-app';
            $token = $user->createToken($deviceName)->plainTextToken;

            return [$token, $user];
        });

        // Same response shape as /api/login — the Flutter client's existing
        // success-handling code path needs no changes to consume this.
        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_photo' => $user->profile_photo,
            ],
            'roles' => $user->getRoleNames(),
        ], 201);
    }
}
