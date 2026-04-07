<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            // Throttle presence writes to once per 60 seconds per user.
            // Without this, every navigation triggers a DB write.
            $throttleKey = 'presence_throttle_' . $user->id;

            if (!Cache::has($throttleKey)) {
                if ($user->status === 'offline') {
                    $user->updateStatus('online');
                } else {
                    $user->update(['last_activity_at' => now()]);
                }
                Cache::put($throttleKey, true, 60);
            }
        }

        return $next($request);
    }
}