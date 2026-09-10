<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountArchiveService;
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
 * Users and customers are shown as two tabs of one page rather than a merged
 * list: an archived pair appears on both tabs describing the same event, and
 * acting on either side operates on the pair.
 */
class AccountArchiveController extends Controller implements HasMiddleware
{
    private const TABS = ['users', 'customers'];

    public function __construct(private AccountArchiveService $archive) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.view', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'users';
        $retention = $this->retention();
        $search = trim((string) $request->query('search', ''));
        $perPage = $request->integer('per_page', 10);

        $records = $tab === 'customers'
            ? $this->archivedCustomers($search, $perPage, $retention)
            : $this->archivedUsers($search, $perPage, $retention);

        return Inertia::render('Settings/AccountArchive', [
            'tab' => $tab,
            'records' => $records,
            'counts' => [
                'users' => User::onlyTrashed()->count(),
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
                'restore_users' => $request->user()->can('users.edit'),
                'restore_customers' => $request->user()->can('stamps.edit'),
                'purge_users' => $request->user()->can('settings.edit') && $request->user()->can('users.delete'),
                'purge_customers' => $request->user()->can('settings.edit') && $request->user()->can('stamps.delete'),
            ],
        ]);
    }

    /* ----------------------------------------------------------------------
     | Restore
     * ------------------------------------------------------------------- */

    public function restore(Request $request)
    {
        [$tab, $ids] = $this->validateSelection($request);

        $this->authorizeAction($request, $tab, 'restore');

        $restored = 0;

        foreach ($this->findArchived($tab, $ids) as $record) {
            $tab === 'customers'
                ? $this->archive->restoreCustomer($record)
                : $this->archive->restoreUser($record);
            $restored++;
        }

        if ($restored === 0) {
            return back()->withErrors(['restore' => 'No archived accounts were selected for restore.']);
        }

        return back()->with('success', "{$restored} archived account(s) restored, together with any linked record.");
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
            return back()->withErrors(['purge' => 'No archived accounts were selected for purge.']);
        }

        $retention = $this->retention();

        // Refuse the whole batch when any row is blocked, the way the ticket
        // archive does — a partial purge is impossible to reason about after
        // the fact, and this action cannot be undone.
        foreach ($records as $record) {
            if ($blocker = $this->purgeBlocker($tab, $record, $retention)) {
                return back()->withErrors(['purge' => $blocker]);
            }
        }

        try {
            foreach ($records as $record) {
                $tab === 'customers'
                    ? $this->archive->purgeCustomer($record, $request->user()->id)
                    : $this->archive->purgeUser($record, $request->user()->id);
            }
        } catch (ValidationException $e) {
            return back()->withErrors(['purge' => collect($e->errors())->flatten()->first()]);
        }

        return back()->with('success', $records->count().' archived account(s) purged permanently.');
    }

    /* ----------------------------------------------------------------------
     | Listing
     * ------------------------------------------------------------------- */

    private function archivedUsers(string $search, int $perPage, array $retention)
    {
        $query = User::onlyTrashed()
            ->select(['id', 'name', 'email', 'employee_id_no', 'department', 'position', 'customer_id', 'deleted_at', 'deleted_by', 'created_at'])
            ->with(['customer' => fn ($q) => $q->withTrashed()->select('id', 'name', 'email', 'deleted_at')]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_id_no', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('deleted_at')->paginate($perPage)->withQueryString();

        $actors = $this->actorNames($users->getCollection()->pluck('deleted_by'));

        $users->getCollection()->transform(fn (User $user) => [
            'id' => $user->id,
            'type' => 'users',
            'name' => $user->name,
            'subtitle' => $user->email,
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
                    'name' => $user->email,
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
            : User::onlyTrashed()->whereIn('id', $ids)->get();
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
