<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Ticket;
use App\Models\User;

/**
 * Per-ticket side of the department axis (see {@see DepartmentContext}).
 *
 * The axis says which department you BELONG to; this says which department a
 * given ticket is SERVED by. Put together they answer the only question the
 * ticket page needs: am I looking at my own desk's work, or at a request my
 * department raised to another desk?
 *
 *  - PROVIDER — the serving department is my home department. The ticket is my
 *    desk's work: everything my permissions allow stays available.
 *  - CUSTOMER — another department serves it. I am their internal customer, so
 *    the ticket is mine to follow and comment on, never to edit. Editing it
 *    would let a requester re-classify, re-assign or close work owned by a desk
 *    they do not sit on.
 *
 * Derived from the TICKET, not from the department tab you happen to be on, so
 * the same ticket reads the same way whether it was opened from the Ticket
 * Board, a search result or an email link.
 */
class TicketAccess
{
    /**
     * Roles that keep full desk access on every ticket. These are the same roles
     * allowed to move their "I belong to" home department
     * ({@see DepartmentContext::HOME_SWITCH_ROLES}): a session whose home
     * department is a preview setting cannot also be an access boundary.
     */
    public const BYPASS_ROLES = DepartmentContext::HOME_SWITCH_ROLES;

    /**
     * The department that owns the work, mirroring
     * {@see Ticket::scopeOwnedByDepartment()}: the explicit route first, then
     * the assignee's department. Null means nobody owns it yet — an unclaimed
     * intake ticket, which stays open for any desk to pick up.
     */
    public static function servingDepartmentId(Ticket $ticket): ?int
    {
        if ($ticket->serving_department_id) {
            return (int) $ticket->serving_department_id;
        }

        $assignee = $ticket->relationLoaded('assignee')
            ? $ticket->assignee
            : ($ticket->assignee_id ? User::find($ticket->assignee_id) : null);

        return $assignee?->department_id ? (int) $assignee->department_id : null;
    }

    /**
     * Whether this user is only an internal CUSTOMER of the ticket, i.e. the
     * page must render read-only.
     */
    public static function isCustomerOf(Ticket $ticket, ?User $user): bool
    {
        if (! $user || $user->hasAnyRole(self::BYPASS_ROLES)) {
            return false;
        }

        // Executive mode sits above the axis; an unplaced user has no desk to be
        // a provider of, so locking them out would only take away access they
        // already have today.
        if (DepartmentContext::isExecutive($user)) {
            return false;
        }

        $home = DepartmentContext::homeDepartmentId($user);
        if (! $home) {
            return false;
        }

        $serving = self::servingDepartmentId($ticket);

        return $serving !== null && $serving !== $home;
    }

    /**
     * The single change a customer MAY make: marking their own request resolved,
     * if they hold `tickets.resolve`. Closing the loop belongs to the requester —
     * they are the one who knows the request was actually satisfied.
     *
     * It carries no Action Taken / RCA: that record is the serving desk's account
     * of the work, and demanding it from a requester would be asking them to
     * document a job they did not do.
     */
    public static function mayResolveAsCustomer(Ticket $ticket, ?User $user): bool
    {
        return $user
            && self::isCustomerOf($ticket, $user)
            && $user->can('tickets.resolve');
    }

    /**
     * Stop a customer from performing a service-desk action. Comments and
     * attachments are deliberately NOT guarded: following up on your own request
     * is the whole point of the customer view. Nor is the resolve above.
     */
    public static function assertProvider(Ticket $ticket, ?User $user): void
    {
        abort_if(
            self::isCustomerOf($ticket, $user),
            403,
            'This ticket is owned by another department. You can follow it and reply, but only its service desk can change it.'
        );
    }

    /**
     * The bulk counterpart: refuse the whole batch if ANY ticket in it belongs to
     * another department. Partially applying a bulk action would be worse than
     * refusing it — the user would have no way to tell which rows took.
     *
     * @param  array<int, string|int>  $ticketIds
     */
    public static function assertProviderOfAll(array $ticketIds, ?User $user): void
    {
        if (empty($ticketIds) || ! $user || $user->hasAnyRole(self::BYPASS_ROLES)) {
            return;
        }

        if (DepartmentContext::isExecutive($user) || ! DepartmentContext::homeDepartmentId($user)) {
            return;
        }

        // Unscoped and column-pinned: a selection can span entities, and the LOB
        // columns (description, form_data…) must not be dragged over the link.
        $tickets = Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->whereIn('id', $ticketIds)
            ->with('assignee:id,department_id')
            ->get(['id', 'serving_department_id', 'assignee_id']);

        foreach ($tickets as $ticket) {
            self::assertProvider($ticket, $user);
        }
    }

    /**
     * The axis payload for Tickets/Edit — same shape the index page receives, so
     * both pages frame the provider/customer distinction identically.
     */
    public static function payload(Ticket $ticket, ?User $user): array
    {
        $isCustomer = self::isCustomerOf($ticket, $user);

        $describe = function (?int $id) {
            $department = $id ? Department::whereKey($id)->first(['id', 'name', 'code']) : null;

            return $department
                ? ['id' => (int) $department->id, 'name' => $department->name, 'code' => $department->code]
                : null;
        };

        return [
            'accessView' => $isCustomer ? 'customer' : 'provider',
            'readOnly' => $isCustomer,
            'canResolveAsCustomer' => $isCustomer && (bool) $user?->can('tickets.resolve'),
            'servingDepartment' => $describe(self::servingDepartmentId($ticket)),
            'homeDepartment' => $describe(DepartmentContext::homeDepartmentId($user)),
            'isExecutive' => DepartmentContext::isExecutive($user),
        ];
    }
}
