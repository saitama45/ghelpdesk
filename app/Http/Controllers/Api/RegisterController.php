<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\AccountArchiveService;
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
 *
 * An email whose account or customer record is archived is refused until the
 * retention purge removes it — see `closedAccountExists()`.
 */
class RegisterController extends Controller
{
    public const CLOSED_ACCOUNT_MESSAGE = 'This email belongs to an account that was closed. Sign up with a different email, or contact support to reopen it.';

    public function __construct(private AccountArchiveService $archive) {}

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // `bail` so the closure only ever sees a well-formed string, and the
            // closed-account check runs before `unique` so an archived login
            // still holding its real address (archived before `archived_email`
            // existed) is told the same thing, not "already been taken".
            'email' => [
                'bail', 'required', 'string', 'lowercase', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->closedAccountExists($value)) {
                        $fail(self::CLOSED_ACCOUNT_MESSAGE);
                    }
                },
                'unique:users,email',
            ],
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

            // `device_name` is nullable, so `validated()` omits the key entirely
            // when a client leaves it out — reading it directly raised an
            // "Undefined array key" 500 on an unauthenticated public endpoint.
            // Same defensive read `Api\AuthController::login` already uses.
            $deviceName = $validated['device_name'] ?? null ?: 'mobile-app';
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

    /**
     * Whether an archived login, or an archived walk-in customer record with no
     * live twin, owns this address.
     *
     * Archiving parks a login's address in `archived_email` and tombstones
     * `email` (`AccountArchiveService::releaseEmail`), which on its own would
     * let the address register again at once. That let a member staff had
     * closed sign straight back up, and left the archived account unable to
     * reclaim its address if it was restored. So the address stays refused
     * while it is archived; `accounts:purge-expired` purges or anonymizes it
     * once retention passes, and the address is free again after that.
     *
     * The store-review demo account (`services.app_review.email`) is exempt: a
     * reviewer tests 5.1.1(v) by deleting it and signing up again.
     */
    private function closedAccountExists(string $email): bool
    {
        $reviewEmail = config('services.app_review.email');
        if ($reviewEmail && strcasecmp($reviewEmail, $email) === 0) {
            return false;
        }

        return $this->archive->archivedLoginHolding($email) !== null
            || $this->archive->archivedCustomerHolding($email) !== null;
    }
}
