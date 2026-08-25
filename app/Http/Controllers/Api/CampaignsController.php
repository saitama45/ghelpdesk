<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\StampProgram;
use Illuminate\Http\Request;

/**
 * Read-only campaign catalogue for the mobile loyalty app (bms /
 * "Coffee Bean & Tea Leaf", a separate Flutter app — not the ghelpdesk
 * helpdesk itself).
 *
 * Source of truth is `stamp_programs`, the same table the staff-facing
 * Stamps module (`StampController`) manages. The mobile app never writes
 * here — a member's stamp progress stays local to their device (see the
 * Flutter app's `docs/knowledge/Database.md`); this endpoint only mirrors
 * the campaign *definitions* down.
 *
 * Unlike the staff Stamps module, this route sits behind plain
 * `auth:sanctum` (any signed-in member), not the `stamps.*` staff
 * permissions — a member has no role in ghelpdesk's RBAC at all.
 *
 * **Strictly scoped to the CBTL entity, unlike the admin Programs tab.** A
 * program with no `company_id` assigned yet does NOT appear here (unlike the
 * admin UI, which still shows unassigned programs everywhere for backward
 * compatibility) — this is the one app that must never show another
 * entity's campaigns, so "not yet assigned" has to mean "not visible", not
 * "visible everywhere by default". If a real campaign is missing from the
 * app, check whether its Company field is set on the Programs tab.
 *
 * `StampProgram` is registered in `CompanyContext::SCOPED_MODELS`, so
 * `ActiveEntityScope` is already applied globally (AppServiceProvider) —
 * every query here explicitly bypasses it and applies its own CBTL filter
 * instead. Left in place, the global scope would silently AND itself onto
 * this query using whatever "active entity" happens to be resolved for the
 * authenticated member's session (a staff `users` row's default, since
 * mobile sign-in reuses `/api/login` today — see the "Known gap" note in
 * this app's docs) rather than CBTL, which is very unlikely to be what this
 * endpoint should return.
 */
class CampaignsController extends Controller
{
    private const ENTITY_CODE = 'CBTL';

    public function index(Request $request)
    {
        $companyId = Company::where('code', self::ENTITY_CODE)->value('id');

        $programs = StampProgram::withoutGlobalScope(ActiveEntityScope::class)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'campaigns' => $programs->map(fn (StampProgram $p) => $this->toCampaign($p))->values(),
        ]);
    }

    private function toCampaign(StampProgram $program): array
    {
        return [
            // Stable sync key the app upserts local rows on — derived, not
            // stored, so no backfill was needed for the 3 campaigns already
            // live when this endpoint shipped.
            'code' => "SP-{$program->id}",
            'name' => $program->name,
            'description' => $program->description,
            'emoji' => $program->emoji,
            'tag' => $program->tag,
            'required_stamps' => $program->stamps_required,
            'eligible_items_description' => $program->eligible_items_description,
            'reward_description' => $program->reward_description,
            'terms_and_conditions' => $program->terms_and_conditions,
            'starts_at' => $program->starts_at?->toIso8601String(),
            'ends_at' => $program->ends_at?->toIso8601String(),
            'is_active' => (bool) $program->is_active,
            'display_order' => $program->display_order,
            'updated_at' => $program->updated_at?->toIso8601String(),
        ];
    }
}
