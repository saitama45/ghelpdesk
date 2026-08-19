<?php

namespace App\Services;

use App\Models\QatCase;
use App\Models\QatCaseResult;
use App\Models\QatCycle;
use App\Models\QatFinding;
use App\Models\QatParticipant;
use App\Models\QatSignoff;
use Illuminate\Support\Collection;

/**
 * Roll-ups and the sign-off gate for the QAT Tracker.
 *
 * The verdict algebra is intentionally identical to {@see UatService}: one verdict
 * per case x participant, collapsed to one column per department, then worst-wins
 * across departments. Keeping the two in step is what lets test cases and the
 * workbook move between the modules without translation.
 *
 * It is a deliberate copy rather than a shared base class. UatService is shipped,
 * verified code behind a working module; rewriting its internals to serve a module
 * that does not exist yet risks a silent change to UAT's readiness gate, and a
 * wrongly-allowed sign-off is exactly the failure nobody notices. The extraction
 * is worth doing later, behind a full UAT regression run.
 *
 * What genuinely differs here is readiness(): QAT gates on a manager's decision
 * rather than a roster of approvers, and unresolved blocker/major findings block
 * that decision unless the manager waives them in writing.
 */
class QatService
{
    /**
     * The matrix columns: ONE per department, not one per person.
     *
     * A department frequently has both a tester and a reviewer. They each record
     * their own answer (the audit trail keeps both), but the matrix shows the
     * department once — otherwise two identically-headed columns sit side by side
     * and nobody can tell which is which.
     *
     * @param  Collection<int,QatParticipant>  $participants
     * @return array<int,array<string,mixed>>
     */
    public function columns(Collection $participants): array
    {
        return $participants
            ->filter(fn (QatParticipant $p) => $p->is_active && $p->canRecordVerdicts())
            ->groupBy(fn (QatParticipant $p) => mb_strtolower(trim((string) $p->label)))
            ->map(function (Collection $group) {
                $reviewer = $group->firstWhere('role', QatParticipant::ROLE_REVIEWER);
                $tester = $group->firstWhere('role', QatParticipant::ROLE_TESTER);
                $first = $group->first();

                return [
                    'key' => mb_strtolower(trim((string) $first->label)),
                    'label' => $first->label,
                    'kind' => $first->kind,
                    'member_ids' => $group->pluck('id')->all(),
                    'reviewer_id' => $reviewer?->id,
                    'tester_id' => $tester?->id,
                    // Whoever a new verdict is recorded against by default: the
                    // reviewer has the final say, so they own the column.
                    'default_participant_id' => $reviewer?->id ?? $tester?->id ?? $first->id,
                    'members' => $group->map(fn (QatParticipant $p) => [
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
     * The reviewer's answer IS the department's decision. Until they give one, the
     * tester's answer stands in — so a department is never blank while its tester
     * has reported something.
     *
     * @param  Collection<int,QatCaseResult>  $caseResults  results for a single case
     * @param  array<string,mixed>  $column
     */
    public function columnVerdict(Collection $caseResults, array $column): string
    {
        if ($column['reviewer_id'] ?? null) {
            $reviewed = $caseResults->firstWhere('qat_participant_id', $column['reviewer_id']);

            if ($reviewed && $reviewed->result !== QatCaseResult::PENDING) {
                return $reviewed->result;
            }
        }

        // Fall back to everyone else in the department (usually one tester).
        $others = $caseResults->filter(
            fn (QatCaseResult $r) => in_array($r->qat_participant_id, $column['member_ids'], true)
                && $r->qat_participant_id !== ($column['reviewer_id'] ?? null)
        );

        return $this->rollUp($others);
    }

    /**
     * Headline verdict for a case across every department column.
     *
     * Departments are collapsed first (reviewer wins), then worst-wins across
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

    /** Worst-wins over a set of verdicts. */
    public function rollUp(Collection $results): string
    {
        // N/A is a deliberate "not in my scope" answer, not an outstanding one.
        $verdicts = $results
            ->pluck('result')
            ->reject(fn ($r) => $r === QatCaseResult::NOT_APPLICABLE)
            ->values();

        if ($verdicts->isEmpty()) {
            // Every participant answered N/A — the case genuinely does not apply.
            return $results->isNotEmpty() ? QatCaseResult::NOT_APPLICABLE : QatCaseResult::PENDING;
        }

        if ($verdicts->contains(QatCaseResult::FAILED)) {
            return QatCaseResult::FAILED;
        }

        if ($verdicts->contains(QatCaseResult::BLOCKED)) {
            return QatCaseResult::BLOCKED;
        }

        if ($verdicts->contains(QatCaseResult::PENDING)) {
            return QatCaseResult::PENDING;
        }

        if ($verdicts->contains(QatCaseResult::ONGOING)) {
            return QatCaseResult::ONGOING;
        }

        return QatCaseResult::PASSED;
    }

    /**
     * Cycle-level tallies.
     *
     * @param  Collection<int,QatCase>  $cases
     * @param  Collection<int,QatCaseResult>  $results
     * @param  Collection<int,QatParticipant>  $participants
     */
    public function statistics(Collection $cases, Collection $results, Collection $participants): array
    {
        $byCase = $results->groupBy('qat_case_id');
        $columns = $this->columns($participants);

        $counts = array_fill_keys(array_keys(QatCaseResult::results()), 0);
        $criticalTotal = 0;
        $criticalPassed = 0;
        $criticalOutstanding = 0;

        foreach ($cases as $case) {
            $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;

            if ($case->is_critical) {
                $criticalTotal++;
                if ($verdict === QatCaseResult::PASSED || $verdict === QatCaseResult::NOT_APPLICABLE) {
                    $criticalPassed++;
                } else {
                    $criticalOutstanding++;
                }
            }
        }

        $total = $cases->count();
        $executed = $counts[QatCaseResult::PASSED]
            + $counts[QatCaseResult::FAILED]
            + $counts[QatCaseResult::BLOCKED];

        $graded = $counts[QatCaseResult::PASSED] + $counts[QatCaseResult::FAILED];

        // The grid is cases x DEPARTMENT columns, so the cell count follows the
        // columns rather than the head-count behind them.
        $cellsTotal = $total * count($columns);
        $cellsFilled = 0;

        foreach ($cases as $case) {
            $caseResults = $byCase->get($case->id, collect());

            foreach ($columns as $column) {
                if ($this->columnVerdict($caseResults, $column) !== QatCaseResult::PENDING) {
                    $cellsFilled++;
                }
            }
        }

        return [
            'total_cases' => $total,
            'passed' => $counts[QatCaseResult::PASSED],
            'failed' => $counts[QatCaseResult::FAILED],
            'blocked' => $counts[QatCaseResult::BLOCKED],
            'ongoing' => $counts[QatCaseResult::ONGOING],
            'pending' => $counts[QatCaseResult::PENDING],
            'not_applicable' => $counts[QatCaseResult::NOT_APPLICABLE],
            'executed' => $executed,
            'execution_rate' => $total > 0 ? round($executed / $total, 4) : 0.0,
            'pass_rate' => $graded > 0 ? round($counts[QatCaseResult::PASSED] / $graded, 4) : 0.0,
            'critical_total' => $criticalTotal,
            'critical_passed' => $criticalPassed,
            'critical_outstanding' => $criticalOutstanding,
            'cells_total' => $cellsTotal,
            'cells_filled' => $cellsFilled,
            'cell_rate' => $cellsTotal > 0 ? round($cellsFilled / $cellsTotal, 4) : 0.0,
        ];
    }

    /**
     * Progress per DEPARTMENT column, using the same reviewer-wins rule as the
     * matrix, plus a per-member breakdown for the drill-down.
     *
     * @param  Collection<int,QatParticipant>  $participants
     */
    public function participantProgress(Collection $participants, Collection $cases, Collection $results): array
    {
        $byCase = $results->groupBy('qat_case_id');
        $byParticipant = $results->groupBy('qat_participant_id');
        $total = $cases->count();

        return collect($this->columns($participants))->map(function (array $column) use ($cases, $byCase, $byParticipant, $total) {
            $counts = array_fill_keys(array_keys(QatCaseResult::results()), 0);

            foreach ($cases as $case) {
                $verdict = $this->columnVerdict($byCase->get($case->id, collect()), $column);
                $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
            }

            $answered = $total - $counts[QatCaseResult::PENDING];

            return [
                'key' => $column['key'],
                'label' => $column['label'],
                'kind' => $column['kind'],
                'reviewer_id' => $column['reviewer_id'],
                'tester_id' => $column['tester_id'],
                'members' => collect($column['members'])->map(function (array $member) use ($byParticipant, $total) {
                    $rows = $byParticipant->get($member['id'], collect());
                    $done = $rows->reject(fn ($r) => $r->result === QatCaseResult::PENDING)->count();

                    return $member + [
                        'answered' => $done,
                        'total' => $total,
                        'rate' => $total > 0 ? round($done / $total, 4) : 0.0,
                    ];
                })->all(),
                'total' => $total,
                'answered' => $answered,
                'passed' => $counts[QatCaseResult::PASSED],
                'failed' => $counts[QatCaseResult::FAILED],
                'blocked' => $counts[QatCaseResult::BLOCKED],
                'ongoing' => $counts[QatCaseResult::ONGOING],
                'not_applicable' => $counts[QatCaseResult::NOT_APPLICABLE],
                'pending' => $counts[QatCaseResult::PENDING],
                'rate' => $total > 0 ? round($answered / $total, 4) : 0.0,
            ];
        })->values()->all();
    }

    /** Per-section progress, for the matrix group headers. */
    public function sectionProgress(Collection $cases, Collection $results, Collection $participants): array
    {
        $byCase = $results->groupBy('qat_case_id');
        $columns = $this->columns($participants);

        return $cases->groupBy('qat_section_id')->map(function (Collection $sectionCases) use ($byCase, $columns) {
            $passed = 0;
            $outstanding = 0;

            foreach ($sectionCases as $case) {
                $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);
                if ($verdict === QatCaseResult::PASSED || $verdict === QatCaseResult::NOT_APPLICABLE) {
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
     * Where the cycle stands, and what is holding it up.
     *
     * The QAT-specific parts are the last three groups: whether the cycle may be
     * submitted, what the manager has decided, and whether it may be promoted into
     * a UAT cycle. Promotion is gated on the sign-off and nothing else — that is
     * the whole point of the module.
     */
    public function readiness(
        QatCycle $cycle,
        Collection $cases,
        Collection $results,
        Collection $findings,
        Collection $participants
    ): array {
        $byCase = $results->groupBy('qat_case_id');
        $columns = $this->columns($participants);
        $gated = $cycle->gate_on_critical_only
            ? $cases->where('is_critical', true)
            : $cases;

        // Two different things, deliberately not merged.
        //
        // UNANSWERED means the run is not finished, and that genuinely blocks a
        // submission — there is nothing to decide on yet.
        //
        // FAILING means the run IS finished and found problems. That must NOT
        // block the submission: a failed case is precisely why a finding exists,
        // and if failures blocked submission then the manager could never see the
        // waiver decision they are the only person entitled to make. The failures
        // travel with the cycle and are shown to them instead.
        $unansweredCases = [];
        $failingCases = [];

        foreach ($gated as $case) {
            $verdict = $this->caseVerdict($byCase->get($case->id, collect()), $columns);

            if (in_array($verdict, [QatCaseResult::PASSED, QatCaseResult::NOT_APPLICABLE], true)) {
                continue;
            }

            $row = [
                'case_key' => $case->case_key,
                'title' => $case->title,
                'verdict' => $verdict,
            ];

            if (in_array($verdict, [QatCaseResult::PENDING, QatCaseResult::ONGOING], true)) {
                $unansweredCases[] = $row;
            } else {
                $failingCases[] = $row;
            }
        }

        // What still stands in the manager's way: unresolved, severe, unwaived.
        $blockingFindings = $findings
            ->filter(fn (QatFinding $f) => $f->isBlocking())
            ->map(fn (QatFinding $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'title' => $f->title,
                'severity' => $f->severity,
            ])->values()->all();

        // Kept separate rather than merged away: a waived finding is not resolved,
        // and the page must keep showing that somebody decided to live with it.
        $waivedFindings = $findings
            ->filter(fn (QatFinding $f) => $f->isWaived())
            ->map(fn (QatFinding $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'title' => $f->title,
                'severity' => $f->severity,
                'waiver_reason' => $f->waiver_reason,
                'waived_at' => $f->waived_at?->toIso8601String(),
                'waived_by' => $f->relationLoaded('waivedBy') ? $f->waivedBy?->name : null,
            ])->values()->all();

        // "No outstanding cases" is vacuously true for a cycle with no cases at
        // all, which would otherwise report an empty draft as testing-complete.
        $hasCases = $cases->isNotEmpty();
        $hasParticipants = $participants
            ->filter(fn ($p) => $p->is_active && $p->canRecordVerdicts())
            ->isNotEmpty();
        $isSetUp = $hasCases && $hasParticipants;

        $testingReady = $isSetUp && empty($unansweredCases);

        $signoff = $cycle->relationLoaded('managerSignoff')
            ? $cycle->managerSignoff
            : $cycle->managerSignoff()->first();

        $isSignedOff = $cycle->status === QatCycle::STATUS_SIGNED_OFF;

        return [
            'gate_on_critical_only' => (bool) $cycle->gate_on_critical_only,
            'unanswered_cases' => $unansweredCases,
            'failing_cases' => $failingCases,
            'blocking_findings' => $blockingFindings,
            'waived_findings' => $waivedFindings,
            'has_cases' => $hasCases,
            'has_participants' => $hasParticipants,
            'is_set_up' => $isSetUp,
            'testing_ready' => $testingReady,

            // --- the QAT sign-off spine ---
            // Blocking findings deliberately do NOT stop a submission: the manager
            // has to be able to see them in order to waive them.
            'can_submit' => $testingReady && in_array($cycle->status, QatCycle::OPEN_STATUSES, true),
            'awaiting_approval' => $cycle->isAwaitingApproval(),
            'signed_off' => $isSignedOff,
            'signoff' => $signoff ? [
                'result' => $signoff->result,
                'remarks' => $signoff->remarks,
                'confirmed_at' => $signoff->confirmed_at?->toIso8601String(),
                'confirmed_name' => $signoff->confirmed_name,
                'waiver_reason' => $signoff->waiver_reason,
                'waived_finding_ids' => $signoff->waived_finding_ids ?? [],
            ] : null,
            'can_promote' => $isSignedOff && ! $cycle->promoted_uat_cycle_id,
            'already_promoted' => (bool) $cycle->promoted_uat_cycle_id,
        ];
    }

    /**
     * Creates the missing matrix cells for a cycle so the grid is never ragged.
     * Called after cases or participants change.
     */
    public function ensureCells(QatCycle $cycle): int
    {
        $caseIds = QatCase::where('qat_cycle_id', $cycle->id)->pluck('id');
        $participantIds = QatParticipant::where('qat_cycle_id', $cycle->id)
            ->where('is_active', true)
            ->whereIn('role', [QatParticipant::ROLE_TESTER, QatParticipant::ROLE_REVIEWER])
            ->pluck('id');

        if ($caseIds->isEmpty() || $participantIds->isEmpty()) {
            return 0;
        }

        $existing = QatCaseResult::where('qat_cycle_id', $cycle->id)
            ->get(['qat_case_id', 'qat_participant_id'])
            ->map(fn ($r) => $r->qat_case_id.':'.$r->qat_participant_id)
            ->flip();

        $rows = [];
        $now = now();

        foreach ($caseIds as $caseId) {
            foreach ($participantIds as $participantId) {
                if ($existing->has($caseId.':'.$participantId)) {
                    continue;
                }

                $rows[] = [
                    'qat_cycle_id' => $cycle->id,
                    'qat_case_id' => $caseId,
                    'qat_participant_id' => $participantId,
                    'result' => QatCaseResult::PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Chunked: SQL Server caps a single INSERT at 1000 rows.
        foreach (array_chunk($rows, 500) as $chunk) {
            QatCaseResult::insert($chunk);
        }

        return count($rows);
    }

    /** Supersedes any current sign-off for the stage, then records a new one. */
    public function recordSignoff(QatCycle $cycle, ?QatParticipant $participant, string $stage, array $attributes): QatSignoff
    {
        QatSignoff::where('qat_cycle_id', $cycle->id)
            ->where('stage', $stage)
            ->when($participant, fn ($q) => $q->where('qat_participant_id', $participant->id))
            ->when(! $participant, fn ($q) => $q->whereNull('qat_participant_id'))
            ->where('is_current', true)
            ->update(['is_current' => false]);

        return QatSignoff::create(array_merge([
            'qat_cycle_id' => $cycle->id,
            'qat_participant_id' => $participant?->id,
            'stage' => $stage,
            'confirmed_at' => now(),
            'is_current' => true,
        ], $attributes));
    }
}
