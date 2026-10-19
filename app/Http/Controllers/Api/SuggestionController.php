<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerSuggestion;
use Illuminate\Http\Request;

/**
 * The mobile loyalty app's Suggestion Box (Profile → Suggestion Box,
 * `SuggestionRemoteDatasource`). Staff read what arrives on /stamps →
 * Suggestions, behind the ordinary `stamps.view` permission.
 *
 * Same `auth:sanctum` boundary as the other member endpoints and no `stamps.*`
 * permission, for the reason LoyaltyMemberController gives: a member has no
 * role in ghelpdesk's RBAC at all.
 */
class SuggestionController extends Controller
{
    /** Matches CampaignsController::ENTITY_CODE — the app belongs to CBTL. */
    private const ENTITY_CODE = 'CBTL';

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $suggestion = CustomerSuggestion::create([
            // Null for a staff login trying the app; the list then falls back
            // to the login's own name.
            'customer_id' => $user->customer_id,
            'user_id' => $user->id,
            'company_id' => Company::where('code', self::ENTITY_CODE)->value('id'),
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
        ]);

        return response()->json([
            'id' => $suggestion->id,
            'message' => 'Thank you! Your message has been sent.',
        ], 201);
    }
}
