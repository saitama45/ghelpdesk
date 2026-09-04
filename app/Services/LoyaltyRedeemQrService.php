<?php

namespace App\Services;

use App\Models\StampCard;
use Illuminate\Support\Facades\Config;

/**
 * Encodes/decodes the signed redemption code a member's mobile app shows when
 * they tap "Redeem Now", and that store staff scan on the Stamps module's
 * "Scan Redeem QR" flow (StampController::resolveRedeemScan).
 *
 * Deliberately mirrors LoyaltyQrService rather than inventing a second
 * scheme — same truncated-HMAC shape, same barcode/QR-safe formatting, same
 * "validate before touching the database" property. The differences are
 * intentional:
 *
 *  - it is keyed on a STAMP CARD, not a customer, so one code authorizes one
 *    specific full card rather than standing in for the member generally;
 *  - it therefore stops being usable the moment that card's status leaves
 *    `completed` — the card, not the code, carries the "already redeemed"
 *    state, so a screenshotted code cannot be redeemed twice (the second
 *    scan resolves to a card whose status is now `redeemed` and is refused).
 *
 * Like the member code it does not expire on a clock. That is what lets the
 * mobile app cache it and display it with no connectivity (the app receives
 * it as part of the ordinary `/api/loyalty/my-cards` progress pull rather
 * than fetching it on demand at the counter, where signal is least reliable).
 * The real access boundary is unchanged: only staff authenticated into
 * ghelpdesk with `stamps.redeem` can act on a scanned code, and redemption
 * still deducts specific inventory units through the normal picker.
 *
 * Format: "LRDM1:{stamp_card_id}:{signature}"
 */
class LoyaltyRedeemQrService
{
    private const PREFIX = 'LRDM1';

    public static function encode(StampCard|int $card): string
    {
        $id = $card instanceof StampCard ? $card->id : $card;

        return self::PREFIX . ':' . $id . ':' . self::sign($id);
    }

    /**
     * Returns the stamp card id if the payload is well-formed and the
     * signature checks out, otherwise null. Says nothing about whether that
     * card is still redeemable — that is a database question, answered by
     * the caller.
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

    private static function sign(int $cardId): string
    {
        $secret = Config::get('app.key');

        return substr(hash_hmac('sha256', self::PREFIX . ':' . $cardId, $secret), 0, 24);
    }
}
