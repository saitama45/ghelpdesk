<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a hand-drawn sign-off signature.
 *
 * The signature pad posts a PNG data URL ("data:image/png;base64,...") rather
 * than a file upload, because the drawing only ever exists in a canvas — there is
 * no file for a multipart form to attach. This decodes it, checks it really is a
 * PNG, and writes it to the public disk like any other uploaded image.
 *
 * It is deliberately NOT stored as the data URL itself. A base64 PNG in an
 * nvarchar(MAX) column is roughly a third larger than the file, and every listing
 * that selected the row would drag it across the link — the same problem that
 * made ticket descriptions expensive to query.
 */
class SignatureImage
{
    /** Generous for a signature (a full-width pad is ~30 KB), mean for an upload. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** The validation rule for the posted field. */
    public static function rules(bool $required = false): array
    {
        return [
            'signature' => ($required ? 'required' : 'nullable').'|string|max:3000000',
        ];
    }

    /**
     * Decodes a PNG data URL and stores it, returning the disk path.
     *
     * @param  string  $directory  e.g. "signatures/qat/12"
     * @return string|null null when nothing was drawn
     *
     * @throws ValidationException when the payload is not a usable PNG
     */
    public static function store(?string $dataUrl, string $directory, string $field = 'signature'): ?string
    {
        $dataUrl = trim((string) $dataUrl);

        if ($dataUrl === '') {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,(?<data>[A-Za-z0-9+/=\s]+)$#', $dataUrl, $matches)) {
            throw ValidationException::withMessages([
                $field => 'The signature could not be read. Please draw it again.',
            ]);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $matches['data']), true);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                $field => 'The signature could not be read. Please draw it again.',
            ]);
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                $field => 'That signature image is too large.',
            ]);
        }

        // Trust the bytes, not the declared mime type: the data URL prefix is
        // attacker-controlled, and this file is served back to browsers.
        if (! str_starts_with($binary, "\x89PNG\x0d\x0a\x1a\x0a")) {
            throw ValidationException::withMessages([
                $field => 'The signature must be a PNG image.',
            ]);
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.png';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /**
     * Origin-relative URL for a stored signature.
     *
     * Storage::url() builds from APP_URL, which is routinely wrong in dev and
     * behind a proxy; a path always resolves against the host that served the page.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * The image as a base64 data URI, for embedding in a PDF.
     *
     * dompdf resolves <img src> against its own chroot and cannot fetch an app URL
     * (there is no HTTP request in play, and enabling remote fetching to reach our
     * own server would be both slow and a small SSRF surface). Inlining the bytes
     * sidesteps the question entirely and is why the signature always renders.
     */
    public static function dataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
