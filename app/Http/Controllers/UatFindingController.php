<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\UatCycle;
use App\Models\UatEvidence;
use App\Models\UatFinding;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The defect register. In the source workbook these were numbered paragraphs in
 * a Remarks cell; here each is a row that can be assigned, retested, closed, and
 * escalated into a real helpdesk ticket.
 */
class UatFindingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Raising a finding is part of testing, not of editing the cycle.
            new Middleware('can:uat.execute', only: ['store']),
            new Middleware('can:uat.edit', only: ['update', 'convertToTicket']),
            new Middleware('can:uat.delete', only: ['destroy']),
        ];
    }

    public function store(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate(
            array_merge(
                $this->rules($cycle),
                // A defect report with no picture of the defect is rarely
                // actionable, so evidence is mandatory at the point of logging.
                $this->screenshotRules(required: true)
            ),
            [
                'screenshots.required' => 'Attach at least one screenshot of the defect.',
                'screenshots.*.image' => 'Evidence must be an image (PNG, JPG, GIF or WEBP).',
                'screenshots.*.max' => 'Each screenshot must be 10 MB or smaller.',
            ]
        );

        $finding = DB::transaction(function () use ($request, $cycle, $validated) {
            $finding = UatFinding::create(array_merge(
                collect($validated)->except('screenshots')->all(),
                [
                    'uat_cycle_id' => $cycle->id,
                    'reference' => UatFinding::nextReference($cycle->id),
                    'status' => $validated['status'] ?? UatFinding::STATUS_OPEN,
                    'reported_by_user_id' => $request->user()->id,
                    'reported_by_name' => $request->user()->name,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]
            ));

            $this->storeScreenshots($request, $cycle, $finding);

            return $finding;
        });

        return redirect()->back()->with('success', "Finding {$finding->reference} logged with evidence.");
    }

    public function update(Request $request, UatCycle $cycle, UatFinding $finding)
    {
        abort_unless($finding->uat_cycle_id === $cycle->id, 404);

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
        if ($status === UatFinding::STATUS_CLOSED && blank($validated['resolution_notes'] ?? $finding->resolution_notes)) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Add a resolution note before closing this finding.',
            ]);
        }

        $validated['resolved_at'] = in_array($status, [UatFinding::STATUS_CLOSED, UatFinding::STATUS_DEFERRED], true)
            ? ($finding->resolved_at ?? now())
            : null;
        $validated['updated_by'] = $request->user()->id;

        $finding->update($validated);

        return redirect()->back()->with('success', "Finding {$finding->reference} updated.");
    }

    public function destroy(UatCycle $cycle, UatFinding $finding)
    {
        abort_unless($finding->uat_cycle_id === $cycle->id, 404);

        $reference = $finding->reference;

        DB::transaction(function () use ($finding) {
            foreach (UatEvidence::where('uat_finding_id', $finding->id)->get() as $evidence) {
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
     * Deliberately manual rather than automatic on every failure: a 96-case
     * cycle produces far more failed verdicts than it does pieces of real work,
     * and flooding the queue is what makes teams stop trusting it.
     */
    public function convertToTicket(Request $request, UatCycle $cycle, UatFinding $finding)
    {
        abort_unless($finding->uat_cycle_id === $cycle->id, 404);

        if ($finding->ticket_id) {
            return redirect()->back()->with('info', "Finding {$finding->reference} is already linked to a ticket.");
        }

        $validated = $request->validate(array_merge([
            'assignee_id' => 'nullable|exists:users,id',
            'serving_department_id' => 'nullable|exists:departments,id',
            'store_id' => 'nullable|exists:stores,id',
        ], $this->screenshotRules(required: false)));

        // Findings logged before evidence was mandatory — and anything raised
        // from the client portal, which has no uploader — can still be attached
        // to here, but a ticket never leaves without a screenshot.
        $this->storeScreenshots($request, $cycle, $finding);

        if (!$finding->evidence()->exists()) {
            throw ValidationException::withMessages([
                'screenshots' => 'Attach at least one screenshot before raising a ticket — the fixer needs to see the defect.',
            ]);
        }

        $ticket = DB::transaction(function () use ($request, $cycle, $finding, $validated) {
            $finding->loadMissing(['testCase:id,case_key,title', 'evidence']);

            $context = array_filter([
                'UAT cycle' => "{$cycle->code} — {$cycle->title}",
                'Test case' => $finding->testCase
                    ? "{$finding->testCase->case_key} — {$finding->testCase->title}"
                    : null,
                'Severity' => UatFinding::severities()[$finding->severity] ?? $finding->severity,
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
                'priority' => UatFinding::SEVERITY_TO_PRIORITY[$finding->severity] ?? 'medium',
                'severity' => $finding->severity === UatFinding::SEVERITY_BLOCKER ? 'critical' : 'minor',
                'reporter_id' => $request->user()->id,
                'sender_name' => $request->user()->name,
                'sender_email' => $request->user()->email,
                'assignee_id' => $validated['assignee_id'] ?? null,
                'company_id' => $cycle->company_id,
                'store_id' => $validated['store_id'] ?? null,
                // The desk that has to deliver the fix; the cycle's own
                // department is the requester side of the axis.
                'serving_department_id' => $validated['serving_department_id'] ?? $finding->department_id,
                'department_id' => $cycle->department_id,
            ]);

            $this->copyEvidenceToTicket($finding, $ticket);

            $finding->update([
                'ticket_id' => $ticket->id,
                'status' => $finding->status === UatFinding::STATUS_OPEN
                    ? UatFinding::STATUS_IN_PROGRESS
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

    /** Persists uploaded screenshots as evidence against the finding. */
    private function storeScreenshots(Request $request, UatCycle $cycle, UatFinding $finding): int
    {
        if (!$request->hasFile('screenshots')) {
            return 0;
        }

        $stored = 0;

        foreach ($request->file('screenshots') as $file) {
            UatEvidence::create([
                'uat_cycle_id' => $cycle->id,
                'uat_finding_id' => $finding->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store("uat/{$cycle->id}", 'public'),
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
     * Copies the finding's evidence onto the ticket so whoever picks the ticket
     * up sees the screenshot without having to open the UAT module. Copied, not
     * moved — the evidence stays attached to the finding as the audit record.
     */
    private function copyEvidenceToTicket(UatFinding $finding, Ticket $ticket): void
    {
        foreach ($finding->evidence as $evidence) {
            if (!Storage::disk('public')->exists($evidence->file_path)) {
                continue;
            }

            $target = 'ticket-attachments/'.time().'_'.basename($evidence->file_path);

            if (!Storage::disk('public')->copy($evidence->file_path, $target)) {
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

    private function rules(UatCycle $cycle): array
    {
        return [
            'uat_case_id' => ['nullable', Rule::exists('uat_cases', 'id')->where('uat_cycle_id', $cycle->id)],
            'uat_participant_id' => ['nullable', Rule::exists('uat_participants', 'id')->where('uat_cycle_id', $cycle->id)],
            'title' => 'required|string|max:255',
            'details' => 'nullable|string|max:8000',
            'severity' => ['required', Rule::in(array_keys(UatFinding::severities()))],
            'status' => ['nullable', Rule::in(array_keys(UatFinding::statuses()))],
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
        ];
    }
}
