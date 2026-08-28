<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose attendance a user may see on /attendance/logs (and its mobile API twin).
 *
 * Attendance carries a selfie, a GPS fix and a work pattern for a named person,
 * so visibility follows the reporting line drawn on /departments rather than a
 * role. Two rules, and nothing else:
 *
 *  1. `users.is_manager` opens the manager's OWN reporting subtree — every user
 *     reachable through `manager_user`, however deep. A manager sees the people
 *     they are accountable for, not the rest of the company.
 *  2. {@see self::DEPARTMENT_WIDE_PERMISSION} opens the holder's whole
 *     `department_id`, for the handful of accounts that administer attendance
 *     for a department they do not personally manage.
 *
 * Everyone else — including Admin, Dev and Solutions Admin, which used to grant
 * company-wide sight of every employee — sees only their own rows.
 *
 * The permission is checked with `hasPermissionTo()` and NOT `can()` on purpose:
 * `Gate::before` lets the Admin role pass every `can()`, which would quietly put
 * the role privilege back. This capability is granted per account.
 *
 * Defined once so the four enforcement points — the web listing and its work-hours
 * summary, and the same pair on the API — cannot drift apart.
 */
class AttendanceVisibility
{
    /** Grants sight of every user in the holder's own department. */
    public const DEPARTMENT_WIDE_PERMISSION = 'attendance.logs_department';

    /**
     * Every user id $user may see, always including themselves.
     *
     * @return array<int, int>
     */
    public static function visibleUserIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $ids = [(int) $user->id];

        if ($user->is_manager) {
            $ids = array_merge($ids, $user->transitiveSubordinateIds());
        }

        if ($user->department_id && static::seesWholeDepartment($user)) {
            $ids = array_merge($ids, User::where('department_id', $user->department_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Constrain a query to the attendance $user is allowed to see.
     *
     * @param  string  $column  the user-id column on the query's table
     */
    public static function scope(Builder $query, ?User $user, string $column = 'user_id'): Builder
    {
        return $query->whereIn($column, static::visibleUserIds($user));
    }

    /**
     * Whether $user holds the department-wide grant. Guarded because the
     * permission row does not exist until this feature's migration has run.
     */
    public static function seesWholeDepartment(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->hasPermissionTo(static::DEPARTMENT_WIDE_PERMISSION);
        } catch (\Throwable) {
            return false;
        }
    }
}
