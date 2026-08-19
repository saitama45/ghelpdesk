<?php

namespace App\Http\Controllers;

use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
use App\Models\UatEvidence;
use App\Models\UatFinding;
use App\Models\UatParticipant;
use App\Models\UatSignoff;
use App\Services\UatService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * No-login portal for external stakeholders and clients.
 *
 * Each participant on the roster can be issued a signed token; that token is the
 * whole credential, so every action here is scoped to the one participant it
 * belongs to and nothing about the cycle beyond their own column is exposed.
 */
class PublicUatController extends Controller
{
    public function __construct(private UatService $uat) {}

    /**
     * Resolves a token to its participant, or 403s. Deliberately does not
     * distinguish "unknown" from "expired" from "revoked" — a probe learns
     * nothing either way.
     */
    private function participantForToken(string $token): UatParticipant
    {
        $participant = UatParticipant::where('access_token', $token)
            ->with(['cycle', 'department:id,name', 'company:id,name'])
            ->first();

        if (!$participant || !$participant->tokenIsValid()) {
            abort(403, 'This access link is no longer valid. Please ask your project contact for a new one.');
        }

        return $participant;
    }

    public function portal(string $token)
    {
        $participant = $this->participantForToken($token);
        $cycle = $participant->cycle;

        $participant->forceFill(['last_accessed_at' => now()])->save();

        $cases = $cycle->cases()->get([
            'id', 'uat_cycle_id', 'uat_section_id', 'case_key', 'screen', 'title', 'is_critical', 'priority', 'order',
        ]);

        // Only this participant's column — never the whole matrix. Evidence rides
        // along so a client can see the screenshots they already sent.
        $results = UatCaseResult::where('uat_participant_id', $participant->id)
            ->with('evidence')
            ->get(['id', 'uat_case_id', 'result', 'remarks', 'executed_at']);

        $signoff = $participant->currentSignoff()->first();

        return Inertia::render('Public/UatPortal', [
            'token' => $token,
            'participant' => [
                'id' => $participant->id,
                'label' => $participant->label,
                'role' => $participant->role,
                'name' => $participant->display_name,
                'email' => $participant->display_email,
                'is_approver' => $participant->isApprover(),
                'can_record' => $participant->canRecordVerdicts(),
            ],
            'cycle' => [
                'code' => $cycle->code,
                'title' => $cycle->title,
                'system_name' => $cycle->system_name,
                'description' => $cycle->description,
                'environment' => $cycle->environment,
                'cycle_no' => $cycle->cycle_no,
                'status' => $cycle->status,
                'links' => $cycle->links,
                'target_signoff_date' => $cycle->target_signoff_date?->toDateString(),
                'is_open' => $cycle->isOpen(),
            ],
            'sections' => $cycle->sections()->get(['id', 'name', 'description', 'order']),
            'cases' => $cases,
            'results' => $results,
            'signoff' => $signoff ? [
                'result' => $signoff->result,
                'remarks' => $signoff->remarks,
                'confirmed_at' => $signoff->confirmed_at?->toIso8601String(),
            ] : null,
            // An approver signs off for the whole cycle, so they need to see what
            // the testers actually reported. Without this they were asked to
            // accept a round while looking at an empty checklist of their own.
            'reportedProblems' => $participant->isApprover()
                ? $this->reportedProblems($cycle, $participant)
                : [],
            // Approvers review rather than test independently, so their checklist
            // mirrors each tester's full answer — verdict, note and screenshots —
            // above their own. Testers deliberately do NOT get this: seeing a
            // colleague's answer first would bias their own.
            'otherAnswers' => $participant->isApprover()
                ? $this->otherAnswers($cycle, $participant)
                : [],
            'options' => [
                'results' => collect(UatCaseResult::results())
                    ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values()->all(),
                'signoffResults' => collect(UatSignoff::results())
                    ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values()->all(),
                'severities' => collect(UatFinding::severities())
                    ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values()->all(),
            ],
        ]);
    }

    /**
     * Everyone else's answers, keyed by case id — the same shape the tester sees
     * for their own, so an approver's checklist mirrors it rather than
     * summarising it.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function otherAnswers(UatCycle $cycle, UatParticipant $approver): array
    {
        return UatCaseResult::where('uat_cycle_id', $cycle->id)
            ->where('uat_participant_id', '!=', $approver->id)
            ->where('result', '!=', UatCaseResult::PENDING)
            ->with(['participant:id,label,role', 'evidence'])
            ->get(['id', 'uat_case_id', 'uat_participant_id', 'result', 'remarks', 'executed_at', 'executed_by_name'])
            ->groupBy('uat_case_id')
            ->map(fn ($group) => $group->map(fn (UatCaseResult $r) => [
                'id' => $r->id,
                'label' => $r->participant?->label,
                'role' => $r->participant?->role,
                'name' => $r->executed_by_name,
                'result' => $r->result,
                'remarks' => $r->remarks,
                'answered_at' => $r->executed_at?->toIso8601String(),
                'evidence' => $r->evidence->map(fn ($e) => [
                    'id' => $e->id,
                    'file_name' => $e->file_name,
                    'url' => $e->url,
                    'is_image' => $e->is_image,
                ])->all(),
            ])->values()->all())
            ->all();
    }

    /**
     * Every problem other participants have reported on this cycle, so an
     * approver can judge what they are being asked to accept.
     *
     * @return array<int,array<string,mixed>>
     */
    private function reportedProblems(UatCycle $cycle, UatParticipant $approver): array
    {
        return UatCaseResult::where('uat_cycle_id', $cycle->id)
            ->whereIn('result', [UatCaseResult::FAILED, UatCaseResult::BLOCKED])
            ->with(['testCase:id,case_key,title', 'participant:id,label', 'evidence'])
            ->orderBy('uat_case_id')
            ->get()
            ->map(fn (UatCaseResult $row) => [
                'id' => $row->id,
                'case_key' => $row->testCase?->case_key,
                'case_title' => $row->testCase?->title,
                'result' => $row->result,
                'remarks' => $row->remarks,
                'reported_by' => $row->executed_by_name ?: $row->participant?->label,
                'participant_label' => $row->participant?->label,
                // Their own column is shown on the checklist tab already.
                'is_mine' => $row->uat_participant_id === $approver->id,
                'evidence' => $row->evidence->map(fn ($e) => [
                    'id' => $e->id,
                    'file_name' => $e->file_name,
                    'url' => $e->url,
                    'is_image' => $e->is_image,
                ])->all(),
            ])
            ->all();
    }

    /** Full text of one case — steps and expected results, fetched on demand. */
    public function caseDetail(string $token, UatCase $case)
    {
        $participant = $this->participantForToken($token);
        abort_unless($case->uat_cycle_id === $participant->uat_cycle_id, 404);

        $result = UatCaseResult::where('uat_case_id', $case->id)
            ->where('uat_participant_id', $participant->id)
            ->first();

        return response()->json([
            'case' => $case->only([
                'id', 'case_key', 'screen', 'title', 'description', 'steps', 'expected_results', 'is_critical', 'priority',
            ]),
            'result' => $result?->only(['id', 'result', 'remarks', 'executed_at']),
        ]);
    }

    public function storeVerdict(Request $request, string $token)
    {
        $participant = $this->participantForToken($token);
        $cycle = $participant->cycle;

        abort_unless($participant->canRecordVerdicts(), 403, 'Your access is read-only.');
        abort_unless($cycle->isOpen(), 422, 'This cycle is closed to new verdicts.');

        $validated = $request->validate([
            'uat_case_id' => ['required', Rule::exists('uat_cases', 'id')->where('uat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(UatCaseResult::results()))],
            'remarks' => 'nullable|string|max:8000',
            'screenshots' => 'nullable|array|max:10',
            'screenshots.*' => 'file|image|mimes:png,jpg,jpeg,gif,webp|max:10240',
        ]);

        $isProblem = in_array($validated['result'], [UatCaseResult::FAILED, UatCaseResult::BLOCKED], true);

        // Reporting a problem without describing it wastes everyone's next hour.
        if ($isProblem && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Please describe what went wrong so the team can act on it.',
            ]);
        }

        // "Has a problem" must come with a picture of the problem. "Couldn't
        // test it" often has nothing to capture, so evidence stays optional there.
        if ($validated['result'] === UatCaseResult::FAILED && !$request->hasFile('screenshots')) {
            throw ValidationException::withMessages([
                'screenshots' => 'Please attach a screenshot showing the problem.',
            ]);
        }

        $result = UatCaseResult::updateOrCreate(
            [
                'uat_case_id' => $validated['uat_case_id'],
                'uat_participant_id' => $participant->id,
            ],
            [
                'uat_cycle_id' => $cycle->id,
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'executed_at' => $validated['result'] === UatCaseResult::PENDING ? null : now(),
                'executed_by_name' => $participant->display_name,
                'source' => 'public',
            ]
        );

        $this->storeScreenshots($request, $cycle, $participant, $result);

        return redirect()->back()->with('success', 'Saved. Thank you.');
    }

    /**
     * Saves screenshots from the portal against a verdict or a finding.
     *
     * Uploads arrive from an unauthenticated visitor, so they are constrained to
     * images by validation and recorded under the participant's name rather than
     * a user id.
     */
    private function storeScreenshots(
        Request $request,
        UatCycle $cycle,
        UatParticipant $participant,
        ?UatCaseResult $result = null,
        ?UatFinding $finding = null
    ): int {
        if (!$request->hasFile('screenshots')) {
            return 0;
        }

        $stored = 0;

        foreach ($request->file('screenshots') as $file) {
            UatEvidence::create([
                'uat_cycle_id' => $cycle->id,
                'uat_case_result_id' => $result?->id,
                'uat_finding_id' => $finding?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("uat/{$cycle->id}", 'public'),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_name' => $participant->display_name,
            ]);
            $stored++;
        }

        return $stored;
    }

    /** Stakeholders can raise a finding directly rather than burying it in remarks. */
    public function storeFinding(Request $request, string $token)
    {
        $participant = $this->participantForToken($token);
        $cycle = $participant->cycle;

        abort_unless($participant->canRecordVerdicts(), 403, 'Your access is read-only.');
        abort_unless($cycle->isOpen(), 422, 'This cycle is closed.');

        $validated = $request->validate([
            'uat_case_id' => ['nullable', Rule::exists('uat_cases', 'id')->where('uat_cycle_id', $cycle->id)],
            'title' => 'required|string|max:255',
            'details' => 'nullable|string|max:8000',
            'severity' => ['required', Rule::in(array_keys(UatFinding::severities()))],
            // Same standard as an internally-logged finding.
            'screenshots' => 'required|array|min:1|max:10',
            'screenshots.*' => 'file|image|mimes:png,jpg,jpeg,gif,webp|max:10240',
        ]);

        $finding = UatFinding::create(array_merge(
            collect($validated)->except('screenshots')->all(),
            [
                'uat_cycle_id' => $cycle->id,
                'uat_participant_id' => $participant->id,
                'reference' => UatFinding::nextReference($cycle->id),
                'status' => UatFinding::STATUS_OPEN,
                'reported_by_name' => $participant->display_name,
            ]
        ));

        $this->storeScreenshots($request, $cycle, $participant, null, $finding);

        return redirect()->back()->with('success', "Reported as {$finding->reference}. The team has been notified.");
    }

    public function storeSignoff(Request $request, string $token)
    {
        $participant = $this->participantForToken($token);
        $cycle = $participant->cycle;

        abort_unless($participant->isApprover(), 403, 'You are not nominated to sign off on this cycle.');

        $validated = $request->validate(array_merge([
            'result' => ['required', Rule::in(array_keys(UatSignoff::results()))],
            'remarks' => 'nullable|string|max:4000',
            // Typing your own name is the acknowledgement, as on the paper pack.
            'confirmed_name' => 'required|string|max:255',
        ], \App\Support\SignatureImage::rules()));

        if ($validated['result'] !== UatSignoff::RESULT_PASSED && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Please explain the reservation or the reason for not accepting.',
            ]);
        }

        $this->uat->recordSignoff($cycle, $participant, UatSignoff::STAGE_ACCEPTANCE, [
            'result' => $validated['result'],
            'remarks' => $validated['remarks'] ?? null,
            'confirmed_name' => $validated['confirmed_name'],
            'confirmed_email' => $participant->display_email,
            // Optional here, unlike the typed name: a stakeholder signing on a
            // phone may not manage a legible finger-drawn signature, and refusing
            // their acceptance over it would be absurd.
            'signature_path' => \App\Support\SignatureImage::store(
                $validated['signature'] ?? null,
                "signatures/uat/{$cycle->id}"
            ),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', 'Your acceptance has been recorded. Thank you.');
    }
}
