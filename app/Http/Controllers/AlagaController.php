<?php

namespace App\Http\Controllers;

use App\Models\AlagaAssessment;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * ALAGA store IT-asset assessments (LINK Hub "ALAGA Asset Assessment"): a TAS-led
 * per-store scorecard on a /4.0 scale — equipment category scores + an inspection
 * checklist + observations/recommendations, with a "Create ALAGA Request" flow.
 */
class AlagaController extends Controller implements HasMiddleware
{
    /**
     * Asset-master categories ALAGA actually assesses. The checklist scores device
     * condition (display, boot time, software version, disk space), so consumables,
     * supplies, uniforms and loose materials are deliberately excluded — a store's
     * stock of tumblers is inventory, not assessable equipment.
     */
    public const ASSESSABLE_CATEGORIES = [
        'ASSET-IT Equipment',
        'ASSET-Network Equipment',
        'ASSET-Peripherals & Input Devices',
        'ASSET-Printing & Labeling',
        'ASSET-Power & Electrical',
        'ASSET-Security & Access Control',
        'CCTV',
    ];

    /** Default inspection checklist (parameter → standard). Scored /4 each. */
    public const CHECKLIST = [
        ['parameter' => 'Clean display / screen', 'standard' => 'Display is clear, clean, and functional'],
        ['parameter' => 'Stable casing and ports', 'standard' => 'No cracks, dents, or loose connections'],
        ['parameter' => 'Functional controls', 'standard' => 'Buttons and peripherals respond properly'],
        ['parameter' => 'Fast system boot', 'standard' => 'Operational within one minute'],
        ['parameter' => 'Updated software', 'standard' => 'Approved application version installed'],
        ['parameter' => 'Organized cables', 'standard' => 'Connected, secured, and obstruction-free'],
        ['parameter' => 'Sufficient disk space', 'standard' => 'Minimum free-space standard maintained'],
        ['parameter' => 'Stable operation', 'standard' => 'No freezing, lagging, or unexpected shutdown'],
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:alaga.view', only: ['index', 'storeAssets']),
            new Middleware('can:alaga.create', only: ['store']),
        ];
    }

    /**
     * The equipment actually deployed at a store, taken from real inventory:
     * StockIn units (physical, serial-tracked) resolved through the Asset master
     * for their description/category. `stock_ins.destination_location` holds the
     * store CODE — that is the only link between a unit and a store.
     *
     * Returns [] when the store has no assessable posted units — the UI then shows
     * an empty state and blocks the assessment. We deliberately do NOT substitute a
     * generic equipment list: scoring equipment that inventory has no record of
     * would invent data.
     */
    public function storeAssets(Request $request, Store $store)
    {
        $rows = StockIn::query()
            ->where('destination_location', $store->code)
            ->where('status', 'Posted')
            ->with(['asset:id,item_code,description,category_id', 'asset.category:id,name'])
            ->whereHas('asset.category', fn ($q) => $q->whereIn('name', self::ASSESSABLE_CATEGORIES))
            ->orderBy('asset_id')
            ->get();

        $assets = $rows
            ->filter(fn (StockIn $s) => $s->asset)
            ->groupBy('asset_id')
            ->map(function ($group) {
                /** @var StockIn $first */
                $first = $group->first();
                $serials = $group->pluck('serial_no')->filter()->values();

                return [
                    'asset_id' => $first->asset_id,
                    'category' => $first->asset->description ?: $first->asset->item_code,
                    'item_code' => $first->asset->item_code,
                    'group' => str_replace('ASSET-', '', (string) $first->asset->category?->name),
                    'units' => (int) $group->sum(fn (StockIn $s) => max(1, (int) $s->quantity)),
                    'serial_no' => $serials->implode(', ') ?: null,
                    'source' => 'inventory',
                ];
            })
            ->values();

        return response()->json([
            'store' => ['id' => $store->id, 'name' => $store->name, 'code' => $store->code],
            'assets' => $assets,
        ]);
    }

    public function index(Request $request)
    {
        $companyIds = CompanyContext::accessibleCompanyIds($request->user());

        $assessments = AlagaAssessment::query()
            ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->with(['store:id,name,code,company_id,class', 'store.company:id,code,name', 'inspector:id,name'])
            ->orderByDesc('assessment_date')
            ->get()
            ->map(fn (AlagaAssessment $a) => $this->present($a));

        $average = $assessments->count() ? round($assessments->avg('overall_score'), 2) : 0;

        return Inertia::render('Alaga/Index', [
            'assessments' => $assessments->values(),
            'average' => $average,
            'assessedCount' => $assessments->count(),
            'canCreate' => (bool) $request->user()->can('alaga.create'),
            'stores' => Store::query()
                ->when($companyIds, fn ($q) => $q->whereIn('company_id', $companyIds))
                ->where('is_active', true)
                ->with('company:id,code')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'company_id', 'class'])
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'brand' => $s->company?->code, 'class' => $s->class]),
            'inspectors' => User::active()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'checklist' => self::CHECKLIST,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'inspector_id' => ['nullable', 'integer', 'exists:users,id'],
            'assessment_date' => ['required', 'date'],
            'next_review' => ['nullable', 'date', 'after_or_equal:assessment_date'],
            'asset_scores' => ['required', 'array', 'min:1'],
            'asset_scores.*.category' => ['required', 'string', 'max:150'],
            'asset_scores.*.score' => ['required', 'numeric', 'between:0,4'],
            // Provenance when the row came from real inventory (StockIn/Asset).
            'asset_scores.*.asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'asset_scores.*.item_code' => ['nullable', 'string', 'max:100'],
            'asset_scores.*.group' => ['nullable', 'string', 'max:100'],
            'asset_scores.*.units' => ['nullable', 'integer', 'min:0'],
            'asset_scores.*.serial_no' => ['nullable', 'string', 'max:255'],
            'asset_scores.*.source' => ['nullable', 'string', 'in:inventory,standard'],
            'checklist' => ['nullable', 'array'],
            'checklist.*.parameter' => ['required', 'string', 'max:150'],
            'checklist.*.standard' => ['nullable', 'string', 'max:255'],
            'checklist.*.finding' => ['nullable', 'string', 'max:500'],
            'checklist.*.score' => ['nullable', 'numeric', 'between:0,4'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
        ]);

        $store = Store::findOrFail($validated['store_id']);
        $scores = collect($validated['asset_scores']);
        $overall = round($scores->avg('score'), 2);

        AlagaAssessment::create([
            'store_id' => $store->id,
            'inspector_id' => $validated['inspector_id'] ?? $request->user()->id,
            'company_id' => $store->company_id,
            'assessment_date' => $validated['assessment_date'],
            'overall_score' => $overall,
            'status' => AlagaAssessment::statusForScore($overall),
            'asset_scores' => $scores->map(fn ($s) => array_filter([
                'category' => $s['category'],
                'score' => (float) $s['score'],
                'asset_id' => $s['asset_id'] ?? null,
                'item_code' => $s['item_code'] ?? null,
                'group' => $s['group'] ?? null,
                'units' => $s['units'] ?? null,
                'serial_no' => $s['serial_no'] ?? null,
                'source' => $s['source'] ?? 'standard',
            ], fn ($v) => $v !== null))->all(),
            'checklist' => $validated['checklist'] ?? self::CHECKLIST,
            'observations' => $validated['observations'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'next_review' => $validated['next_review'] ?? Carbon::parse($validated['assessment_date'])->addMonths(4)->format('Y-m-d'),
            'workflow_status' => 'Completed',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('alaga.index')->with('success', "ALAGA assessment recorded for {$store->name}.");
    }

    private function present(AlagaAssessment $a): array
    {
        return [
            'id' => $a->id,
            'store' => $a->store?->name,
            'store_code' => $a->store?->code,
            'brand' => $a->store?->company?->code,
            'class' => $a->store?->class,
            'location' => $a->store?->company?->name,
            'inspector' => $a->inspector?->name,
            'assessment_date' => $a->assessment_date?->format('M d, Y'),
            'next_review' => $a->next_review?->format('M d, Y'),
            'overall_score' => (float) $a->overall_score,
            'status' => $a->status,
            'asset_scores' => $a->asset_scores ?? [],
            'checklist' => $a->checklist ?? [],
            'observations' => $a->observations,
            'recommendations' => $a->recommendations,
            'workflow_status' => $a->workflow_status,
        ];
    }
}
