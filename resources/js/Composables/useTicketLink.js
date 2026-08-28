import { route } from '../../../vendor/tightenco/ziggy';

/**
 * A ticket's canonical URL is built from its human key — `/tickets/TGI-4096/edit` —
 * because that is the identifier people read, quote in email and search for.
 *
 * Anything that still holds only the UUID keeps working: the backend route binding
 * accepts the key, the UUID and any key retired by a renumber, and `TicketController`
 * redirects the non-canonical ones to the key. Building the key URL here just saves
 * that extra hop, and stops a UUID flashing in the address bar on the way.
 *
 * Accepts a ticket object (any of `ticket_key` / `key` / `id`) or a bare identifier,
 * so a caller with nothing but an id is still correct rather than broken.
 */
export function ticketRouteKey(ticket) {
    if (ticket && typeof ticket === 'object') {
        return ticket.ticket_key || ticket.key || ticket.id;
    }

    return ticket;
}

export function ticketUrl(ticket, name = 'tickets.edit') {
    return route(name, ticketRouteKey(ticket));
}
