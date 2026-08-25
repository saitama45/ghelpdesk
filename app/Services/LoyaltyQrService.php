<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Config;

/**
 * Encodes/decodes the static, signed member code shown as a QR on a
 * customer's Loyalty Stamps card. Deliberately NOT a rotating/expiring
 * token — this is a membership code (like a physical loyalty card barcode),
 * not a one-time payment authorization, so nothing sensitive is exposed by
 * it staying valid: the worst case of it leaking is someone else's stamp
 * gets added to the rightful owner's own card. The real access boundary is
 * that only staff authenticated into ghelpdesk with `stamps.create` can
 * scan one in (see StampController::resolveScan / scanAddStamp).
 *
 * Format: "LCARD1:{customer_id}:{signature}" — a compact, barcode/QR-safe
 * string. The signature is a truncated HMAC-SHA256 keyed on the app key, so
 * ghelpdesk can validate a scanned code without a DB round trip before
 * looking up the customer.
 */
class LoyaltyQrService
{
    private const PREFIX = 'LCARD1';

    public static function encode(Customer|int $customer): string
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return self::PREFIX . ':' . $id . ':' . self::sign($id);
    }

    /**
     * Returns the customer id if the payload is well-formed and the
     * signature checks out, otherwise null.
     */
    public static function decode(string $payload): ?int
    {
        $payload = trim($payload);
        $parts = explode(':', $payload);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        [, $rawId, $signature] = $parts;

        if (! ctype_digit($rawId)) {
            return null;
        }

        $id = (int) $rawId;

        return hash_equals(self::sign($id), $signature) ? $id : null;
    }

    private static function sign(int $customerId): string
    {
        $secret = Config::get('app.key');

        return substr(hash_hmac('sha256', self::PREFIX . ':' . $customerId, $secret), 0, 24);
    }
}
