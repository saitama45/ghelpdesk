<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::firstOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'name'              => $googleUser->getName(),
                'email'             => $googleUser->getEmail(),
                'google_id'         => $googleUser->getId(),
                'email_verified_at' => now(),
                'password'          => Str::random(32),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->assignRole('User');
        }

        Auth::login($user, true);

        return redirect()->intended(route('dashboard'));
    }
}
