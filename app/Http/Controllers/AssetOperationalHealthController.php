<?php

namespace App\Http\Controllers;

use App\Services\AssetOperationalHealthService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

/**
 * Drill-down behind the Asset Operational Health tab: the individual physical units
 * at one store, each with every active linked ticket.
 *
 * Entity scope is resolved server-side through CompanyContext, exactly like the
 * dashboard build, so a client-supplied store_id can only ever narrow the caller's
 * own accessible entities — never reach outside them.
 */
class AssetOperationalHealthController extends Controller
{
    public function __construct(
        private AssetOperationalHealthService $assetHealth,
    ) {}

    public function units(Request $request)
    {
        abort_unless($request->user()->can('stock_ins.view'), 403);

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'group' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:operational,impacted'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
        ]);

        $effectiveCompanyIds = CompanyContext::effectiveEntityIds(
            $request->user(),
            (array) $request->input('entity_ids', []),
            $request->user()->can('dashboard.filter_entity')
        );

        return response()->json($this->assetHealth->units(
            $effectiveCompanyIds,
            $validated['store_id'] ?? null,
            $validated['group'] ?? null,
            $validated['status'] ?? null,
        ));
    }
}
