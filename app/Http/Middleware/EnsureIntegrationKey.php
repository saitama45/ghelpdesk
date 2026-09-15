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
        // Trimmed: a stray space or newline pasted into an app setting is the
        // most common cause of a "mismatch" between two identical keys.
        $expected = trim((string) config("services.integrations.{$integration}.key"));
        $given = trim((string) $request->header('X-Integration-Key'));

        // Distinct messages so the caller's UI can tell a missing server-side
        // key apart from a mismatched one; neither reveals the key itself.
        if ($expected === '') {
            return response()->json(['message' => 'Integration key is not configured on Helpdesk.'], 401);
        }

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Integration key does not match Helpdesk.'], 401);
        }

        return $next($request);
    }
}
