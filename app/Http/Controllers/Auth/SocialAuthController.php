<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\GoogleRegistrationPending;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        if (! $this->googleConfigured()) {
            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please contact the administrator.');
        }

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        if (! $this->googleConfigured()) {
            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please contact the administrator.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in could not be completed. Please try again or contact the administrator.');
        }

        if (blank($googleUser->getEmail())) {
            return redirect()
                ->route('login')
                ->with('error', 'Google did not return an email address for this account.');
        }

        $user = User::where('google_id', $googleUser->getId())->first();
        $wasRecentlyCreated = false;

        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user && filled($user->google_id) && $user->google_id !== $googleUser->getId()) {
                return redirect()
                    ->route('login')
                    ->with('error', 'This email address is already linked to a different Google account.');
            }

            if ($user) {
                $user->forceFill([
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            } else {
                $user = User::create([
                    'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                    'password' => Str::random(32),
                    'is_active' => false,
                ]);

                $user->forceFill([
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ])->save();

                $wasRecentlyCreated = true;
            }
        }

        if ($wasRecentlyCreated) {
            $this->notifyAdminsOfPendingRegistration($user);
        }

        if (! $this->canSignIn($user)) {
            return redirect()
                ->route('login')
                ->with('info', 'Your Google registration was received. Please wait for an administrator to approve your account.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $user->updateStatus('online');

        return $this->redirectAfterLogin($user);
    }

    private function googleConfigured(): bool
    {
        return collect([
            config('services.google.client_id'),
            config('services.google.client_secret'),
            config('services.google.redirect'),
        ])->every(fn ($value) => filled($value));
    }

    private function canSignIn(User $user): bool
    {
        return (bool) $user->is_active && $user->roles()->exists();
    }

    private function notifyAdminsOfPendingRegistration(User $user): void
    {
        $admins = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('roles', fn ($query) => $query->where('notify_on_user_registration', true))
            ->get()
            ->unique('email');

        $adminIds = $admins->pluck('id')->all();
        if (!empty($adminIds)) {
            app(\App\Services\NotificationService::class)->notifyApproval(
                $adminIds,
                $user->id,
                'pending',
                'New registration awaiting approval',
                trim(($user->name ?? 'A new user') . ' registered via Google and needs account approval.'),
                route('users.index', [], false) . '?status=pending_approval',
                'user_registration:' . $user->id,
                'warning'
            );
        }

        try {
            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new GoogleRegistrationPending($user));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function redirectAfterLogin(User $user)
    {
        $landingPage = 'dashboard';
        $role = $user->roles()->whereNotNull('landing_page')->first();

        if ($role) {
            $landingPage = $role->landing_page;
        }

        try {
            route($landingPage, absolute: false);
        } catch (Throwable) {
            $landingPage = 'dashboard';
        }

        return redirect()->intended(route($landingPage, absolute: false));
    }
}
