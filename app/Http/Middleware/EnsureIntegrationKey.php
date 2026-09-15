<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server authentication for sibling apps (e.g. DAVID).
 *
 * The caller sends `X-Integration-Key`; it must match the key configured for
 * the named integration in config/services.php. An unconfigured key rejects
 * every request, so a missing env var can never leave the endpoint open.
 */
class EnsureIntegrationKey
{
    public function handle(Request $request, Closure $next, string $integration): Response
    {
        $expected = (string) config("services.integrations.{$integration}.key");
        $given = (string) $request->header('X-Integration-Key');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
