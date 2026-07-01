<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Central resolver for the "active entity" (Company) used for the sidebar
 * entity switcher and for stamping newly-created records across modules.
 *
 * Behaviour (per product decision):
 *  - Viewing is NOT filtered by the active entity (controllers keep their
 *    existing union-of-accessible-companies behaviour).
 *  - The active entity is stamped onto new records of every module table in
 *    {@see CompanyContext::MODULE_TABLES} when their company_id is empty.
 *  - The active entity lives in the session and is always one of the entities
 *    the user can access.
 */
class CompanyContext
{
    /** Session key holding the user-selected active company id. */
    public const SESSION_KEY = 'active_company_id';

    /**
     * The entity every user is defaulted to on a fresh sign-in, as long as
     * they can access it. Resolved by company `code`.
     */
    public const DEFAULT_COMPANY_CODE = 'TGI';

    /**
     * Tables whose new records should be auto-stamped with the active entity.
     * This is the single source of truth: the add-company_id migration targets
     * exactly this list (minus the few that already had the column).
     */
    public const MODULE_TABLES = [
        // Already had company_id before the entity-switcher work:
        'tickets',
        'pos_requests',
        'sap_requests',
        'npc_statuses',
        // Added by the entity-switcher migration:
        'projects',
        'assets',
        'task_boards',
        'task_cards',
        'schedules',
        'service_vehicles',
        'service_vehicle_trips',
        'stores',
        'clusters',
        'departments',
        'categories',
        'sub_categories',
        'items',
        'request_types',
        'form_definitions',
        'form_records',
        'canned_messages',
        'kb_articles',
        'kb_categories',
        'vendors',
        'activity_templates',
        'customers',
        'stamp_programs',
        'stock_ins',
        'stock_transfers',
        'stock_receivings',
        'payment_records',
        'payment_vendors',
        'cctv_systems',
        'wigs_pcf',
        'quests',
    ];

    /**
     * Tables that carry company_id but must NEVER be auto-stamped with the
     * active entity (their company_id has different semantics).
     */
    public const EXCLUDED_TABLES = [
        'users',
    ];

    /**
     * Models whose records are FILTERED by the active entity everywhere via
     * {@see \App\Models\Scopes\ActiveEntityScope}. These are transactional
     * record models — reference/config models (categories, items, stores,
     * departments, vendors, etc.) are intentionally NOT scoped so that form
     * dropdowns keep working across every entity.
     */
    public const SCOPED_MODELS = [
        \App\Models\Ticket::class,
        \App\Models\PosRequest::class,
        \App\Models\SapRequest::class,
        \App\Models\Project::class,
        \App\Models\Asset::class,
        \App\Models\Schedule::class,
        \App\Models\ServiceVehicle::class,
        \App\Models\ServiceVehicleTrip::class,
        \App\Models\StockIn::class,
        \App\Models\StockTransfer::class,
        \App\Models\StockReceiving::class,
        \App\Models\PaymentRecord::class,
        \App\Models\CctvSystem::class,
        \App\Models\WigsPcf::class,
        \App\Models\Quest::class,
        \App\Models\StampProgram::class,
        \App\Models\FormRecord::class,
        \App\Models\TaskCard::class,
    ];

    /**
     * The entities a user may access: union of their roles' companies and their
     * own direct company assignment, limited to active companies.
     */
    public static function accessibleCompanies($user): EloquentCollection|Collection
    {
        if (!$user) {
            return collect();
        }

        $user->loadMissing('roles.companies');

        $ids = collect();
        foreach ($user->roles as $role) {
            if ($role->companies) {
                $ids = $ids->merge($role->companies->pluck('id'));
            }
        }
        if ($user->company_id) {
            $ids->push($user->company_id);
        }

        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Company::whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'logo']);
    }

    /**
     * Resolve the active company id for a user: the session selection if it is
     * still accessible, else their direct company, else the first accessible.
     */
    public static function resolveActiveId($user): ?int
    {
        $accessible = static::accessibleCompanies($user);
        if ($accessible->isEmpty()) {
            return null;
        }

        $sessionId = session(static::SESSION_KEY);
        if ($sessionId && $accessible->contains('id', (int) $sessionId)) {
            return (int) $sessionId;
        }

        // Fresh sign-in: default to the configured entity (TGI) when the user
        // can access it, before falling back to their own / first entity.
        $default = $accessible->firstWhere('code', static::DEFAULT_COMPANY_CODE);
        if ($default) {
            return (int) $default->id;
        }

        if ($user->company_id && $accessible->contains('id', (int) $user->company_id)) {
            return (int) $user->company_id;
        }

        return (int) $accessible->first()->id;
    }

    /** The accessible company ids for a user as a plain int array. */
    public static function accessibleCompanyIds($user): array
    {
        return static::accessibleCompanies($user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolve the company ids an "Entity/Company" filter selection maps to.
     *
     * - When the user is permitted AND made a non-empty selection, returns the
     *   selection intersected with the entities they can actually access.
     * - Otherwise falls back to the single active entity (the sidebar selection),
     *   or all accessible entities when there is no active entity.
     *
     * Always a subset of the user's accessible entities, so callers can safely
     * apply it without a separate access check.
     *
     * @param  array<int|string>|null  $selected
     * @return int[]
     */
    public static function effectiveEntityIds($user, ?array $selected, bool $hasPermission): array
    {
        $accessible = static::accessibleCompanyIds($user);

        if ($hasPermission && !empty($selected)) {
            $picked = collect($selected)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $intersection = array_values(array_intersect($picked, $accessible));
            if (!empty($intersection)) {
                return $intersection;
            }
        }

        $activeId = static::resolveActiveId($user);
        if ($activeId) {
            return [$activeId];
        }

        return $accessible;
    }

    /** Per-request memo of the resolved active id, keyed by user id. */
    private static array $activeIdMemo = [];

    /**
     * The active company id for the currently authenticated user, or null.
     * Used by the global record-stamping listener. Memoized per request so
     * bulk inserts don't re-query the accessible-entities list each time.
     */
    public static function activeCompanyId(): ?int
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        if (!array_key_exists($user->id, static::$activeIdMemo)) {
            static::$activeIdMemo[$user->id] = static::resolveActiveId($user);
        }

        return static::$activeIdMemo[$user->id];
    }

    /** Clear the per-request memo (used after switching the active entity). */
    public static function flushMemo(): void
    {
        static::$activeIdMemo = [];
    }

    /** Whether a table's new records should be stamped with the active entity. */
    public static function shouldStamp(string $table): bool
    {
        return in_array($table, static::MODULE_TABLES, true)
            && !in_array($table, static::EXCLUDED_TABLES, true);
    }
}
