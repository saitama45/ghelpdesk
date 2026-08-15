<?php

namespace App\Http\Controllers;

use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
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

        // Only this participant's column — never the whole matrix.
        $results = UatCaseResult::where('uat_participant_id', $participant->id)
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
        ]);

        // Reporting a problem without describing it wastes everyone's next hour.
        if (in_array($validated['result'], [UatCaseResult::FAILED, UatCaseResult::BLOCKED], true)
            && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Please describe what went wrong so the team can act on it.',
            ]);
        }

        UatCaseResult::updateOrCreate(
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

        return redirect()->back()->with('success', 'Saved. Thank you.');
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
        ]);

        $finding = UatFinding::create(array_merge($validated, [
            'uat_cycle_id' => $cycle->id,
            'uat_participant_id' => $participant->id,
            'reference' => UatFinding::nextReference($cycle->id),
            'status' => UatFinding::STATUS_OPEN,
            'reported_by_name' => $participant->display_name,
        ]));

        return redirect()->back()->with('success', "Reported as {$finding->reference}. The team has been notified.");
    }

    public function storeSignoff(Request $request, string $token)
    {
        $participant = $this->participantForToken($token);
        $cycle = $participant->cycle;

        abort_unless($participant->isApprover(), 403, 'You are not nominated to sign off on this cycle.');

        $validated = $request->validate([
            'result' => ['required', Rule::in(array_keys(UatSignoff::results()))],
            'remarks' => 'nullable|string|max:4000',
            // Typing your own name is the acknowledgement, as on the paper pack.
            'confirmed_name' => 'required|string|max:255',
        ]);

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
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', 'Your acceptance has been recorded. Thank you.');
    }
}
