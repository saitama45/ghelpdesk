<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // If user was offline or status changed, we'll update it
            // But usually we just update last_activity_at here
            // If user status is offline, and they make a request, mark them as online
            if ($user->status === 'offline') {
                $user->updateStatus('online');
            } else {
                $user->update(['last_activity_at' => now()]);
            }
        }

        return $next($request);
    }
}