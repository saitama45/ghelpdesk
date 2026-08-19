<?php

namespace App\Http\Controllers;

use App\Models\QatCycle;
use App\Models\QatEvidence;
use App\Models\QatFinding;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The QAT defect register — assignable, retestable, closable, and escalatable
 * into a real helpdesk ticket.
 *
 * Sibling of {@see UatFindingController}. Kept as a separate copy rather than a
 * shared trait: extracting one would mean editing the shipped UAT controller, and
 * the ticket-conversion path is the highest-consequence code in either module.
 * The dedupe is a clean follow-up once both are covered by browser QA.
 */
class QatFindingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Raising a finding is part of testing, not of editing the cycle.
            new Middleware('can:qat.execute', only: ['store']),
            new Middleware('can:qat.edit', only: ['update', 'convertToTicket']),
            new Middleware('can:qat.delete', only: ['destroy']),
        ];
    }

    public function store(Request $request, QatCycle $cycle)
    {
        // Not `required` here: a finding raised against a test case inherits the
        // screenshots already attached to that case's verdicts, so the tester is
        // not asked for the same picture twice. The real check is below, once both
        // sources are known.
        $validated = $request->validate(
            array_merge($this->rules($cycle), $this->screenshotRules(required: false)),
            [
                'screenshots.*.image' => 'Evidence must be an image (PNG, JPG, GIF or WEBP).',
                'screenshots.*.max' => 'Each screenshot must be 10 MB or smaller.',
            ]
        );

        $inherited = 0;

        $finding = DB::transaction(function () use ($request, $cycle, $validated, &$inherited) {
            $finding = QatFinding::create(array_merge(
                collect($validated)->except('screenshots')->all(),
                [
                    'qat_cycle_id' => $cycle->id,
                    'reference' => QatFinding::nextReference($cycle->id),
                    'status' => $validated['status'] ?? QatFinding::STATUS_OPEN,
                    'reported_by_user_id' => $request->user()->id,
                    'reported_by_name' => $request->user()->name,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]
            ));

            $uploaded = $this->storeScreenshots($request, $cycle, $finding);
            $inherited = $this->inheritCaseEvidence($cycle, $finding);

            // Rolls the whole thing back — no half-created finding.
            if ($uploaded + $inherited === 0) {
                throw ValidationException::withMessages([
                    'screenshots' => 'Attach at least one screenshot of the defect.',
                ]);
            }

            return $finding;
        });

        $total = $finding->evidence()->count();
        $note = $inherited > 0 ? " ({$inherited} carried over from the test evidence)" : '';

        return redirect()->back()->with(
            'success',
            "Finding {$finding->reference} logged with {$total} screenshot(s){$note}."
        );
    }

    public function update(Request $request, QatCycle $cycle, QatFinding $finding)
    {
        abort_unless($finding->qat_cycle_id === $cycle->id, 404);

        $validated = $request->validate(array_merge(
            $this->rules($cycle),
            ['resolution_notes' => 'nullable|string|max:4000'],
            // Editing can add more screenshots; it never demands them, since the
            // finding already had to carry one to exist.
            $this->screenshotRules(required: false)
        ));

        $this->storeScreenshots($request, $cycle, $finding);
        $validated = collect($validated)->except('screenshots')->all();

        $status = $validated['status'] ?? $finding->status;

        // Closing without saying how it was resolved is how a register rots.
        if ($status === QatFinding::STATUS_CLOSED && blank($validated['resolution_notes'] ?? $finding->resolution_notes)) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Add a resolution note before closing this finding.',
            ]);
        }

        $validated['resolved_at'] = in_array($status, [QatFinding::STATUS_CLOSED, QatFinding::STATUS_DEFERRED], true)
            ? ($finding->resolved_at ?? now())
            : null;

        // Resolving a waived finding clears the waiver: the manager's override was
        // a decision to live with an OPEN defect, and it must not silently persist
        // as a permanent exemption once the defect is dealt with.
        if (in_array($status, [QatFinding::STATUS_CLOSED, QatFinding::STATUS_DEFERRED], true) && $finding->isWaived()) {
            $validated['waived_at'] = null;
            $validated['waived_by_user_id'] = null;
            $validated['waiver_reason'] = null;
        }

        $validated['updated_by'] = $request->user()->id;

        $finding->update($validated);

        return redirect()->back()->with('success', "Finding {$finding->reference} updated.");
    }

    public function destroy(QatCycle $cycle, QatFinding $finding)
    {
        abort_unless($finding->qat_cycle_id === $cycle->id, 404);

        $reference = $finding->reference;

        DB::transaction(function () use ($finding) {
            foreach (QatEvidence::where('qat_finding_id', $finding->id)->get() as $evidence) {
                Storage::disk('public')->delete($evidence->file_path);
                $evidence->delete();
            }

            $finding->delete();
        });

        return redirect()->back()->with('success', "Finding {$reference} deleted.");
    }

    /**
     * Raises a helpdesk ticket for this finding and links the two.
     *
     * Deliberately manual rather than automatic on every failure: a long cycle
     * produces far more failed verdicts than it does pieces of real work, and
     * flooding the queue is what makes teams stop trusting it.
     */
    public function convertToTicket(Request $request, QatCycle $cycle, QatFinding $finding)
    {
        abort_unless($finding->qat_cycle_id === $cycle->id, 404);

        if ($finding->ticket_id) {
            return redirect()->back()->with('info', "Finding {$finding->reference} is already linked to a ticket.");
        }

        $validated = $request->validate(array_merge([
            'assignee_id' => 'nullable|exists:users,id',
            'serving_department_id' => 'nullable|exists:departments,id',
            'store_id' => 'nullable|exists:stores,id',
        ], $this->screenshotRules(required: false)));

        $this->storeScreenshots($request, $cycle, $finding);

        if (! $finding->evidence()->exists()) {
            throw ValidationException::withMessages([
                'screenshots' => 'Attach at least one screenshot before raising a ticket — the fixer needs to see the defect.',
            ]);
        }

        $ticket = DB::transaction(function () use ($request, $cycle, $finding, $validated) {
            $finding->loadMissing(['testCase:id,case_key,title', 'evidence']);

            $context = array_filter([
                'QAT cycle' => "{$cycle->code} — {$cycle->title}",
                'Test case' => $finding->testCase
                    ? "{$finding->testCase->case_key} — {$finding->testCase->title}"
                    : null,
                'Severity' => QatFinding::severities()[$finding->severity] ?? $finding->severity,
                'Reported by' => $finding->reported_by_name,
            ]);

            $description = collect($context)
                ->map(fn ($value, $label) => "{$label}: {$value}")
                ->implode("\n")."\n\n".($finding->details ?? '');

            $ticket = Ticket::create([
                'title' => mb_substr("[{$finding->reference}] {$finding->title}", 0, 255),
                'description' => trim($description),
                'type' => 'bug',
                'status' => 'open',
                'priority' => QatFinding::SEVERITY_TO_PRIORITY[$finding->severity] ?? 'medium',
                'severity' => $finding->severity === QatFinding::SEVERITY_BLOCKER ? 'critical' : 'minor',
                'reporter_id' => $request->user()->id,
                'sender_name' => $request->user()->name,
                'sender_email' => $request->user()->email,
                'assignee_id' => $validated['assignee_id'] ?? null,
                'company_id' => $cycle->company_id,
                'store_id' => $validated['store_id'] ?? null,
                // The desk that has to deliver the fix; the cycle's own department
                // is the requester side of the axis.
                'serving_department_id' => $validated['serving_department_id'] ?? $finding->department_id,
                'department_id' => $cycle->department_id,
            ]);

            $this->copyEvidenceToTicket($finding, $ticket);

            $finding->update([
                'ticket_id' => $ticket->id,
                'status' => $finding->status === QatFinding::STATUS_OPEN
                    ? QatFinding::STATUS_IN_PROGRESS
                    : $finding->status,
                'assigned_to_user_id' => $validated['assignee_id'] ?? $finding->assigned_to_user_id,
                'updated_by' => $request->user()->id,
            ]);

            return $ticket;
        });

        $shots = $finding->evidence()->count();

        return redirect()->back()->with(
            'success',
            "Ticket {$ticket->ticket_key} raised for finding {$finding->reference} with {$shots} screenshot(s) attached."
        );
    }

    /**
     * Screenshot evidence. Multiple files are allowed — one picture rarely tells
     * the whole story of a defect.
     */
    private function screenshotRules(bool $required): array
    {
        return [
            'screenshots' => ($required ? 'required' : 'nullable').'|array|max:10',
            'screenshots.*' => 'file|image|mimes:png,jpg,jpeg,gif,webp|max:10240',
        ];
    }

    private function storeScreenshots(Request $request, QatCycle $cycle, QatFinding $finding): int
    {
        if (! $request->hasFile('screenshots')) {
            return 0;
        }

        $stored = 0;

        foreach ($request->file('screenshots') as $file) {
            QatEvidence::create([
                'qat_cycle_id' => $cycle->id,
                'qat_finding_id' => $finding->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("qat/{$cycle->id}", 'public'),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()?->id,
                'uploaded_by_name' => $request->user()?->name,
            ]);
            $stored++;
        }

        return $stored;
    }

    /**
     * Carries the screenshots already attached to a case's verdicts across to a
     * finding raised against that case.
     *
     * The tester has usually just uploaded the picture while recording the
     * failure; making them attach it again to the finding is pure friction.
     *
     * The file is physically copied rather than the row re-pointed: evidence rows
     * own their file and delete it on removal, so sharing one path would mean
     * deleting a finding could blank a verdict's evidence.
     */
    private function inheritCaseEvidence(QatCycle $cycle, QatFinding $finding): int
    {
        if (! $finding->qat_case_id) {
            return 0;
        }

        $sources = QatEvidence::where('qat_cycle_id', $cycle->id)
            ->whereNotNull('qat_case_result_id')
            ->whereIn('qat_case_result_id', function ($q) use ($finding) {
                $q->select('id')->from('qat_case_results')->where('qat_case_id', $finding->qat_case_id);
            })
            ->get();

        $copied = 0;

        foreach ($sources as $source) {
            if (! Storage::disk('public')->exists($source->file_path)) {
                continue;
            }

            $target = "qat/{$cycle->id}/".Str::random(40).'.'.pathinfo($source->file_path, PATHINFO_EXTENSION);

            if (! Storage::disk('public')->copy($source->file_path, $target)) {
                continue;
            }

            QatEvidence::create([
                'qat_cycle_id' => $cycle->id,
                'qat_finding_id' => $finding->id,
                'label' => $source->label,
                'file_name' => $source->file_name,
                'file_path' => $target,
                'mime_type' => $source->mime_type,
                'file_size' => $source->file_size,
                'uploaded_by_user_id' => $source->uploaded_by_user_id,
                'uploaded_by_name' => $source->uploaded_by_name,
            ]);

            $copied++;
        }

        return $copied;
    }

    /**
     * Copies the finding's evidence onto the ticket so whoever picks the ticket up
     * sees the screenshot without having to open the QAT module. Copied, not moved
     * — the evidence stays attached to the finding as the audit record.
     */
    private function copyEvidenceToTicket(QatFinding $finding, Ticket $ticket): void
    {
        foreach ($finding->evidence as $evidence) {
            if (! Storage::disk('public')->exists($evidence->file_path)) {
                continue;
            }

            $target = 'ticket-attachments/'.time().'_'.basename($evidence->file_path);

            if (! Storage::disk('public')->copy($evidence->file_path, $target)) {
                continue;
            }

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'file_name' => $evidence->file_name,
                'file_storage_path' => str_replace('\\', '/', $target),
                'file_size_bytes' => $evidence->file_size,
            ]);
        }
    }

    private function rules(QatCycle $cycle): array
    {
        return [
            'qat_case_id' => ['nullable', Rule::exists('qat_cases', 'id')->where('qat_cycle_id', $cycle->id)],
            'qat_participant_id' => ['nullable', Rule::exists('qat_participants', 'id')->where('qat_cycle_id', $cycle->id)],
            'title' => 'required|string|max:255',
            'details' => 'nullable|string|max:8000',
            'severity' => ['required', Rule::in(array_keys(QatFinding::severities()))],
            'status' => ['nullable', Rule::in(array_keys(QatFinding::statuses()))],
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
        ];
    }
}
