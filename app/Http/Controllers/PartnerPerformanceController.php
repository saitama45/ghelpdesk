<?php

namespace App\Http\Controllers;

use App\Services\PartnerPerformanceService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

/**
 * Drill-down behind the dashboard's Partner Performance tab: the actual escalation
 * child tickets sitting under any partner / brand / state cell the user clicks.
 */
class PartnerPerformanceController extends Controller
{
    public function __construct(private PartnerPerformanceService $partnerPerformance) {}

    public function tickets(Request $request)
    {
        abort_unless($request->user()->can('tickets.view'), 403);

        $validated = $request->validate([
            'vendor_id' => ['nullable', 'integer'],
            // 'none' means escalations with no store (no owning company) — a real
            // bucket on the tab, distinct from "no brand filter".
            'brand_id' => ['nullable', 'string'],
            'state' => ['nullable', 'in:all,open,closed,aging,breached'],
            'year' => ['nullable', 'integer'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
        ]);

        // Resolved through CompanyContext exactly like the dashboard build, so the
        // requested ids can only ever narrow the caller's own accessible entities.
        $effectiveCompanyIds = CompanyContext::effectiveEntityIds(
            $request->user(),
            (array) $request->input('entity_ids', []),
            $request->user()->can('dashboard.filter_entity')
        );

        return response()->json(
            $this->partnerPerformance->tickets($request->user(), $validated, $effectiveCompanyIds)
        );
    }
}
