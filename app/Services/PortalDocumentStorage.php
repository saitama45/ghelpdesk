<?php

namespace App\Services;

use App\Models\VendorDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a vendor document's bytes from the portal that stored them.
 *
 * The row is in the shared database, the file is not: linkportal writes it to
 * its own `public` disk (`public/storage/portal/vendors/{id}/documents/...`).
 * Two deployments, two answers, so this tries both:
 *
 *  1. `services.linkportal.documents_root` — an absolute path to the portal's
 *     public storage. Set it when both apps sit on one filesystem (local dev,
 *     or a shared Azure Files mount); reading the file directly is cheapest.
 *  2. `services.linkportal.base_url` — a server-side GET of the portal's public
 *     `/storage/...` URL. This is the path that works when the two apps are
 *     separate App Services with separate disks.
 *
 * Either way the bytes reach the browser through the back office's own
 * permission-gated route, never a link to the portal.
 */
class PortalDocumentStorage
{
    /** A document that large is streamed from disk but refused over HTTP. */
    private const MAX_PROXY_BYTES = 25 * 1024 * 1024;

    /** Absolute path to the file on a shared filesystem, when it is there. */
    public function localPath(VendorDocument $document): ?string
    {
        $root = config('services.linkportal.documents_root');

        if (! $root || ! $document->file_path) {
            return null;
        }

        // The stored path is portal-relative and always uses forward slashes.
        $relative = ltrim(str_replace('\\', '/', $document->file_path), '/');

        // Traversal guard: a crafted row must not be able to walk out of the
        // portal's storage root and read arbitrary files off the server.
        if (str_contains($relative, '..')) {
            return null;
        }

        $path = rtrim(str_replace('\\', '/', $root), '/') . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /** The portal's public URL for the file, for the HTTP fallback. */
    public function remoteUrl(VendorDocument $document): ?string
    {
        $baseUrl = config('services.linkportal.base_url');

        if (! $baseUrl || ! $document->file_path) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $document->file_path), '/');

        if (str_contains($relative, '..')) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/storage/' . $relative;
    }

    /**
     * The file's bytes, or null when neither route can produce them (portal
     * unreachable, file deleted, nothing configured). Callers 404 on null.
     */
    public function contents(VendorDocument $document): ?string
    {
        $path = $this->localPath($document);

        if ($path !== null) {
            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        $url = $this->remoteUrl($document);

        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Vendor document fetch failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Vendor document fetch returned an error', [
                'document_id' => $document->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->body();

        // Guard the back office's memory: the portal is the right place to open
        // an unusually large file from.
        return strlen($body) > self::MAX_PROXY_BYTES ? null : $body;
    }

    /** True when the file can actually be produced — drives the UI's actions. */
    public function isAvailable(VendorDocument $document): bool
    {
        return $this->localPath($document) !== null || $this->remoteUrl($document) !== null;
    }
}
