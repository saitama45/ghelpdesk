<?php

namespace App\Http\Controllers;

use App\Http\Services\WigsService;
use App\Models\User;
use App\Models\WigsPcf;
use App\Models\WigsPcfItem;
use App\Models\WigsPerformanceStandard;
use App\Models\WigsQuarterGuideline;
use App\Models\WigsTrackRating;
use App\Models\WigsTrackValue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * WIGS — Wildly Important Goals.
 *
 * Tabs: Yardstick (reference config) | PCF (commitment) | PAF (appraisal).
 * Records are scoped to the actor + their org subtree (see WigsService),
 * unless they hold wigs.manage_all.
 */
class WigsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:wigs.view', only: ['index', 'showPcf']),
            new Middleware('can:wigs.create', only: ['storePcf']),
            new Middleware('can:wigs.edit', only: ['updatePcf', 'gradePcf', 'confirmPcf']),
            new Middleware('can:wigs.delete', only: ['destroyPcf']),
            new Middleware('can:wigs.manage_yardstick', only: ['saveYardstick']),
        ];
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $user = $request->user();
        $year = (int) ($validated['year'] ?? now()->year);
        $viewableIds = WigsService::viewableUserIds($user);

        $pcfQuery = WigsPcf::query()
            ->with(['user:id,name,position,org_path', 'confirmer:id,name', 'items.scores'])
            ->where('year', $year);

        if ($viewableIds !== null) {
            $pcfQuery->whereIn('user_id', $viewableIds);
        }

        $pcfs = $pcfQuery->get()
            ->sortBy(fn (WigsPcf $p) => $p->user?->name)
            ->values()
            ->map(fn (WigsPcf $p) => $this->serializePcf($p));

        return Inertia::render('Wigs/Index', [
            'filters' => ['year' => $year],
            'yardstick' => $this->yardstickPayload(),
            'pcfs' => $pcfs,
            'quarterStatuses' => WigsService::quarterStatuses($year),
            'standardOptions' => WigsPerformanceStandard::where('is_active', true)
                ->orderBy('sort_order')->pluck('general')->values(),
            'valueOptions' => WigsTrackValue::where('is_active', true)
                ->orderBy('sort_order')->pluck('name')->values(),
            'selectableUsers' => WigsService::selectableUsers($user),
            'currentUserId' => $user->id,
            // Existing PCFs for the candidate users (all years) so the create
            // dropdown can hide anyone already committed for the chosen year.
            'takenPcf' => WigsPcf::whereIn('user_id', WigsService::selectableUserIds($user))
                ->get(['user_id', 'year'])
                ->map(fn ($p) => ['user_id' => (int) $p->user_id, 'year' => (int) $p->year])
                ->values(),
            'can' => [
                'create' => $user->can('wigs.create'),
                'edit' => $user->can('wigs.edit'),
                'delete' => $user->can('wigs.delete'),
                'manage_all' => $user->can('wigs.manage_all'),
                'manage_yardstick' => $user->can('wigs.manage_yardstick'),
            ],
        ]);
    }

    public function showPcf(Request $request, WigsPcf $pcf)
    {
        $this->authorizeRecord($request, $pcf->user_id);

        $pcf->load(['user:id,name,position,org_path', 'confirmer:id,name', 'items.scores']);

        return response()->json($this->serializePcf($pcf));
    }

    public function storePcf(Request $request)
    {
        $data = $this->validatePcf($request);
        $this->authorizeRecord($request, (int) $data['user_id']);

        if (WigsPcf::where('user_id', $data['user_id'])->where('year', $data['year'])->exists()) {
            throw ValidationException::withMessages([
                'year' => 'A PCF already exists for this team member and year. Edit it instead.',
            ]);
        }

        $pcf = DB::transaction(function () use ($data, $request) {
            $levels = $this->resolveOrgLevels($data);

            $pcf = WigsPcf::create([
                'user_id' => $data['user_id'],
                'year' => $data['year'],
                'level_1' => $levels[0],
                'level_2' => $levels[1],
                'level_3' => $levels[2],
                'status' => 'draft',
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $this->syncItems($pcf, $data['items'] ?? []);

            return $pcf;
        });

        return redirect()->back()->with('success', 'PCF created successfully.');
    }

    public function updatePcf(Request $request, WigsPcf $pcf)
    {
        $this->authorizeRecord($request, $pcf->user_id);
        $data = $this->validatePcf($request, $pcf);

        DB::transaction(function () use ($data, $request, $pcf) {
            $levels = $this->resolveOrgLevels($data);

            $pcf->update([
                'year' => $data['year'],
                'level_1' => $levels[0],
                'level_2' => $levels[1],
                'level_3' => $levels[2],
                'updated_by' => $request->user()->id,
            ]);

            $this->syncItems($pcf, $data['items'] ?? []);
        });

        return redirect()->back()->with('success', 'PCF updated successfully.');
    }

    public function confirmPcf(Request $request, WigsPcf $pcf)
    {
        $this->authorizeRecord($request, $pcf->user_id);

        $pcf->update([
            'status' => $pcf->status === 'confirmed' ? 'draft' : 'confirmed',
            'confirmed_by' => $pcf->status === 'confirmed' ? null : $request->user()->id,
            'confirmed_at' => $pcf->status === 'confirmed' ? null : now(),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'PCF status updated.');
    }

    public function destroyPcf(Request $request, WigsPcf $pcf)
    {
        $this->authorizeRecord($request, $pcf->user_id);

        $pcf->delete();

        return redirect()->back()->with('success', 'PCF deleted successfully.');
    }

    /**
     * Save PAF quarterly grades for a PCF. Only quarters that are open for
     * grading (first week of the month after the quarter ends) are accepted.
     */
    public function gradePcf(Request $request, WigsPcf $pcf)
    {
        $this->authorizeRecord($request, $pcf->user_id);

        $validated = $request->validate([
            'scores' => 'required|array',
            'scores.*.pcf_item_id' => 'required|integer',
            'scores.*.quarter' => 'required|integer|min:1|max:4',
            'scores.*.actual_performance' => 'nullable|string|max:2000',
            'scores.*.rating' => 'nullable|integer|min:1|max:4',
            'scores.*.value_pass' => 'nullable|boolean',
            'scores.*.remarks' => 'nullable|string|max:2000',
        ]);

        $itemIds = $pcf->items()->pluck('id')->all();

        DB::transaction(function () use ($validated, $request, $pcf, $itemIds) {
            foreach ($validated['scores'] as $row) {
                if (!in_array((int) $row['pcf_item_id'], $itemIds, true)) {
                    continue; // ignore items not belonging to this PCF
                }
                if (!WigsService::isQuarterOpen($pcf->year, (int) $row['quarter'])) {
                    continue; // quarter not yet open for grading
                }

                WigsPcfItem::find($row['pcf_item_id'])->scores()->updateOrCreate(
                    ['quarter' => $row['quarter']],
                    [
                        'actual_performance' => $row['actual_performance'] ?? null,
                        'rating' => $row['rating'] ?? null,
                        'value_pass' => $row['value_pass'] ?? null,
                        'remarks' => $row['remarks'] ?? null,
                        'graded_by' => $request->user()->id,
                        'graded_at' => now(),
                    ]
                );
            }
        });

        return redirect()->back()->with('success', 'Quarterly grades saved.');
    }

    /**
     * Admin-only full sync of the Yardstick reference configuration.
     */
    public function saveYardstick(Request $request)
    {
        $data = $request->validate([
            'standards' => 'array',
            'standards.*.general' => 'required|string|max:255',
            'standards.*.specific' => 'nullable|string|max:2000',
            'standards.*.rating_4' => 'nullable|string|max:2000',
            'standards.*.rating_3' => 'nullable|string|max:2000',
            'standards.*.rating_2' => 'nullable|string|max:2000',
            'standards.*.rating_1' => 'nullable|string|max:2000',
            'values' => 'array',
            'values.*.name' => 'required|string|max:255',
            'values.*.track_question' => 'nullable|string|max:2000',
            'values.*.guide_questions' => 'array',
            'values.*.guide_questions.*' => 'nullable|string|max:2000',
            'ratings' => 'array',
            'ratings.*.rating' => 'required|string|max:5',
            'ratings.*.description' => 'nullable|string|max:2000',
            'guidelines' => 'array',
            'guidelines.*.quarter' => 'required|integer|min:1|max:4',
            'guidelines.*.value_name' => 'nullable|string|max:255',
            'guidelines.*.description' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($data) {
            // Performance standards
            WigsPerformanceStandard::query()->delete();
            foreach ($data['standards'] ?? [] as $i => $s) {
                WigsPerformanceStandard::create([
                    'general' => $s['general'],
                    'specific' => $s['specific'] ?? null,
                    'rating_4' => $s['rating_4'] ?? null,
                    'rating_3' => $s['rating_3'] ?? null,
                    'rating_2' => $s['rating_2'] ?? null,
                    'rating_1' => $s['rating_1'] ?? null,
                    'sort_order' => $i,
                    'is_active' => true,
                ]);
            }

            // TRACK values + guide questions (cascade deletes the questions)
            WigsTrackValue::query()->delete();
            foreach ($data['values'] ?? [] as $i => $v) {
                $value = WigsTrackValue::create([
                    'name' => $v['name'],
                    'track_question' => $v['track_question'] ?? null,
                    'sort_order' => $i,
                    'is_active' => true,
                ]);
                foreach (array_values(array_filter($v['guide_questions'] ?? [], fn ($q) => filled($q))) as $qi => $q) {
                    $value->guideQuestions()->create(['question' => $q, 'sort_order' => $qi]);
                }
            }

            // TRACK rating definitions
            WigsTrackRating::query()->delete();
            foreach ($data['ratings'] ?? [] as $i => $r) {
                WigsTrackRating::create([
                    'rating' => $r['rating'],
                    'description' => $r['description'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            // Quarter guidelines
            WigsQuarterGuideline::query()->delete();
            foreach ($data['guidelines'] ?? [] as $g) {
                WigsQuarterGuideline::create([
                    'quarter' => $g['quarter'],
                    'value_name' => $g['value_name'] ?? null,
                    'description' => $g['description'] ?? null,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Yardstick configuration saved.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function authorizeRecord(Request $request, int $targetUserId): void
    {
        abort_unless(WigsService::canAccessUser($request->user(), $targetUserId), 403);
    }

    private function validatePcf(Request $request, ?WigsPcf $pcf = null): array
    {
        return $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'year' => 'required|integer|min:2000|max:2100',
            'level_1' => 'nullable|string|max:255',
            'level_2' => 'nullable|string|max:255',
            'level_3' => 'nullable|string|max:255',
            'items' => 'array',
            'items.*.kra' => 'nullable|string|max:255',
            'items.*.wig' => 'nullable|string|max:2000',
            'items.*.lead_measures' => 'nullable|string|max:2000',
            'items.*.performance_standard' => 'nullable|string|max:255',
            'items.*.performance_metric' => 'nullable|string|max:2000',
            'items.*.metric_benchmark' => 'nullable|string|max:2000',
            'items.*.q1_weight' => 'nullable|numeric|min:0|max:100',
            'items.*.q2_weight' => 'nullable|numeric|min:0|max:100',
            'items.*.q3_weight' => 'nullable|numeric|min:0|max:100',
            'items.*.q4_weight' => 'nullable|numeric|min:0|max:100',
            'items.*.value_alignment' => 'nullable|string|max:255',
            'items.*.value_remarks' => 'nullable|string|max:2000',
        ]);
    }

    /**
     * Resolve org levels: use explicit values if provided, otherwise derive
     * from the target user's org_path breadcrumb ("L1 > L2 > L3").
     */
    private function resolveOrgLevels(array $data): array
    {
        if (filled($data['level_1'] ?? null) || filled($data['level_2'] ?? null) || filled($data['level_3'] ?? null)) {
            return [$data['level_1'] ?? null, $data['level_2'] ?? null, $data['level_3'] ?? null];
        }

        $user = User::find($data['user_id']);
        $parts = array_map('trim', explode('>', (string) ($user->org_path ?? '')));
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        return [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null];
    }

    /**
     * Replace the PCF's items with the supplied set (delete-and-recreate keeps
     * grading in sync; PAF scores cascade with their item).
     */
    private function syncItems(WigsPcf $pcf, array $items): void
    {
        $pcf->items()->delete();

        foreach (array_values($items) as $i => $item) {
            $pcf->items()->create([
                'kra' => $item['kra'] ?? null,
                'wig' => $item['wig'] ?? null,
                'lead_measures' => $item['lead_measures'] ?? null,
                'performance_standard' => $item['performance_standard'] ?? null,
                'performance_metric' => $item['performance_metric'] ?? null,
                'metric_benchmark' => $item['metric_benchmark'] ?? null,
                'q1_weight' => $item['q1_weight'] ?? 0,
                'q2_weight' => $item['q2_weight'] ?? 0,
                'q3_weight' => $item['q3_weight'] ?? 0,
                'q4_weight' => $item['q4_weight'] ?? 0,
                'value_alignment' => $item['value_alignment'] ?? null,
                'value_remarks' => $item['value_remarks'] ?? null,
                'sort_order' => $i,
            ]);
        }
    }

    private function yardstickPayload(): array
    {
        return [
            'standards' => WigsPerformanceStandard::orderBy('sort_order')->get([
                'id', 'general', 'specific', 'rating_4', 'rating_3', 'rating_2', 'rating_1',
            ]),
            'values' => WigsTrackValue::with('guideQuestions:id,track_value_id,question,sort_order')
                ->orderBy('sort_order')
                ->get(['id', 'name', 'track_question', 'sort_order'])
                ->map(fn (WigsTrackValue $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'track_question' => $v->track_question,
                    'guide_questions' => $v->guideQuestions->pluck('question')->values(),
                ]),
            'ratings' => WigsTrackRating::orderBy('sort_order')->get(['id', 'rating', 'description']),
            'guidelines' => WigsQuarterGuideline::orderBy('quarter')->get(['id', 'quarter', 'value_name', 'description']),
        ];
    }

    /**
     * Serialize a PCF + its items, with the derived PAF (annual appraisal)
     * computed from the per-quarter grades.
     *
     * Quarter score for an item = (quarter weight / 100) * quarter rating.
     * Annual item score = average of the graded quarters' scores.
     * PAF total = sum of annual item scores.
     */
    private function serializePcf(WigsPcf $pcf): array
    {
        $quarterTotals = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
        $pafTotal = 0.0;
        $allValuesPass = true;

        $items = $pcf->items->map(function (WigsPcfItem $item) use (&$quarterTotals, &$pafTotal, &$allValuesPass) {
            $weights = [
                1 => (float) $item->q1_weight,
                2 => (float) $item->q2_weight,
                3 => (float) $item->q3_weight,
                4 => (float) $item->q4_weight,
            ];
            foreach ($weights as $q => $w) {
                $quarterTotals[$q] += $w;
            }

            $scoresByQuarter = $item->scores->keyBy('quarter');
            $quarterScores = [];
            $ratingSum = 0;
            $ratingCount = 0;
            $scoreSum = 0.0;
            $scoreCount = 0;
            $itemValuePass = null;

            foreach ([1, 2, 3, 4] as $q) {
                $score = $scoresByQuarter->get($q);
                $rating = $score?->rating;
                $qScore = ($rating !== null) ? ($weights[$q] / 100) * $rating : null;

                $quarterScores[$q] = [
                    'actual_performance' => $score?->actual_performance,
                    'rating' => $rating,
                    'value_pass' => $score?->value_pass,
                    'remarks' => $score?->remarks,
                    'score' => $qScore !== null ? round($qScore, 2) : null,
                ];

                if ($rating !== null) {
                    $ratingSum += $rating;
                    $ratingCount++;
                    $scoreSum += $qScore;
                    $scoreCount++;
                }
                if ($score && $score->value_pass !== null) {
                    $itemValuePass = ($itemValuePass === null ? true : $itemValuePass) && $score->value_pass;
                }
            }

            $annualRating = $ratingCount ? round($ratingSum / $ratingCount, 2) : null;
            $annualScore = $scoreCount ? round($scoreSum / $scoreCount, 2) : null;
            $annualWeight = round(($weights[1] + $weights[2] + $weights[3] + $weights[4]) / 4, 2);

            if ($annualScore !== null) {
                $pafTotal += $annualScore;
            }
            if ($itemValuePass === false) {
                $allValuesPass = false;
            }

            return [
                'id' => $item->id,
                'kra' => $item->kra,
                'wig' => $item->wig,
                'lead_measures' => $item->lead_measures,
                'performance_standard' => $item->performance_standard,
                'performance_metric' => $item->performance_metric,
                'metric_benchmark' => $item->metric_benchmark,
                'q1_weight' => (float) $item->q1_weight,
                'q2_weight' => (float) $item->q2_weight,
                'q3_weight' => (float) $item->q3_weight,
                'q4_weight' => (float) $item->q4_weight,
                'value_alignment' => $item->value_alignment,
                'value_remarks' => $item->value_remarks,
                'sort_order' => $item->sort_order,
                'quarters' => $quarterScores,
                'annual_weight' => $annualWeight,
                'annual_rating' => $annualRating,
                'annual_score' => $annualScore,
                'value_pass' => $itemValuePass,
            ];
        })->values();

        return [
            'id' => $pcf->id,
            'user_id' => $pcf->user_id,
            'user' => $pcf->user ? [
                'id' => $pcf->user->id,
                'name' => $pcf->user->name,
                'position' => $pcf->user->position,
                'org_path' => $pcf->user->org_path,
            ] : null,
            'year' => $pcf->year,
            'level_1' => $pcf->level_1,
            'level_2' => $pcf->level_2,
            'level_3' => $pcf->level_3,
            'status' => $pcf->status,
            'confirmed_by' => $pcf->confirmer?->name,
            'confirmed_at' => $pcf->confirmed_at?->toDateString(),
            'items' => $items,
            'quarter_weight_totals' => array_map(fn ($v) => round($v, 2), $quarterTotals),
            'paf_total' => round($pafTotal, 2),
            'for_rehire' => $items->isNotEmpty() && $allValuesPass,
        ];
    }
}
