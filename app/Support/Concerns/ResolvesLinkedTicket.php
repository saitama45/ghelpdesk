<?php

namespace App\Support\Concerns;

use App\Models\Scopes\ActiveEntityScope;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared "why does this record have no ticket?" logic for any model with a
 * nullable ticket_id (PosRequest, FormRecord, SapRequest, ...).
 *
 * A plain `->ticket` relation is unreliable for this question because it hides
 * BOTH archived (soft-deleted) tickets AND tickets outside the viewer's active
 * entity (Ticket carries ActiveEntityScope). Treating either as "no ticket"
 * makes the UI claim a ticket is missing when it exists — and, worse, invites a
 * regeneration that would duplicate it.
 *
 * States:
 *   'live'     – ticket exists, not archived (safe to link to)
 *   'archived' – ticket_id points at a soft-deleted ticket (show it, don't link)
 *   'none'     – no ticket was ever created, or the row is hard-deleted
 */
trait ResolvesLinkedTicket
{
    /**
     * Fetch the referenced ticket with archive + entity filters lifted.
     */
    protected function resolveTicket(?string $ticketId): ?Ticket
    {
        if (!$ticketId) {
            return null;
        }

        return $this->resolveTickets(collect([$ticketId]))->get($ticketId);
    }

    /**
     * Fetch many referenced tickets in one query, keyed by id. Used by list
     * screens so annotating a page of rows stays a single extra query.
     */
    protected function resolveTickets(Collection $ticketIds): Collection
    {
        $ids = $ticketIds->filter()->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Ticket::withTrashed()
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->with('archiver:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'ticket_key', 'status', 'company_id', 'deleted_at', 'deleted_by'])
            ->keyBy('id');
    }

    /**
     * Classify a resolved ticket. Null ticket means it never existed.
     */
    protected function ticketStateOf(?Ticket $ticket): string
    {
        if (!$ticket) {
            return 'none';
        }

        return $ticket->trashed() ? 'archived' : 'live';
    }

    /**
     * Annotate a collection of records with `ticket_state` and re-attach the
     * resolved ticket, so lists can always render the real ticket number.
     */
    protected function annotateTicketState(Collection $records): void
    {
        $resolved = $this->resolveTickets($records->pluck('ticket_id'));

        $records->each(function (Model $record) use ($resolved) {
            $ticket = $record->ticket_id ? $resolved->get($record->ticket_id) : null;

            $record->ticket_state = $this->ticketStateOf($ticket);
            $record->setRelation('ticket', $ticket);
        });
    }

    /**
     * Detail payload for an archived ticket: what it was, when it went, who did it.
     * `deleted_by` is null for tickets archived before the column existed.
     */
    protected function archivedTicketPayload(?Ticket $ticket): ?array
    {
        if (!$ticket || !$ticket->trashed()) {
            return null;
        }

        return [
            'ticket_key' => $ticket->ticket_key,
            'deleted_at' => $ticket->deleted_at?->toIso8601String(),
            'deleted_by' => $ticket->archiver?->name,
        ];
    }
}
