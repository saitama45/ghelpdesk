<?php

namespace App\Support;

/**
 * Who may see EVERY department's test cycles on /uat and /qat.
 *
 * The department axis scopes both modules to the cycle's owning department, with
 * per-cycle escape hatches (being the dev lead, QA lead, submitter, creator or a
 * snapshotted approver). Those hatches are per-row, and the Dev role needs the
 * whole board: Devs build what every department is testing, triage the findings
 * raised against their work, and are routinely not named on the cycle until a bug
 * is filed — by which time they could not open it.
 *
 * Deliberately a HARD-CODED role name rather than a permission, matching
 * {@see DepartmentContext::HOME_SWITCH_ROLES}: seeing across the department axis
 * is an administrative capability, not something that should ride along with a
 * broadly-granted `uat.view`.
 *
 * Defined once so the four places that enforce it — the UAT listing and its
 * middleware, and QatCycle's query/row rule pair — cannot drift apart. When they
 * drift, the symptom is a row that lists and then 403s when opened.
 */
class TestCycleAccess
{
    /** Roles that see test cycles from every department. */
    public const ALL_DEPARTMENT_ROLES = ['Dev'];

    public static function seesAllDepartments($user): bool
    {
        if (! $user) {
            return false;
        }

        if (DepartmentContext::isExecutive($user)) {
            return true;
        }

        return (bool) $user->hasAnyRole(static::ALL_DEPARTMENT_ROLES);
    }
}
