<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'error' => session('error'),
            'info' => session('info'),
            'googleConfigured' => $this->googleConfigured(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();
        
        if ($user) {
            $user->updateStatus('online');
        }

        $landingPage = 'dashboard';

        // Check if user has a role with a specific landing page
        if ($user) {
            $role = $user->roles()->whereNotNull('landing_page')->first();
            if ($role) {
                $landingPage = $role->landing_page;
            }
        }

        // Validate that the route exists, otherwise default to dashboard
        try {
            $url = route($landingPage, absolute: false);
        } catch (\Exception $e) {
            $landingPage = 'dashboard';
        }

        return redirect()->intended(route($landingPage, absolute: false));
    }

    private function googleConfigured(): bool
    {
        return collect([
            config('services.google.client_id'),
            config('services.google.client_secret'),
            config('services.google.redirect'),
        ])->every(fn ($value) => filled($value));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user) {
            $user->updateStatus('offline');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
