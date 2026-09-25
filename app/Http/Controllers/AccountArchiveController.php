<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountArchiveService;
use App\Support\TicketStatuses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Settings → Account Archive: the restore/purge desk for accounts deleted from
 * /users and from /stamps → Customers.
 *
 * Deliberately built like TicketArchiveController (retention window, manual
 * purge, bulk selection) so there is one archive mental model in the app, but
 * kept separate because the two archives have different permissions, different
 * blockers, and different audiences.
 *
 * Tabs:
 *  - Loyalty Customers: one list for the whole member lifecycle, filtered by
 *    status. "Pending request" rows are still-active members with an open
 *    "Account Deletion Request" ticket (public /account-deletion page or the
 *    app); archiving them is Stage 1. "Archived" rows are archived customers,
 *    restored or purged from here. A mobile-app member is a customer plus an
 *    app login, listed once by its customer id; every action works on the pair.
 *  - Users: archived staff logins only (soft deleted from User Management).
 */
class AccountArchiveController extends Controller implements HasMiddleware
{
    private const TABS = ['customers', 'users'];

    private const STATUSES = ['all', 'pending', 'archived'];

    public function __construct(private AccountArchiveService $archive) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.view', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        [$tab, $status] = $this->resolveView($request);
        $retention = $this->retention();
        $search = trim((string) $request->query('search', ''));
        $perPage = $request->integer('per_page', 10);

        $records = $tab === 'customers'
            ? $this->loyaltyCustomers($search, $status, $perPage, $retention)
            : $this->archivedUsers($search, $perPage, $retention);

        $pending = $this->loyaltyCustomersQuery('pending')->count();
        $archived = Customer::onlyTrashed()->count();

        return Inertia::render('Settings/AccountArchive', [
            'tab' => $tab,
            'records' => $records,
            'counts' => [
                'customers' => $pending + $archived,
                'pending' => $pending,
                'archived' => $archived,
                'users' => User::onlyTrashed()->whereNull('customer_id')->count(),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'retention' => [
                'value' => $retention['value'],
                'unit' => $retention['unit'],
                'label' => $retention['label'],
                'cutoff' => $this->formatDate($retention['cutoff']),
            ],
            'can' => [
                'archive_requests' => $request->user()->can('stamps.delete'),
                'restore_users' => $request->user()->can('users.edit'),
                'restore_customers' => $request->user()->can('stamps.edit'),
                'purge_users' => $request->user()->can('settings.edit') && $request->user()->can('users.delete'),
                'purge_customers' => $request->user()->can('settings.edit') && $request->user()->can('stamps.delete'),
            ],
        ]);
    }

    /* ----------------------------------------------------------------------
     | Archive (Stage 1 of a member's deletion request)
     * ------------------------------------------------------------------- */

    public function archive(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        // Same right as the delete icon on /stamps → Customers, which archives
        // the same pair.
        abort_unless($request->user()->can('stamps.delete'), 403);

        // The ids are customer ids, like every row on the Loyalty Customers tab.
        // Only members with an open deletion request: this desk acts on requests,
        // it is not a back door for archiving arbitrary accounts.
        $members = $this->deletionRequestsQuery()->whereIn('customer_id', $validated['ids'])->get();

        if ($members->isEmpty()) {
            return $this->toTab($request, 'customers')->withErrors(['archive' => 'No pending deletion requests were selected.']);
        }

        foreach ($members as $member) {
            $this->archive->archiveUser($member, $request->user()->id);
        }

        return $this->toTab($request, 'customers')->with('success', $members->count().' account(s) archived with their loyalty customer record. Reply on the request ticket to let the member know.');
    }

    /* ----------------------------------------------------------------------
     | Restore
     * ------------------------------------------------------------------- */

    public function restore(Request $request)
    {
        [$tab, $ids] = $this->validateSelection($request);

        $this->authorizeAction($request, $tab, 'restore');

        $restored = 0;
        $stranded = [];

        foreach ($this->findArchived($tab, $ids) as $record) {
            $result = $tab === 'customers'
                ? $this->archive->restoreCustomer($record)
                : $this->archive->restoreUser($record);
            $restored++;

            // Archiving frees the login's email so it can be registered again.
            // If somebody took it in the meantime the address cannot come back,
            // and the restored login therefore cannot sign in — say so, rather
            // than reporting a success that leaves an unusable account behind.
            if (($result['email_reclaimed'] ?? true) === false) {
                $stranded[] = $result['user'] ?? $result['customer'];
            }
        }

        if ($restored === 0) {
            return $this->toTab($request, $tab)->withErrors(['restore' => 'No archived accounts were selected for restore.']);
        }

        if ($stranded !== []) {
            return $this->toTab($request, $tab)->with('warning', "{$restored} archived account(s) restored, but ".implode(', ', $stranded).' could not get their original email address back — another account is using it now, so they cannot sign in until that account is archived and this one is restored again.');
        }

        return $this->toTab($request, $tab)->with('success', "{$restored} archived account(s) restored, together with any linked record.");
    }

    /* ----------------------------------------------------------------------
     | Purge
     * ------------------------------------------------------------------- */

    public function purge(Request $request)
    {
        [$tab, $ids] = $this->validateSelection($request);

        $this->authorizeAction($request, $tab, 'purge');

        $records = $this->findArchived($tab, $ids);

        if ($records->isEmpty()) {
            return $this->toTab($request, $tab)->withErrors(['purge' => 'No archived accounts were selected for purge.']);
        }

        $retention = $this->retention();

        // Refuse the whole batch when any row is blocked, the way the ticket
        // archive does — a partial purge is impossible to reason about after
        // the fact, and this action cannot be undone.
        foreach ($records as $record) {
            if ($blocker = $this->purgeBlocker($tab, $record, $retention)) {
                return $this->toTab($request, $tab)->withErrors(['purge' => $blocker]);
            }
        }

        try {
            foreach ($records as $record) {
                $tab === 'customers'
                    ? $this->archive->purgeCustomer($record, $request->user()->id)
                    : $this->archive->purgeUser($record, $request->user()->id);
            }
        } catch (ValidationException $e) {
            return $this->toTab($request, $tab)->withErrors(['purge' => collect($e->errors())->flatten()->first()]);
        }

        return $this->toTab($request, $tab)->with('success', $records->count().' archived account(s) purged permanently.');
    }

    /* ----------------------------------------------------------------------
     | Listing
     * ------------------------------------------------------------------- */

    /**
     * Active app members with an open deletion-request ticket. The ticket is
     * matched on its reporter, which the public page sets to the member.
     */
    private function deletionRequestsQuery(): Builder
    {
        return User::query()
            ->whereNotNull('customer_id')
            ->whereIn('id', $this->openRequestTickets()->select('reporter_id'));
    }

    private function openRequestTickets(): Builder
    {
        return Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->where('title', PublicAccountDeletionController::TICKET_TITLE)
            ->whereNotNull('reporter_id')
            ->whereNotIn('status', TicketStatuses::like(['resolved', 'closed']));
    }

    /** Staff logins only; a member's app login is listed with its customer. */
    private function archivedUsers(string $search, int $perPage, array $retention)
    {
        $query = User::onlyTrashed()
            ->whereNull('customer_id')
            ->select(['id', 'name', 'email', 'archived_email', 'employee_id_no', 'department', 'position', 'customer_id', 'deleted_at', 'deleted_by', 'created_at'])
            ->with(['customer' => fn ($q) => $q->withTrashed()->select('id', 'name', 'email', 'deleted_at')]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    // Archiving parks the real address here and leaves a
                    // tombstone in `email`, so searching by the address a
                    // colleague actually remembers has to look at both.
                    ->orWhere('archived_email', 'like', "%{$search}%")
                    ->orWhere('employee_id_no', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('deleted_at')->paginate($perPage)->withQueryString();

        $actors = $this->actorNames($users->getCollection()->pluck('deleted_by'));

        $users->getCollection()->transform(fn (User $user) => [
            'id' => $user->id,
            'type' => 'users',
            'name' => $user->name,
            'subtitle' => $user->archived_email ?? $user->email,
            'meta' => array_values(array_filter([
                $user->employee_id_no ? "ID {$user->employee_id_no}" : null,
                $user->position,
                $user->department,
            ])),
            'linked' => $user->customer ? [
                'label' => 'Loyalty customer',
                'name' => $user->customer->name,
                'archived' => (bool) $user->customer->deleted_at,
            ] : null,
            'deleted_at' => $this->formatDate($user->deleted_at),
            'deleted_by' => $actors[$user->deleted_by] ?? null,
            'created_at' => $this->formatDate($user->created_at),
            'purge_eligible' => $this->isPurgeEligible($user->deleted_at, $retention),
            'purge_available_at' => $this->formatDate($this->purgeAvailableAt($user->deleted_at, $retention)),
            'purge_blocker' => $this->archive->userPurgeBlocker($user),
        ]);

        return $users;
    }

    /**
     * Loyalty customers that need this desk: still-active members with an open
     * deletion request ("pending"), and archived customers ("archived").
     */
    private function loyaltyCustomersQuery(string $status): Builder
    {
        $pending = fn (Builder $q) => $q->whereNull('deleted_at')
            ->whereIn('id', $this->deletionRequestsQuery()->select('customer_id'));

        return Customer::withTrashed()->where(fn (Builder $q) => match ($status) {
            'pending' => $pending($q),
            'archived' => $q->whereNotNull('deleted_at'),
            default => $q->whereNotNull('deleted_at')->orWhere(fn (Builder $q) => $pending($q)),
        });
    }

    private function loyaltyCustomers(string $search, string $status, int $perPage, array $retention)
    {
        $query = $this->loyaltyCustomersQuery($status)
            ->select(['id', 'name', 'email', 'phone', 'deleted_at', 'deleted_by', 'created_at'])
            ->withCount(['stampCards', 'redemptions']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Pending requests first: they are the rows waiting on someone.
        $customers = $query
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('deleted_at')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $actors = $this->actorNames($customers->getCollection()->pluck('deleted_by'));
        $users = $customers->getCollection()->mapWithKeys(fn (Customer $c) => [$c->id => $this->archive->userFor($c)]);
        $tickets = $this->requestTicketsFor($users->filter()->pluck('id'));

        $customers->getCollection()->transform(function (Customer $customer) use ($retention, $actors, $users, $tickets) {
            $user = $users->get($customer->id);
            $ticket = $user ? $tickets->get($user->id) : null;
            $archived = $customer->trashed();

            return [
                'id' => $customer->id,
                'type' => 'customers',
                'state' => $archived ? 'archived' : 'pending',
                'name' => $customer->name,
                'subtitle' => $customer->email ?: $customer->phone ?: 'Walk-in customer',
                'meta' => array_values(array_filter([
                    $customer->stamp_cards_count ? "{$customer->stamp_cards_count} stamp card(s)" : null,
                    $customer->redemptions_count ? "{$customer->redemptions_count} redemption(s)" : null,
                ])),
                'linked' => $user ? [
                    'label' => 'App login',
                    'name' => $user->archived_email ?? $user->email,
                    'archived' => (bool) $user->deleted_at,
                ] : null,
                'ticket' => $ticket ? [
                    'key' => $ticket->ticket_key,
                    'status' => TicketStatuses::label($ticket->status),
                    'open' => $ticket->is_open,
                    'requested_at' => $this->formatDate($ticket->created_at),
                ] : null,
                'deleted_at' => $archived ? $this->formatDate($customer->deleted_at) : null,
                'deleted_by' => $actors[$customer->deleted_by] ?? null,
                'created_at' => $this->formatDate($customer->created_at),
                'purge_eligible' => $archived && $this->isPurgeEligible($customer->deleted_at, $retention),
                'purge_available_at' => $archived ? $this->formatDate($this->purgeAvailableAt($customer->deleted_at, $retention)) : null,
                'purge_blocker' => $archived ? $this->archive->customerPurgeBlocker($customer) : null,
            ];
        });

        return $customers;
    }

    /**
     * The deletion-request ticket to show per member: the latest open one, or,
     * once every request is closed, the latest closed one, so an archived row
     * still links to the request that led to it.
     */
    private function requestTicketsFor(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $closed = TicketStatuses::like(['resolved', 'closed']);

        return Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->where('title', PublicAccountDeletionController::TICKET_TITLE)
            ->whereIn('reporter_id', $userIds)
            ->orderByDesc('created_at')
            ->get(['id', 'ticket_key', 'status', 'reporter_id', 'created_at'])
            ->groupBy('reporter_id')
            ->map(function (Collection $group) use ($closed) {
                $open = $group->first(fn (Ticket $t) => ! in_array($t->status, $closed, true));
                $ticket = $open ?? $group->first();
                $ticket->setAttribute('is_open', $open !== null);

                return $ticket;
            });
    }

    /** Names for the `deleted_by` ids on this page, archived actors included. */
    private function actorNames(Collection $ids): array
    {
        $ids = $ids->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /* ----------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------- */

    /** @return array{0: string, 1: array<int, int>} */
    private function validateSelection(Request $request): array
    {
        $validated = $request->validate([
            'type' => 'required|in:users,customers',
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        return [$validated['type'], $validated['ids']];
    }

    /**
     * The tab and the Loyalty Customers status filter. The old standalone
     * "Deletion Requests" tab is now that filter, so its links still land there.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveView(Request $request): array
    {
        $tab = $request->query('tab');
        $status = $request->query('status');

        if ($tab === 'requests') {
            [$tab, $status] = ['customers', 'pending'];
        }

        return [
            in_array($tab, self::TABS, true) ? $tab : 'customers',
            in_array($status, self::STATUSES, true) ? $status : 'all',
        ];
    }

    /**
     * Return to the tab the action was taken on. Not back(): when the browser
     * sends no Referer, back() falls to the session's last full page load, which
     * is often the Deletion Requests tab, not the tab currently open.
     */
    private function toTab(Request $request, string $tab)
    {
        return redirect()->route('account-archive.index', array_filter([
            'tab' => $tab,
            'status' => $tab === 'customers' && in_array($request->input('status'), ['pending', 'archived'], true)
                ? $request->input('status')
                : null,
            'search' => trim((string) $request->input('search', '')),
            'per_page' => $request->integer('per_page') ?: null,
            'page' => $request->integer('page') > 1 ? $request->integer('page') : null,
        ]));
    }

    private function authorizeAction(Request $request, string $tab, string $action): void
    {
        $permission = $tab === 'customers' ? 'stamps' : 'users';

        if ($action === 'purge') {
            abort_unless(
                $request->user()->can('settings.edit') && $request->user()->can("{$permission}.delete"),
                403
            );

            return;
        }

        abort_unless($request->user()->can("{$permission}.edit"), 403);
    }

    private function findArchived(string $tab, array $ids): Collection
    {
        return $tab === 'customers'
            ? Customer::onlyTrashed()->whereIn('id', $ids)->get()
            : User::onlyTrashed()->whereNull('customer_id')->whereIn('id', $ids)->get();
    }

    private function purgeBlocker(string $tab, $record, array $retention): ?string
    {
        $structural = $tab === 'customers'
            ? $this->archive->customerPurgeBlocker($record)
            : $this->archive->userPurgeBlocker($record);

        if ($structural) {
            return $structural;
        }

        if (! $this->isPurgeEligible($record->deleted_at, $retention)) {
            $availableAt = $this->formatDate($this->purgeAvailableAt($record->deleted_at, $retention));

            return "\"{$record->name}\" is retained for {$retention['label']} and cannot be purged until {$availableAt}.";
        }

        return null;
    }

    private function retention(): array
    {
        $value = max(1, (int) Setting::get('account_retention_value', 6));
        $unit = Setting::get('account_retention_unit', 'months');
        $unit = in_array($unit, ['months', 'years'], true) ? $unit : 'months';

        $cutoff = now('Asia/Manila');
        $cutoff = $unit === 'years' ? $cutoff->subYears($value) : $cutoff->subMonths($value);

        $unitLabel = $value === 1 ? rtrim($unit, 's') : $unit;

        return [
            'value' => $value,
            'unit' => $unit,
            'label' => "{$value} {$unitLabel}",
            'cutoff' => $cutoff,
        ];
    }

    private function purgeAvailableAt($deletedAt, array $retention)
    {
        if (! $deletedAt) {
            return null;
        }

        return $retention['unit'] === 'years'
            ? $deletedAt->copy()->addYears($retention['value'])
            : $deletedAt->copy()->addMonths($retention['value']);
    }

    private function isPurgeEligible($deletedAt, array $retention): bool
    {
        return $deletedAt && $deletedAt->lte($retention['cutoff']);
    }

    private function formatDate($date): ?string
    {
        return $date ? $date->timezone('Asia/Manila')->format('Y-m-d H:i:s') : null;
    }
}
