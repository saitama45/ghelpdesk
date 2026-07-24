<?php

namespace App\Support;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Resolves the "department axis" that nests inside the active entity
 * ({@see CompanyContext}). Two ideas, mirroring the LINK Hub prototype:
 *
 *  - HOME department: the department the user belongs to (users.department_id).
 *    This is fixed per user and drives provider-vs-customer access.
 *  - VIEWED department: which department's workspace the user is currently
 *    looking at (session-based, defaults to the home department). Always one of
 *    the departments under the active entity.
 *
 * Access is DERIVED, never assigned: you are a "provider" of the department you
 * belong to (you manage its work) and a "customer" of any other department (you
 * request from its catalogue). This matches the prototype's single-line rule.
 */
class DepartmentContext
{
    /** Session key holding the user-selected viewed department id. */
    public const SESSION_KEY = 'viewed_department_id';

    /**
     * Session key holding the user-selected "I belong to" home department.
     * Overrides the DB placement for the session (prototype-style exploration).
     * May be an int department id or the {@see EXECUTIVE} sentinel.
     */
    public const HOME_SESSION_KEY = 'home_department_override';

    /** Sentinel for the executive ("I belong to Executive") selection. */
    public const EXECUTIVE = 'executive';

    /**
     * Per-department accent palette, ported from the LINK Hub prototype and keyed
     * by department code. Drives the CSS accent that retints on department switch.
     * Unknown codes fall back to {@see DEFAULT_ACCENT}.
     */
    public const ACCENTS = [
        'TAS' => ['accent' => '#0b948c', 'soft' => '#e7f6f4'],
        'SCM' => ['accent' => '#2d6fe4', 'soft' => '#eaf1ff'],
        // Operations keeps the prototype amber; PD/FM get their own distinct hues
        // so no two departments share an accent.
        'PD'  => ['accent' => '#7c3aed', 'soft' => '#f1ebfd'],
        'FM'  => ['accent' => '#0891b2', 'soft' => '#e6f6fb'],
        'MKTG' => ['accent' => '#d85142', 'soft' => '#ffefec'],
        'F&A' => ['accent' => '#6366c9', 'soft' => '#f0efff'],
        'P&O' => ['accent' => '#b95381', 'soft' => '#fbeaf2'],
        // OWD/LD were the same pink as P&O in the prototype (one HR template);
        // given their own hues so every department is visually distinct.
        'OWD' => ['accent' => '#16a34a', 'soft' => '#e7f7ee'],
        'LD'  => ['accent' => '#be123c', 'soft' => '#fdeaef'],
        'BD'  => ['accent' => '#253d5b', 'soft' => '#eaf0f5'],
    ];

    public const DEFAULT_ACCENT = ['accent' => '#2d6fe4', 'soft' => '#eaf1ff'];

    /** Executive mode accent (prototype navy). */
    public const EXECUTIVE_ACCENT = ['accent' => '#253d5b', 'soft' => '#eaf0f5'];

    /**
     * Resolve the accent palette for a department by code or name. Marketing is
     * stored as name "Marketing" (code often null), Finance likewise — match on
     * both. Falls back to the default blue.
     */
    public static function accentFor(?string $code, ?string $name = null): array
    {
        $key = strtoupper(trim((string) $code));
        if ($key !== '' && isset(static::ACCENTS[$key])) {
            return static::ACCENTS[$key];
        }

        // Name-based fallbacks for departments whose code is blank.
        $n = strtolower(trim((string) $name));
        return match (true) {
            str_contains($n, 'finance') => ['accent' => '#6366c9', 'soft' => '#f0efff'],
            str_contains($n, 'marketing') => static::ACCENTS['MKTG'],
            str_contains($n, 'operation') => ['accent' => '#d97706', 'soft' => '#fff3df'],
            str_contains($n, 'technology') => static::ACCENTS['TAS'],
            str_contains($n, 'supply') => static::ACCENTS['SCM'],
            str_contains($n, 'business development') => static::ACCENTS['BD'],
            default => static::DEFAULT_ACCENT,
        };
    }

    /** Whether the session is in the Executive "I belong to" mode. */
    public static function isExecutive($user): bool
    {
        return $user && session(static::HOME_SESSION_KEY) === static::EXECUTIVE;
    }

    /**
     * The user's home department id. A session "I belong to" override wins when it
     * points to a department the user can access; Executive resolves to null (no
     * home department); otherwise falls back to the DB placement (users.department_id).
     */
    public static function homeDepartmentId($user): ?int
    {
        if (! $user) {
            return null;
        }

        $override = session(static::HOME_SESSION_KEY);

        if ($override === static::EXECUTIVE) {
            return null;
        }

        if ($override !== null) {
            $overrideId = (int) $override;
            if (in_array($overrideId, static::accessibleDepartmentIds($user), true)) {
                return $overrideId;
            }
        }

        return $user->department_id ? (int) $user->department_id : null;
    }

    /**
     * Departments the user can view: those belonging to the active entity. When
     * there is no active entity, all departments are returned. Ordered by name.
     */
    public static function accessibleDepartments($user): EloquentCollection|Collection
    {
        if (! $user) {
            return collect();
        }

        $companyId = CompanyContext::activeCompanyId();

        return Department::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'company_id']);
    }

    /** The accessible department ids as a plain int array. */
    public static function accessibleDepartmentIds($user): array
    {
        return static::accessibleDepartments($user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The viewed department id: the session selection if still accessible, else
     * the home department if accessible, else the first accessible department.
     */
    public static function resolveViewedId($user): ?int
    {
        $accessibleIds = static::accessibleDepartmentIds($user);
        if (empty($accessibleIds)) {
            return null;
        }

        $sessionId = session(static::SESSION_KEY);
        if ($sessionId && in_array((int) $sessionId, $accessibleIds, true)) {
            return (int) $sessionId;
        }

        $home = static::homeDepartmentId($user);
        if ($home && in_array($home, $accessibleIds, true)) {
            return $home;
        }

        return $accessibleIds[0];
    }

    /**
     * 'provider' when the viewed department is the user's home department,
     * 'customer' otherwise. Never 'provider' for an unplaced user.
     */
    public static function accessView($user): string
    {
        $home = static::homeDepartmentId($user);
        $viewed = static::resolveViewedId($user);

        return $home && $viewed && $home === $viewed ? 'provider' : 'customer';
    }

    /**
     * The shared payload for the frontend: home/viewed ids, derived access view,
     * and the list of departments the user may switch between.
     */
    public static function share($user): array
    {
        if (! $user) {
            return [
                'home' => null,
                'viewed' => null,
                'accessView' => 'customer',
                'isExecutive' => false,
                'canSwitchHome' => false,
                'accent' => static::DEFAULT_ACCENT['accent'],
                'soft' => static::DEFAULT_ACCENT['soft'],
                'departments' => [],
            ];
        }

        $departments = static::accessibleDepartments($user);
        $viewed = static::resolveViewedId($user);
        $home = static::homeDepartmentId($user);
        $executive = static::isExecutive($user);

        // Accent follows the VIEWED department (Executive mode uses navy).
        $viewedDept = $departments->firstWhere('id', $viewed);
        $palette = $executive
            ? static::EXECUTIVE_ACCENT
            : static::accentFor($viewedDept?->code, $viewedDept?->name);

        return [
            'home' => $home,
            'viewed' => $viewed,
            'accessView' => ($home && $viewed && $home === $viewed) ? 'provider' : 'customer',
            'isExecutive' => $executive,
            // Elevated cross-scope users may switch their "I belong to" home
            // department (a preview/explore tool); everyone else sees it read-only.
            'canSwitchHome' => (bool) $user->can('dashboard.filter_entity'),
            'accent' => $palette['accent'],
            'soft' => $palette['soft'],
            'departments' => $departments->map(fn ($d) => [
                'id' => (int) $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'accent' => static::accentFor($d->code, $d->name)['accent'],
            ])->values()->all(),
        ];
    }
}
