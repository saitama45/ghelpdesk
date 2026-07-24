<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Write actions behind the Live Brand Health tab's WCF confirmation register.
 *
 * Each row is a ticket awaiting brand (client) confirmation. The brand owner's
 * verdict is applied here: Resolved closes the ticket, Not Resolved returns it to
 * Open with the latest evidence. Kept separate from the read-only dashboard build.
 */
class BrandHealthController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

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
        if ($ticket->status !== 'waiting_client_feedback') {
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
