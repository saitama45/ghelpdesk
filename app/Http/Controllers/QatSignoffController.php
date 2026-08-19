<?php

namespace App\Http\Controllers;

use App\Models\QatCycle;
use App\Models\QatFinding;
use App\Models\QatSignoff;
use App\Models\UatCase;
use App\Models\UatCycle;
use App\Models\UatSection;
use App\Services\ManagerApproverResolver;
use App\Services\NotificationService;
use App\Services\QatService;
use App\Support\SignatureImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The QAT sign-off spine: submit a cycle for the manager's decision, record that
 * decision, and — once it is signed off — promote the cycle into a UAT cycle.
 *
 * This is what the module exists for. Everything else is a test matrix; this is
 * the control that stops work reaching a client before somebody accountable has
 * put their name to it.
 */
class QatSignoffController extends Controller implements HasMiddleware
{
    private const CASE_LIST_COLUMNS = [
        'id', 'qat_cycle_id', 'qat_section_id', 'case_key', 'screen', 'title',
        'is_critical', 'priority', 'order',
    ];

    public function __construct(
        private QatService $qat,
        private ManagerApproverResolver $approvers,
        private NotificationService $notifications,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:qat.submit', only: ['submit', 'cancel']),
            new Middleware('can:qat.approve', only: ['decide']),
            new Middleware('can:qat.promote', only: ['promote']),
            // Anyone who can see the cycle can print its certificate — it is a
            // record of a decision, not a way to make one.
            new Middleware('can:qat.view', only: ['pdf']),
        ];
    }

    /**
     * Sends the cycle to the submitter's immediate manager.
     *
     * Blocking findings deliberately do NOT stop a submission — the manager has to
     * be able to see them in order to decide whether to waive them. What stops a
     * submission is an unfinished test run.
     */
    public function submit(Request $request, QatCycle $cycle)
    {
        $user = $request->user();

        abort_unless(
            $cycle->isOpen(),
            422,
            $cycle->isAwaitingApproval()
                ? 'This cycle has already been submitted and is waiting on a decision.'
                : 'A signed-off cycle cannot be submitted again.'
        );

        $readiness = $this->readinessFor($cycle);

        if (! $readiness['is_set_up']) {
            throw ValidationException::withMessages([
                'submit' => 'Add at least one test case and one active tester before submitting.',
            ]);
        }

        // Only UNANSWERED cases block. A failed case is the normal reason a cycle
        // is being escalated at all, and blocking on it would make the manager's
        // waiver — the one override the design allows — permanently unreachable.
        if (! empty($readiness['unanswered_cases'])) {
            $count = count($readiness['unanswered_cases']);
            throw ValidationException::withMessages([
                'submit' => "{$count} test case(s) still have no verdict. Finish the run before asking for sign-off.",
            ]);
        }

        // Resolved from the SUBMITTER's own chain — this is their work being
        // signed off, so it is their manager who is accountable for it.
        $resolved = $this->approvers->resolve($user, 'qat.approve');

        if (empty($resolved['ids'])) {
            throw ValidationException::withMessages([
                'submit' => 'No manager could be found who is able to approve this cycle. '
                    .'Ask an administrator to assign you a manager, or to grant the QAT approve permission to one.',
            ]);
        }

        DB::transaction(function () use ($cycle, $user, $resolved) {
            $cycle->update([
                // Frozen at submit. The org chart can change while a cycle waits,
                // and a pending decision must never be orphaned by that — this
                // snapshot, not a live re-resolution, is who may decide.
                'approver_user_ids' => $resolved['ids'],
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => QatCycle::STATUS_FOR_APPROVAL,
                'updated_by' => $user->id,
            ]);
        });

        $blocking = count($readiness['blocking_findings']);
        $message = "{$cycle->title} is ready for your sign-off.";
        if ($blocking > 0) {
            $message .= " {$blocking} unresolved blocker/major finding(s) will need resolving or waiving.";
        }

        $this->notifications->notifyApproval(
            $resolved['ids'],
            $user->id,
            'pending',
            "QAT sign-off needed: {$cycle->code}",
            $message,
            route('qat.show', $cycle->id, false).'?tab=signoff',
            'qat_cycle:'.$cycle->id,
            $blocking > 0 ? 'warning' : 'info'
        );

        $names = implode(', ', $this->approvers->names($resolved['ids']));

        return redirect()->back()->with('success', "Submitted for sign-off. Waiting on: {$names}.");
    }

    /** Withdraws a pending request so the team can keep working. */
    public function cancel(Request $request, QatCycle $cycle)
    {
        abort_unless($cycle->isAwaitingApproval(), 422, 'This cycle is not waiting on a decision.');

        $user = $request->user();

        abort_unless(
            (int) $cycle->submitted_by === (int) $user->id || $user->can('qat.edit'),
            403,
            'Only the person who submitted this cycle can withdraw it.'
        );

        $cycle->update([
            'status' => QatCycle::STATUS_TESTING,
            'approver_user_ids' => null,
            'submitted_at' => null,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Sign-off request withdrawn. The cycle is open for testing again.');
    }

    /**
     * The manager's decision.
     *
     * Two gates, both required: the caller must hold `qat.approve`, AND they must
     * be in the list snapshotted when the cycle was submitted. The permission
     * alone would let any manager in the company sign off anyone's work.
     */
    public function decide(Request $request, QatCycle $cycle)
    {
        $user = $request->user();

        abort_unless($cycle->isAwaitingApproval(), 422, 'This cycle is not waiting on a decision.');
        abort_unless(
            $cycle->isAssignedApprover($user),
            403,
            'You are not assigned to approve this QAT cycle.'
        );

        $validated = $request->validate(array_merge([
            'result' => ['required', Rule::in(array_keys(QatSignoff::results()))],
            'remarks' => 'nullable|string|max:4000',
            'waived_finding_ids' => 'nullable|array',
            'waived_finding_ids.*' => [
                'integer',
                Rule::exists('qat_findings', 'id')->where('qat_cycle_id', $cycle->id),
            ],
            'waiver_reason' => 'nullable|string|max:4000',
        ], SignatureImage::rules()));

        $accepting = in_array($validated['result'], QatSignoff::ACCEPTING_RESULTS, true);
        $waivedIds = collect($validated['waived_finding_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();

        // Rejecting is never gated — a manager must always be able to say no.
        if (! $accepting && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Say why the cycle is not accepted, so the team knows what to fix.',
            ]);
        }

        if ($validated['result'] === QatSignoff::RESULT_PASSED_WITH_RESERVATION && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Explain the reservation.',
            ]);
        }

        // THE GATE. Anything unresolved, severe and unwaived stops an acceptance.
        if ($accepting) {
            $blocking = QatFinding::where('qat_cycle_id', $cycle->id)->blocking()->get();
            $unwaived = $blocking->reject(fn (QatFinding $f) => $waivedIds->contains((int) $f->id));

            if ($unwaived->isNotEmpty()) {
                $list = $unwaived
                    ->map(fn (QatFinding $f) => "{$f->reference} ({$f->severity})")
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'result' => "These findings must be resolved or waived before sign-off: {$list}.",
                ]);
            }

            // A waiver with no reason is not a waiver, it is a rubber stamp. The
            // written reason is the entire reason the gate is overridable at all.
            if ($waivedIds->isNotEmpty() && strlen(trim((string) ($validated['waiver_reason'] ?? ''))) < 10) {
                throw ValidationException::withMessages([
                    'waiver_reason' => 'Explain why you are accepting the cycle with these findings still open (at least 10 characters).',
                ]);
            }
        }

        // Stored before the transaction: writing the file is not transactional, so
        // doing it inside would leave an orphan image if a later statement failed.
        $signaturePath = SignatureImage::store($validated['signature'] ?? null, "signatures/qat/{$cycle->id}");

        DB::transaction(function () use ($cycle, $user, $request, $validated, $accepting, $waivedIds, $signaturePath) {
            if ($accepting && $waivedIds->isNotEmpty()) {
                QatFinding::where('qat_cycle_id', $cycle->id)
                    ->whereIn('id', $waivedIds->all())
                    ->update([
                        'waived_at' => now(),
                        'waived_by_user_id' => $user->id,
                        'waiver_reason' => $validated['waiver_reason'] ?? null,
                        'updated_by' => $user->id,
                    ]);
            }

            $this->qat->recordSignoff($cycle, null, QatSignoff::STAGE_MANAGER, [
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'waived_finding_ids' => $accepting ? $waivedIds->values()->all() : [],
                'waiver_reason' => $accepting && $waivedIds->isNotEmpty() ? ($validated['waiver_reason'] ?? null) : null,
                // Copied off the cycle so the ledger stays readable even after a
                // later re-submission resolves a different set of managers.
                'resolved_approver_ids' => $cycle->approver_user_ids ?? [],
                'confirmed_by_user_id' => $user->id,
                'confirmed_name' => $user->name,
                'confirmed_email' => $user->email,
                'signature_path' => $signaturePath,
                'ip_address' => $request->ip(),
            ]);

            $cycle->update([
                'status' => $accepting ? QatCycle::STATUS_SIGNED_OFF : QatCycle::STATUS_RETURNED,
                'updated_by' => $user->id,
            ]);
        });

        // Back to whoever has a stake in the outcome, minus the actor (the
        // notification service drops them).
        $recipients = collect([
            $cycle->submitted_by, $cycle->qa_lead_id, $cycle->dev_lead_id, $cycle->created_by,
        ])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->notifications->notifyApproval(
            $recipients,
            $user->id,
            $accepting ? 'approved' : 'rejected',
            $accepting ? "QAT signed off: {$cycle->code}" : "QAT returned: {$cycle->code}",
            $accepting
                ? "{$user->name} signed off {$cycle->title}. It can now be promoted to UAT."
                : "{$user->name} returned {$cycle->title}: ".($validated['remarks'] ?? ''),
            route('qat.show', $cycle->id, false).'?tab=signoff',
            'qat_cycle:'.$cycle->id,
            $accepting ? 'success' : 'warning'
        );

        return redirect()->back()->with(
            'success',
            $accepting
                ? 'Sign-off recorded. This cycle can now be promoted to UAT.'
                : 'Cycle returned to the team.'
        );
    }

    /**
     * Creates a UAT cycle from this signed-off QAT cycle, carrying the test script
     * across but nothing else.
     *
     * Verdicts, findings, evidence, participants and dates are deliberately left
     * behind: the QAT result is history, not a pre-filled answer, and a UAT roster
     * is clients rather than internal QA staff. Starting the client-facing cycle
     * with somebody else's ticks already in it would be worse than useless.
     */
    public function promote(Request $request, QatCycle $cycle)
    {
        $user = $request->user();

        abort_unless(
            $user->can('uat.create'),
            403,
            'Promoting creates a UAT cycle, which needs the UAT create permission.'
        );

        if (! $cycle->isSignedOff()) {
            throw ValidationException::withMessages([
                'promote' => 'This cycle has not been signed off by a manager yet.',
            ]);
        }

        // Idempotent: the button becomes a link to the existing cycle rather than
        // quietly minting a second one on a double-click.
        if ($cycle->promoted_uat_cycle_id) {
            return redirect()->route('qat.show', $cycle->id)
                ->with('info', 'This cycle has already been promoted.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $uat = DB::transaction(function () use ($cycle, $validated, $user) {
            $new = $this->createUatWithUniqueCode([
                'title' => $validated['title'],
                'system_name' => $cycle->system_name,
                'description' => $cycle->description,
                'cycle_no' => 1,
                'environment' => $cycle->environment,
                'links' => $cycle->links,
                'company_id' => $cycle->company_id,
                'department_id' => $validated['department_id'] ?? $cycle->department_id,
                'qa_lead_id' => $cycle->qa_lead_id,
                'dev_lead_id' => $cycle->dev_lead_id,
                'qat_cycle_id' => $cycle->id,
                'status' => UatCycle::STATUS_DRAFT,
                'gate_on_critical_only' => $cycle->gate_on_critical_only,
            ], $user->id);

            $sectionMap = [];
            foreach ($cycle->sections as $section) {
                $sectionMap[$section->id] = UatSection::create([
                    'uat_cycle_id' => $new->id,
                    'name' => $section->name,
                    'description' => $section->description,
                    'is_critical' => $section->is_critical,
                    'order' => $section->order,
                ])->id;
            }

            foreach ($cycle->cases()->get() as $case) {
                UatCase::create([
                    'uat_cycle_id' => $new->id,
                    'uat_section_id' => $sectionMap[$case->qat_section_id] ?? null,
                    'case_key' => $case->case_key,
                    'screen' => $case->screen,
                    'title' => $case->title,
                    'description' => $case->description,
                    'steps' => $case->steps,
                    'expected_results' => $case->expected_results,
                    'is_critical' => $case->is_critical,
                    'priority' => $case->priority,
                    'order' => $case->order,
                    'source_qat_case_id' => $case->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            }

            $cycle->update([
                'promoted_uat_cycle_id' => $new->id,
                'promoted_by' => $user->id,
                'promoted_at' => now(),
                'updated_by' => $user->id,
            ]);

            return $new;
        });

        return redirect()->route('uat.show', $uat->id)
            ->with('success', "Promoted to UAT cycle {$uat->code}. Add your stakeholder roster to begin acceptance testing.");
    }

    /**
     * The sign-off certificate, rendered inline so the browser shows it in the tab
     * rather than downloading it. `stream()` sets Content-Disposition: inline;
     * `download()` would defeat the point of opening a new tab.
     */
    public function pdf(Request $request, QatCycle $cycle)
    {
        $cycle->load([
            'company:id,name',
            'department:id,name',
            'qaLead:id,name',
            'devLead:id,name',
            'managerSignoff.confirmedBy:id,name,email',
        ]);

        $signoff = $cycle->managerSignoff;

        $cases = $cycle->cases()->get(self::CASE_LIST_COLUMNS);
        $results = $cycle->results()->get(['id', 'qat_case_id', 'qat_participant_id', 'result']);
        $participants = $cycle->participants()->get();

        // The waiver list comes off the signed record, not off today's findings:
        // the certificate must show what was true when it was signed, even if a
        // finding has since been resolved or re-opened.
        $waivedFindings = collect();
        if ($signoff && ! empty($signoff->waived_finding_ids)) {
            $waivedFindings = QatFinding::whereIn('id', $signoff->waived_finding_ids)
                ->orderBy('reference')
                ->get(['id', 'reference', 'severity', 'title']);
        }

        $pdf = Pdf::loadView('pdf.testing-signoff', [
            'module' => 'QAT — Quality Assurance Testing',
            'approverTitle' => 'Approving manager',
            'cycle' => $cycle,
            'signoff' => $signoff,
            'statistics' => $this->qat->statistics($cases, $results, $participants),
            'waivedFindings' => $waivedFindings,
            'signatureDataUri' => SignatureImage::dataUri($signoff?->signature_path),
            'resultLabels' => QatSignoff::results(),
            'ledger' => $cycle->signoffs()
                ->where('stage', QatSignoff::STAGE_MANAGER)
                ->orderByDesc('confirmed_at')
                ->get(),
            'generatedBy' => $request->user()?->name,
        ])->setPaper('a4');

        return $pdf->stream("{$cycle->code}-signoff.pdf");
    }

    /** Retries on the UAT code unique-index race, mirroring UatController. */
    private function createUatWithUniqueCode(array $attributes, int $userId): UatCycle
    {
        foreach (range(1, 3) as $attempt) {
            try {
                return UatCycle::create(array_merge($attributes, [
                    'code' => UatCycle::nextCode(),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]));
            } catch (Throwable $e) {
                if ($attempt === 3 || ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['promote' => 'Could not allocate a UAT cycle code. Please try again.']);
    }

    private function readinessFor(QatCycle $cycle): array
    {
        return $this->qat->readiness(
            $cycle,
            $cycle->cases()->get(self::CASE_LIST_COLUMNS),
            $cycle->results()->get(['id', 'qat_case_id', 'qat_participant_id', 'result']),
            $cycle->findings()->get(),
            $cycle->participants()->get(),
        );
    }
}
