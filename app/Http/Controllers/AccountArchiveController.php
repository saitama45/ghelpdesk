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
 *  - Deletion Requests: members who asked to be deleted from the public
 *    /account-deletion page (an open "Account Deletion Request" ticket) and are
 *    still active. Archiving here is Stage 1 of that process.
 *  - Users: archived staff logins only.
 *  - Loyalty Customers: archived customers. A mobile-app member is a customer
 *    plus an app login, and is listed here once, not also under Users; acting on
 *    it operates on the pair.
 */
class AccountArchiveController extends Controller implements HasMiddleware
{
    private const TABS = ['requests', 'users', 'customers'];

    public function __construct(private AccountArchiveService $archive) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.view', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'requests';
        $retention = $this->retention();
        $search = trim((string) $request->query('search', ''));
        $perPage = $request->integer('per_page', 10);

        $records = match ($tab) {
            'requests' => $this->deletionRequests($search, $perPage),
            'customers' => $this->archivedCustomers($search, $perPage, $retention),
            default => $this->archivedUsers($search, $perPage, $retention),
        };

        return Inertia::render('Settings/AccountArchive', [
            'tab' => $tab,
            'records' => $records,
            'counts' => [
                'requests' => $this->deletionRequestsQuery()->count(),
                'users' => User::onlyTrashed()->whereNull('customer_id')->count(),
                'customers' => Customer::onlyTrashed()->count(),
            ],
            'filters' => [
                'search' => $search,
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

        // Only members with an open deletion request: this desk acts on requests,
        // it is not a back door for archiving arbitrary accounts.
        $members = $this->deletionRequestsQuery()->whereIn('id', $validated['ids'])->get();

        if ($members->isEmpty()) {
            return $this->toTab($request, 'requests')->withErrors(['archive' => 'No pending deletion requests were selected.']);
        }

        foreach ($members as $member) {
            $this->archive->archiveUser($member, $request->user()->id);
        }

        return $this->toTab($request, 'requests')->with('success', $members->count().' account(s) archived with their loyalty customer record. Reply on the request ticket to let the member know.');
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

    private function deletionRequests(string $search, int $perPage)
    {
        $query = $this->deletionRequestsQuery()
            ->select(['id', 'name', 'email', 'customer_id', 'created_at'])
            ->with(['customer' => fn ($q) => $q->select('id', 'name', 'email', 'phone')->withCount('redemptions')]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $members = $query->orderBy('name')->paginate($perPage)->withQueryString();

        // Latest open request per member, fetched for this page only.
        $tickets = $this->openRequestTickets()
            ->whereIn('reporter_id', $members->getCollection()->pluck('id'))
            ->orderByDesc('created_at')
            ->get(['id', 'ticket_key', 'status', 'reporter_id', 'created_at'])
            ->unique('reporter_id')
            ->keyBy('reporter_id');

        $members->getCollection()->transform(function (User $member) use ($tickets) {
            $ticket = $tickets->get($member->id);
            $customer = $member->customer;

            return [
                'id' => $member->id,
                'type' => 'requests',
                'name' => $member->name,
                'subtitle' => $member->email,
                'meta' => array_values(array_filter([
                    $customer?->phone,
                    $customer?->redemptions_count ? "{$customer->redemptions_count} redemption(s), kept as financial records" : null,
                ])),
                'linked' => $customer ? [
                    'label' => 'Loyalty customer',
                    'name' => $customer->name,
                    'archived' => false,
                ] : null,
                'ticket' => $ticket ? [
                    'key' => $ticket->ticket_key,
                    'status' => TicketStatuses::label($ticket->status),
                    'requested_at' => $this->formatDate($ticket->created_at),
                ] : null,
                'created_at' => $this->formatDate($member->created_at),
            ];
        });

        return $members;
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

    private function archivedCustomers(string $search, int $perPage, array $retention)
    {
        $query = Customer::onlyTrashed()
            ->select(['id', 'name', 'email', 'phone', 'deleted_at', 'deleted_by', 'created_at'])
            ->withCount(['stampCards', 'redemptions']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderByDesc('deleted_at')->paginate($perPage)->withQueryString();

        $actors = $this->actorNames($customers->getCollection()->pluck('deleted_by'));

        $customers->getCollection()->transform(function (Customer $customer) use ($retention, $actors) {
            $user = $this->archive->userFor($customer);

            return [
                'id' => $customer->id,
                'type' => 'customers',
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
                'deleted_at' => $this->formatDate($customer->deleted_at),
                'deleted_by' => $actors[$customer->deleted_by] ?? null,
                'created_at' => $this->formatDate($customer->created_at),
                'purge_eligible' => $this->isPurgeEligible($customer->deleted_at, $retention),
                'purge_available_at' => $this->formatDate($this->purgeAvailableAt($customer->deleted_at, $retention)),
                'purge_blocker' => $this->archive->customerPurgeBlocker($customer),
            ];
        });

        return $customers;
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
     * Return to the tab the action was taken on. Not back(): when the browser
     * sends no Referer, back() falls to the session's last full page load, which
     * is often the Deletion Requests tab, not the tab currently open.
     */
    private function toTab(Request $request, string $tab)
    {
        return redirect()->route('account-archive.index', array_filter([
            'tab' => $tab,
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
