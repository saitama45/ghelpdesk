<?php

namespace App\Services;

use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
use App\Models\UatFinding;
use App\Models\UatParticipant;
use App\Models\UatSignoff;
use Illuminate\Support\Collection;

/**
 * Roll-ups for the UAT Tracker.
 *
 * The source workbook held one verdict per test case because a single tester
 * owned the sheet; the walkthrough checklist held one per department. This
 * module stores the per-participant verdicts and derives the single headline
 * verdict from them, so both source shapes are representable and the workbook's
 * header tallies (Passed / Failed / Pending / % executed) still reconcile.
 */
class UatService
{
    /**
     * The matrix columns: ONE per department, not one per person.
     *
     * A department frequently has both a tester and an approver. They each
     * record their own answer (the audit trail keeps both), but the matrix shows
     * the department once — otherwise two columns headed "MKTG" sit side by side
     * and nobody can tell which is which.
     *
     * @param  Collection<int,UatParticipant>  $participants
     * @return array<int,array<string,mixed>>
     */
    public function columns(Collection $participants): array
    {
        return $participants
            ->filter(fn (UatParticipant $p) => $p->is_active && $p->canRecordVerdicts())
            ->groupBy(fn (UatParticipant $p) => mb_strtolower(trim((string) $p->label)))
            ->map(function (Collection $group) {
                $approver = $group->firstWhere('role', UatParticipant::ROLE_APPROVER);
                $tester = $group->firstWhere('role', UatParticipant::ROLE_TESTER);
                $first = $group->first();

                return [
                    'key' => mb_strtolower(trim((string) $first->label)),
                    'label' => $first->label,
                    'kind' => $first->kind,
                    'member_ids' => $group->pluck('id')->all(),
                    'approver_id' => $approver?->id,
                    'tester_id' => $tester?->id,
                    // Whoever a new verdict is recorded against by default: the
                    // approver has the final say, so they own the column.
                    'default_participant_id' => $approver?->id ?? $tester?->id ?? $first->id,
                    'members' => $group->map(fn (UatParticipant $p) => [
                        'id' => $p->id,
                        'name' => $p->display_name,
                        'role' => $p->role,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One department's verdict on one case.
     *
     * The approver's answer IS the department's decision. Until they give one,
     * the tester's answer stands in — so a department is never blank while its
     * tester has reported something.
     *
     * @param  Collection<int,UatCaseResult>  $caseResults  results for a single case
     * @param  array<string,mixed>  $column
     */
    public function columnVerdict(Collection $caseResults, array $column): string
    {
        if ($column['approver_id'] ?? null) {
            $approved = $caseResults->firstWhere('uat_participant_id', $column['approver_id']);

            if ($approved && $approved->result !== UatCaseResult::PENDING) {
                return $approved->result;
            }
        }

        // Fall back to everyone else in the department (usually one tester).
        $others = $caseResults->filter(
            fn (UatCaseResult $r) => in_array($r->uat_participant_id, $column['member_ids'], true)
                && $r->uat_participant_id !== ($column['approver_id'] ?? null)
        );

        return $this->rollUp($others);
    }

    /**
     * Headline verdict for a case across every department column.
     *
     * Departments are collapsed first (approver wins), then worst-wins across
     * them: one department reporting a failure fails the case regardless of how
     * many others passed it.
     *
     * @param  array<int,array<string,mixed>>  $columns
     */
    public function caseVerdict(Collection $caseResults, array $columns): string
    {
        if ($columns === []) {
            return $this->rollUp($caseResults);
        }

        $perColumn = collect($columns)
            ->map(fn (array $column) => (object) ['result' => $this->columnVerdict($caseResults, $column)]);

        return $this->rollUp($perColumn);
    }

    /**
     * Worst-wins over a set of verdicts.
     *
     * Used both for the members inside one department column and for the
     * collapsed department verdicts across a case.
     */
    public function rollUp(Collection $results): string
    {
        // N/A is a deliberate "not in my scope" answer, not an outstanding one.
        $verdicts = $results
            ->pluck('result')
            ->reject(fn ($r) => $r === UatCaseResult::NOT_APPLICABLE)
            ->values();

        if ($verdicts->isEmpty()) {
            // Every participant answered N/A — the case genuinely does not apply.
            return $results->isNotEmpty() ? UatCaseResult::NOT_APPLICABLE : UatCaseResult::PENDING;
        }

        if ($verdicts->contains(UatCaseResult::FAILED)) {
            return UatCaseResult::FAILED;
        }

        if ($verdicts->contains(UatCaseResult::BLOCKED)) {
            return UatCaseResult::BLOCKED;
        }

        if ($verdicts->contains(UatCaseResult::PENDING)) {
            return UatCaseResult::PENDING;
        }

        if ($verdicts->contains(UatCaseResult::ONGOING)) {
            return UatCaseResult::ONGOING;
        }

        return UatCaseResult::PASSED;
    }

    /**
     * Cycle-level tallies — the live equivalent of the workbook's header block.
     *
     * @param  Collection<int,UatCase>  $cases
     * @param  Collection<int,UatCaseResult>  $results
     * @param  Collection<int,UatParticipant>  $participants
     */
    public function statistics(Collection $cases, Collection $results, Collection $participants): array
    {
        $byCase = $results->groupBy('uat_case_id');
        $columns = $this->columns($participants);

        $counts = array_fill_keys(array_keys(UatCaseResult::results()), 0);
        $criticalTotal = 0;
        $criticalPassed = 0;
        $criticalOutstanding = 0;

        foreach ($cases as $case) {
            $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;

            if ($case->is_critical) {
                $criticalTotal++;
                if ($verdict === UatCaseResult::PASSED || $verdict === UatCaseResult::NOT_APPLICABLE) {
                    $criticalPassed++;
                } else {
                    $criticalOutstanding++;
                }
            }
        }

        $total = $cases->count();
        // Matches the workbook formula: executed = Passed + Failed (+ Blocked,
        // which was not a state it could express but has genuinely been tried).
        $executed = $counts[UatCaseResult::PASSED]
            + $counts[UatCaseResult::FAILED]
            + $counts[UatCaseResult::BLOCKED];

        $graded = $counts[UatCaseResult::PASSED] + $counts[UatCaseResult::FAILED];

        // The matrix grid is cases x DEPARTMENT columns, so the cell count follows
        // the columns rather than the head-count behind them.
        $cellsTotal = $total * count($columns);
        $cellsFilled = 0;

        foreach ($cases as $case) {
            $caseResults = $byCase->get($case->id, collect());

            foreach ($columns as $column) {
                if ($this->columnVerdict($caseResults, $column) !== UatCaseResult::PENDING) {
                    $cellsFilled++;
                }
            }
        }

        return [
            'total_cases' => $total,
            'passed' => $counts[UatCaseResult::PASSED],
            'failed' => $counts[UatCaseResult::FAILED],
            'blocked' => $counts[UatCaseResult::BLOCKED],
            'ongoing' => $counts[UatCaseResult::ONGOING],
            'pending' => $counts[UatCaseResult::PENDING],
            'not_applicable' => $counts[UatCaseResult::NOT_APPLICABLE],
            'executed' => $executed,
            'execution_rate' => $total > 0 ? round($executed / $total, 4) : 0.0,
            'pass_rate' => $graded > 0 ? round($counts[UatCaseResult::PASSED] / $graded, 4) : 0.0,
            'critical_total' => $criticalTotal,
            'critical_passed' => $criticalPassed,
            'critical_outstanding' => $criticalOutstanding,
            'cells_total' => $cellsTotal,
            'cells_filled' => $cellsFilled,
            'cell_rate' => $cellsTotal > 0 ? round($cellsFilled / $cellsTotal, 4) : 0.0,
        ];
    }

    /**
     * Progress per DEPARTMENT column, using the same approver-wins rule as the
     * matrix, plus a per-member breakdown for the drill-down.
     *
     * @param  Collection<int,UatParticipant>  $participants
     */
    public function participantProgress(Collection $participants, Collection $cases, Collection $results): array
    {
        $byCase = $results->groupBy('uat_case_id');
        $byParticipant = $results->groupBy('uat_participant_id');
        $total = $cases->count();

        return collect($this->columns($participants))->map(function (array $column) use ($cases, $byCase, $byParticipant, $total) {
            $counts = array_fill_keys(array_keys(UatCaseResult::results()), 0);

            foreach ($cases as $case) {
                $verdict = $this->columnVerdict($byCase->get($case->id, collect()), $column);
                $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
            }

            $answered = $total - $counts[UatCaseResult::PENDING];

            return [
                'key' => $column['key'],
                'label' => $column['label'],
                'kind' => $column['kind'],
                'approver_id' => $column['approver_id'],
                'tester_id' => $column['tester_id'],
                // Who sits behind the department column, and how far each is —
                // shown when the column is expanded.
                'members' => collect($column['members'])->map(function (array $member) use ($byParticipant, $total) {
                    $rows = $byParticipant->get($member['id'], collect());
                    $done = $rows->reject(fn ($r) => $r->result === UatCaseResult::PENDING)->count();

                    return $member + [
                        'answered' => $done,
                        'total' => $total,
                        'rate' => $total > 0 ? round($done / $total, 4) : 0.0,
                    ];
                })->all(),
                'total' => $total,
                'answered' => $answered,
                'passed' => $counts[UatCaseResult::PASSED],
                'failed' => $counts[UatCaseResult::FAILED],
                'blocked' => $counts[UatCaseResult::BLOCKED],
                'ongoing' => $counts[UatCaseResult::ONGOING],
                'not_applicable' => $counts[UatCaseResult::NOT_APPLICABLE],
                'pending' => $counts[UatCaseResult::PENDING],
                'rate' => $total > 0 ? round($answered / $total, 4) : 0.0,
            ];
        })->values()->all();
    }

    /** Per-section progress, for the matrix group headers. */
    public function sectionProgress(Collection $cases, Collection $results, Collection $participants): array
    {
        $byCase = $results->groupBy('uat_case_id');
        $columns = $this->columns($participants);

        return $cases->groupBy('uat_section_id')->map(function (Collection $sectionCases) use ($byCase, $columns) {
            $passed = 0;
            $outstanding = 0;

            foreach ($sectionCases as $case) {
                $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);
                if ($verdict === UatCaseResult::PASSED || $verdict === UatCaseResult::NOT_APPLICABLE) {
                    $passed++;
                } else {
                    $outstanding++;
                }
            }

            return [
                'total' => $sectionCases->count(),
                'passed' => $passed,
                'outstanding' => $outstanding,
                'rate' => $sectionCases->count() > 0 ? round($passed / $sectionCases->count(), 4) : 0.0,
            ];
        })->all();
    }

    /**
     * Whether the cycle can be signed off, and what is standing in the way.
     *
     * Mirrors the "Non-critical for Go-Live" split in the source checklist: when
     * the cycle gates on critical items only, non-critical rows are reported but
     * never block.
     */
    public function readiness(UatCycle $cycle, Collection $cases, Collection $results, Collection $findings, Collection $participants): array
    {
        $byCase = $results->groupBy('uat_case_id');
        $columns = $this->columns($participants);
        $gated = $cycle->gate_on_critical_only
            ? $cases->where('is_critical', true)
            : $cases;

        $outstandingCases = [];
        foreach ($gated as $case) {
            $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);
            if (!in_array($verdict, [UatCaseResult::PASSED, UatCaseResult::NOT_APPLICABLE], true)) {
                $outstandingCases[] = [
                    'case_key' => $case->case_key,
                    'title' => $case->title,
                    'verdict' => $verdict,
                ];
            }
        }

        $blockingFindings = $findings
            ->whereIn('status', UatFinding::UNRESOLVED_STATUSES)
            ->whereIn('severity', [UatFinding::SEVERITY_BLOCKER, UatFinding::SEVERITY_MAJOR])
            ->map(fn (UatFinding $f) => [
                'reference' => $f->reference,
                'title' => $f->title,
                'severity' => $f->severity,
            ])->values()->all();

        $approvers = $participants->filter(fn ($p) => $p->is_active && $p->isApprover());
        $pendingApprovers = [];

        foreach ($approvers as $approver) {
            $signoff = $approver->relationLoaded('currentSignoff')
                ? $approver->currentSignoff
                : $approver->currentSignoff()->first();

            if (!$signoff || !$signoff->isAccepting()) {
                $pendingApprovers[] = [
                    'id' => $approver->id,
                    'label' => $approver->label,
                    'name' => $approver->display_name,
                    'result' => $signoff?->result,
                ];
            }
        }

        // A cycle with nobody nominated to approve can never be signed off,
        // whichever rule it uses.
        $accepted = $approvers->count() - count($pendingApprovers);
        $signoffReady = $approvers->isNotEmpty() && ($cycle->signoff_requires_all
            ? empty($pendingApprovers)
            : $accepted > 0);

        // "No outstanding cases" is vacuously true for a cycle with no cases at
        // all, which would otherwise report an empty draft as testing-complete.
        // A cycle is only testable once it has both something to test and
        // somebody to test it.
        $hasCases = $cases->isNotEmpty();
        $hasParticipants = $participants
            ->filter(fn ($p) => $p->is_active && $p->canRecordVerdicts())
            ->isNotEmpty();
        $isSetUp = $hasCases && $hasParticipants;

        $testingReady = $isSetUp && empty($outstandingCases) && empty($blockingFindings);

        return [
            'gate_on_critical_only' => (bool) $cycle->gate_on_critical_only,
            'outstanding_cases' => $outstandingCases,
            'blocking_findings' => $blockingFindings,
            'pending_approvers' => $pendingApprovers,
            'has_cases' => $hasCases,
            'has_participants' => $hasParticipants,
            'is_set_up' => $isSetUp,
            'testing_ready' => $testingReady,
            'signoff_ready' => $signoffReady,
            'is_ready' => $testingReady && $signoffReady,
        ];
    }

    /** Current acceptance state per participant, keyed by participant id. */
    public function acceptanceLedger(Collection $participants): array
    {
        return $participants->mapWithKeys(function (UatParticipant $participant) {
            $signoff = $participant->relationLoaded('currentSignoff')
                ? $participant->currentSignoff
                : $participant->currentSignoff()->first();

            return [$participant->id => $signoff ? [
                'result' => $signoff->result,
                'remarks' => $signoff->remarks,
                'confirmed_at' => $signoff->confirmed_at?->toIso8601String(),
                'confirmed_name' => $signoff->confirmed_name,
                'confirmed_email' => $signoff->confirmed_email,
                // Most acceptances are signed on the tokenised portal, so the
                // roster inside the app is where that signature has to surface.
                'signature_url' => $signoff->signature_url,
            ] : null];
        })->all();
    }

    /**
     * Creates the missing matrix cells for a cycle so the grid is never ragged.
     * Called after cases or participants change.
     */
    public function ensureCells(UatCycle $cycle): int
    {
        $caseIds = UatCase::where('uat_cycle_id', $cycle->id)->pluck('id');
        $participantIds = UatParticipant::where('uat_cycle_id', $cycle->id)
            ->where('is_active', true)
            ->whereIn('role', [UatParticipant::ROLE_TESTER, UatParticipant::ROLE_APPROVER])
            ->pluck('id');

        if ($caseIds->isEmpty() || $participantIds->isEmpty()) {
            return 0;
        }

        $existing = UatCaseResult::where('uat_cycle_id', $cycle->id)
            ->get(['uat_case_id', 'uat_participant_id'])
            ->map(fn ($r) => $r->uat_case_id.':'.$r->uat_participant_id)
            ->flip();

        $rows = [];
        $now = now();

        foreach ($caseIds as $caseId) {
            foreach ($participantIds as $participantId) {
                if ($existing->has($caseId.':'.$participantId)) {
                    continue;
                }

                $rows[] = [
                    'uat_cycle_id' => $cycle->id,
                    'uat_case_id' => $caseId,
                    'uat_participant_id' => $participantId,
                    'result' => UatCaseResult::PENDING,
                    'source' => 'internal',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Chunked: SQL Server caps a single INSERT at 1000 rows.
        foreach (array_chunk($rows, 500) as $chunk) {
            UatCaseResult::insert($chunk);
        }

        return count($rows);
    }

    /** Supersedes any current sign-off for the participant/stage, then records a new one. */
    public function recordSignoff(UatCycle $cycle, ?UatParticipant $participant, string $stage, array $attributes): UatSignoff
    {
        UatSignoff::where('uat_cycle_id', $cycle->id)
            ->where('stage', $stage)
            ->when($participant, fn ($q) => $q->where('uat_participant_id', $participant->id))
            ->when(!$participant, fn ($q) => $q->whereNull('uat_participant_id'))
            ->where('is_current', true)
            ->update(['is_current' => false]);

        return UatSignoff::create(array_merge([
            'uat_cycle_id' => $cycle->id,
            'uat_participant_id' => $participant?->id,
            'stage' => $stage,
            'confirmed_at' => now(),
            'is_current' => true,
        ], $attributes));
    }
}
