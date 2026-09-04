<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampRedemption;
use App\Services\LoyaltyQrService;
use App\Services\LoyaltyRedeemQrService;
use Illuminate\Http\Request;

/**
 * Member-facing loyalty endpoints for the mobile app (bms / "TAS Service
 * Center", a separate Flutter app — not the ghelpdesk helpdesk itself).
 * Same `auth:sanctum` boundary as `/api/campaigns` — a member has no role in
 * ghelpdesk's RBAC at all, so this deliberately doesn't sit behind any
 * `stamps.*` permission.
 *
 * - `qrCard` issues the signed code the mobile "My Member Code" screen
 *   displays (`LoyaltyMemberRemoteDatasource`, `memberQrProvider`).
 * - `myCards` is the other direction: the real stamp progress, so a member's
 *   card visibly updates after ghelpdesk staff scan them in
 *   (`StampController::scanAddStamp`) — the mobile app's `SyncManager` pulls
 *   this alongside the campaign catalogue and upserts local progress by the
 *   same `code` the catalogue pull already keys on.
 * - `myTransactions` is the individual-event counterpart to `myCards`: every
 *   real `stamp_entries`/`stamp_redemptions` row for the member, mapped to
 *   the mobile app's ledger shape. `myCards` alone only updates the running
 *   count on a card — nothing populated the mobile History screen's actual
 *   transaction list until this existed (previously a documented, deferred
 *   gap in `SyncManager._pullProgress`'s doc comment).
 *
 * The staff side that CONSUMES a scanned code lives in StampController
 * (`resolveScan` / `scanAddStamp`), gated by the `stamps.*` permissions.
 */
class LoyaltyMemberController extends Controller
{
    /** Matches CampaignsController::ENTITY_CODE — see that class's doc
     *  comment for why this app is strictly scoped to one entity. Progress
     *  has to use the exact same scope as the catalogue pull, or a card tied
     *  to a program outside it would have no local campaign row to attach to. */
    private const ENTITY_CODE = 'CBTL';

    public function qrCard(Request $request)
    {
        $customer = $request->user()->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'This account is not linked to a loyalty member record.',
            ], 422);
        }

        return response()->json([
            'token' => LoyaltyQrService::encode($customer),
        ]);
    }

    public function myCards(Request $request)
    {
        $customer = $request->user()->customer;

        if (! $customer) {
            return response()->json(['cards' => []]);
        }

        $companyId = Company::where('code', self::ENTITY_CODE)->value('id');

        $cards = StampCard::where('customer_id', $customer->id)
            ->whereHas('program', function ($q) use ($companyId) {
                // StampProgram carries ActiveEntityScope (see
                // CompanyContext::SCOPED_MODELS); a whereHas subquery applies
                // it using whatever "active entity" happens to be resolved
                // for this roleless mobile user's session, not necessarily
                // CBTL — bypass it and apply the explicit CBTL filter
                // ourselves, exactly like CampaignsController does.
                $q->withoutGlobalScope(ActiveEntityScope::class)
                    ->when($companyId, fn ($q2) => $q2->where('company_id', $companyId), fn ($q2) => $q2->whereRaw('1 = 0'));
            })
            ->with(['program' => fn ($q) => $q->withoutGlobalScope(ActiveEntityScope::class)])
            ->get(['id', 'stamp_program_id', 'stamps_count', 'status', 'redeemed_at']);

        return response()->json([
            'cards' => $cards->map(fn (StampCard $c) => [
                // Same derivation as CampaignsController::toCampaign — the
                // stable sync key the app upserts local campaign rows on.
                'code' => "SP-{$c->stamp_program_id}",
                // The card's own identity. `code` alone is ambiguous as soon
                // as a member has more than one card for the same program —
                // an old redeemed one plus its successor, which is exactly
                // what every redemption produces. The app keys its local
                // rows on this so a closed card and the new one stay
                // separate instead of overwriting each other.
                'card_id' => $c->id,
                'stamps_count' => $c->stamps_count,
                'stamps_required' => $c->program->stamps_required,
                'status' => $c->status,
                'redeemed_at' => optional($c->redeemed_at)->toIso8601String(),
                // Only a completed card can be redeemed (StampController::redeem
                // re-checks that under a row lock), so only a completed card
                // carries a code. Issued as part of the ordinary progress
                // pull rather than from a separate on-demand endpoint, so the
                // app still has it in hand at the counter with no signal.
                'redeem_token' => $c->status === 'completed'
                    ? LoyaltyRedeemQrService::encode($c)
                    : null,
            ])->values(),
        ]);
    }

    public function myTransactions(Request $request)
    {
        $customer = $request->user()->customer;

        if (! $customer) {
            return response()->json(['transactions' => []]);
        }

        $companyId = Company::where('code', self::ENTITY_CODE)->value('id');

        // Same CBTL-only scoping as myCards — a card outside it has no
        // local campaign row to attach an entry to on the app side anyway.
        $cardIds = StampCard::where('customer_id', $customer->id)
            ->whereHas('program', function ($q) use ($companyId) {
                $q->withoutGlobalScope(ActiveEntityScope::class)
                    ->when($companyId, fn ($q2) => $q2->where('company_id', $companyId), fn ($q2) => $q2->whereRaw('1 = 0'));
            })
            ->pluck('id');

        $earns = StampEntry::whereIn('stamp_card_id', $cardIds)
            ->with(['card:id,stamp_program_id', 'store:id,code,name'])
            ->get()
            ->map(fn (StampEntry $e) => [
                'reference' => "SE-{$e->id}",
                'type' => 'earn',
                'points' => $e->quantity,
                'campaign_code' => "SP-{$e->card->stamp_program_id}",
                'product_name' => null,
                'store_name' => $e->store ? "{$e->store->code} — {$e->store->name}" : null,
                'occurred_at' => $e->created_at?->toIso8601String(),
            ]);

        $redemptions = StampRedemption::whereIn('stamp_card_id', $cardIds)
            ->with(['program:id,stamps_required', 'asset:id,item_code,brand,model,description'])
            ->get()
            ->map(fn (StampRedemption $r) => [
                'reference' => "SR-{$r->id}",
                'type' => 'redeem',
                // A redemption spends the whole card, not the reward item
                // count — matches the local redeemReward's own convention
                // (-campaign.requiredStamps), so totals stay consistent
                // whichever source a given row came from.
                'points' => -(int) ($r->program->stamps_required ?? 0),
                'campaign_code' => "SP-{$r->stamp_program_id}",
                'product_name' => $r->asset?->description ?: $r->asset?->brand,
                'store_name' => $r->location,
                'occurred_at' => $r->created_at?->toIso8601String(),
            ]);

        // Newest first, capped like the local getTransactions(limit: 100)
        // default — this is a history feed, not a full export.
        $transactions = $earns->concat($redemptions)
            ->sortByDesc('occurred_at')
            ->take(100)
            ->values();

        return response()->json(['transactions' => $transactions]);
    }
}
