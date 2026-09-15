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
            // Short SHA-256 fingerprints let an admin see WHICH side holds the
            // wrong value (compare against the fingerprint of the intended key)
            // without either key ever appearing in a response or a log.
            return response()->json([
                'message' => 'Integration key does not match Helpdesk.',
                'helpdesk_key_fingerprint' => self::fingerprint($expected),
                'received_key_fingerprint' => $given === '' ? null : self::fingerprint($given),
            ], 401);
        }

        return $next($request);
    }

    /** First 8 hex chars of SHA-256 plus the length, e.g. "3f9a1c07 (64 chars)". */
    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8).' ('.strlen($key).' chars)';
    }
}
