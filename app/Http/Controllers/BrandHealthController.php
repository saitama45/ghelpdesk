<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Services\BrandHealthService;
use App\Services\NotificationService;
use App\Support\CompanyContext;
use App\Support\DashboardPeriod;
use App\Support\TicketStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Write actions behind the Live Brand Health tab's WCF confirmation register.
 *
 * Each row is a ticket awaiting brand (client) confirmation. The brand owner's
 * verdict is applied here: Resolved closes the ticket, Not Resolved returns it to
 * Open with the latest evidence. Kept separate from the read-only dashboard build.
 */
class BrandHealthController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private BrandHealthService $brandHealth,
    ) {}

    /**
     * Drill-down behind the Top 10 Sub-categories / Top 10 Stores lists: the tickets
     * for one brand slice, plus the per-item roll-up shown above them.
     */
    public function tickets(Request $request)
    {
        abort_unless($request->user()->can('tickets.view'), 403);

        $validated = $request->validate($this->drillDownRules());

        return response()->json($this->brandHealth->tickets($validated, $this->effectiveCompanyIds($request)));
    }

    /**
     * The open drill-down as an .xlsx: every ticket in the slice (the modal stops at
     * 500 rows) and, for a sub-category, its Top 10 Stores with each store's tickets.
     */
    public function exportTickets(Request $request)
    {
        abort_unless($request->user()->can('tickets.view'), 403);

        $validated = $request->validate($this->drillDownRules());
        $slice = $this->brandHealth->ticketsForExport($validated, $this->effectiveCompanyIds($request));
        $stores = $slice['stores'];

        $spreadsheet = new Spreadsheet();

        $this->writeSheet(
            $spreadsheet->getActiveSheet()->setTitle('Tickets'),
            ['Ticket #', 'Store Code', 'Store Name', 'Brand', 'Sub-category', 'Issue', 'Item', 'Concern', 'Assignee', 'Status', 'Created At'],
            $slice['tickets']->map(fn (Ticket $ticket) => [
                $ticket->ticket_key ?? (string) $ticket->id,
                $ticket->store?->code,
                $ticket->store?->name,
                $this->brandHealth->storeBrandLabel($stores->get($ticket->store_id)),
                $ticket->subCategory?->name ?? 'Unspecified',
                ...$this->ticketCells($ticket),
            ])
        );

        if ($slice['top_stores']->isNotEmpty()) {
            $rows = [];
            foreach ($slice['top_stores'] as $index => $row) {
                foreach ($row['tickets'] as $ticket) {
                    $rows[] = [
                        $index + 1,
                        $row['store']->code,
                        $row['store']->name,
                        $this->brandHealth->storeBrandLabel($row['store']),
                        $row['count'],
                        $ticket->ticket_key ?? (string) $ticket->id,
                        ...$this->ticketCells($ticket),
                    ];
                }
            }

            $this->writeSheet(
                $spreadsheet->createSheet()->setTitle('Top 10 Stores'),
                ['Rank', 'Store Code', 'Store Name', 'Brand', 'Store Tickets', 'Ticket #', 'Issue', 'Item', 'Concern', 'Assignee', 'Status', 'Created At'],
                $rows
            );
        }

        $first = $slice['tickets']->first();
        $label = match (true) {
            ! empty($validated['store_id']) => $first?->store?->name ?? 'store',
            filled($validated['sub_category_id'] ?? null) => $first?->subCategory?->name ?? 'unspecified',
            default => 'tickets',
        };

        $writer = new Xlsx($spreadsheet);
        $filename = Str::slug('brand-health-' . $label) . '-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->stream(fn () => $writer->save('php://output'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /** The ticket columns both export sheets share. */
    private function ticketCells(Ticket $ticket): array
    {
        return [
            $ticket->title,
            $ticket->item?->name ?? 'Unspecified',
            $ticket->item?->concern_type,
            $ticket->assignee?->name ?? 'Unassigned',
            TicketStatuses::label($ticket->status),
            $ticket->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function writeSheet(Worksheet $sheet, array $headers, iterable $rows): void
    {
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        $rowNumber = 2;
        foreach ($rows as $cells) {
            foreach ($cells as $i => $value) {
                // Explicit types: ticket titles come from inbound email, and one that
                // starts with "=" would otherwise open as a live formula.
                is_int($value)
                    ? $sheet->setCellValueExplicit([$i + 1, $rowNumber], $value, DataType::TYPE_NUMERIC)
                    : $sheet->setCellValueExplicit([$i + 1, $rowNumber], (string) $value, DataType::TYPE_STRING);
            }
            $rowNumber++;
        }

        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    /** Shared by the drill-down and its export, so both read the same slice. */
    private function drillDownRules(): array
    {
        return [
            'brand_id' => ['nullable', 'integer'],
            // 'none' means tickets with no sub-category — distinct from "no filter".
            'sub_category_id' => ['nullable', 'string'],
            'store_id' => ['nullable', 'integer'],
            'concern_type' => ['nullable', 'in:Incident,Service Request,Problem'],
            'status' => ['nullable', Rule::in(BrandHealthService::STATUS_BUCKETS)],
            'as_of_date' => ['nullable', 'date'],
            ...DashboardPeriod::RULES,
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
        ];
    }

    /**
     * The Top 10 Sub-categories / Top 10 Stores lists rebuilt for a concern-type and
     * status slice. The tab itself always paints the open backlog; this endpoint is
     * what the two filters above the lists call.
     */
    public function topLists(Request $request)
    {
        abort_unless($request->user()->can('tickets.view'), 403);

        $validated = $request->validate([
            'brand_id' => ['nullable', 'integer'],
            'concern_type' => ['nullable', 'in:Incident,Service Request,Problem'],
            'status' => ['nullable', Rule::in(BrandHealthService::STATUS_BUCKETS)],
            'as_of_date' => ['nullable', 'date'],
            ...DashboardPeriod::RULES,
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
        ]);

        return response()->json($this->brandHealth->topLists($validated, $this->effectiveCompanyIds($request)));
    }

    /**
     * Resolved through CompanyContext exactly like the dashboard build, so requested
     * ids can only ever narrow the caller's own accessible entities.
     */
    private function effectiveCompanyIds(Request $request): ?array
    {
        return CompanyContext::effectiveEntityIds(
            $request->user(),
            (array) $request->input('entity_ids', []),
            $request->user()->can('dashboard.filter_entity')
        );
    }

    /** Brand confirmed the fix worked → close the ticket. */
    public function resolve(Request $request, Ticket $ticket)
    {
        abort_unless($request->user()->can('tickets.close'), 403);

        return $this->transition($ticket, 'closed', 'success');
    }

    /** Brand reported the issue is not resolved → return the ticket to Open. */
    public function reopen(Request $request, Ticket $ticket)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        return $this->transition($ticket, 'open', 'info');
    }

    /**
     * Apply a status transition to a WCF ticket, recording history and firing an
     * in-app bell notification exactly like the ticket editor does.
     */
    private function transition(Ticket $ticket, string $newStatus, string $level)
    {
        // The register only surfaces tickets awaiting client feedback. Guard against a
        // stale click (someone else already actioned it) so we never re-close/reopen.
        if (\App\Support\TicketStatuses::behavior($ticket->status) !== 'waiting_client_feedback') {
            return redirect()->back()->with('info', "{$ticket->ticket_key} is no longer awaiting confirmation.");
        }

        $oldStatus = $ticket->status;
        $ticket->status = $newStatus;
        $ticket->save();

        TicketHistory::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'column_changed' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'changed_at' => now('Asia/Manila'),
        ]);

        $this->notifications->notifyTicket(
            $ticket,
            'status',
            'Ticket status changed',
            "{$ticket->ticket_key}: " . str_replace('_', ' ', $oldStatus) . ' → ' . str_replace('_', ' ', $newStatus),
            auth()->id(),
            [],
            $level
        );

        $message = $newStatus === 'closed'
            ? "{$ticket->ticket_key} confirmed resolved and closed."
            : "{$ticket->ticket_key} returned to Open for further action.";

        return redirect()->back()->with('success', $message);
    }
}
