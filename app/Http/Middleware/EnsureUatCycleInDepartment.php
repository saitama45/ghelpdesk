<?php

namespace App\Http\Middleware;

use App\Models\UatCycle;
use App\Support\DepartmentContext;
use App\Support\TestCycleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the department axis on every route that acts on a single UAT cycle.
 *
 * Scoping the index query alone only hides rows from the list — the URL still
 * worked, so anyone who knew or guessed an id could open, edit, export or delete
 * another department's cycle. A listing filter is not an authorisation boundary;
 * this is the boundary.
 *
 * Mirrors the listing rule exactly, so what a user can reach always matches what
 * they can see:
 *   - Executive mode and the Dev role may reach every department
 *     ({@see TestCycleAccess}).
 *   - A cycle with no owning department is shared and reachable by everyone.
 *   - Otherwise the cycle must belong to the user's "I belong to" department.
 *
 * Admin/Solutions Admin are not special-cased: they can already switch
 * "I belong to", which is the intended escape hatch.
 */
class EnsureUatCycleInDepartment
{
    public function handle(Request $request, Closure $next): Response
    {
        $cycle = $request->route('cycle');

        if (! $cycle instanceof UatCycle) {
            return $next($request);
        }

        if ($this->permits($request, $cycle)) {
            return $next($request);
        }

        abort(403, 'This UAT cycle belongs to another department.');
    }

    private function permits(Request $request, UatCycle $cycle): bool
    {
        $user = $request->user();

        if (! $user || TestCycleAccess::seesAllDepartments($user)) {
            return (bool) $user;
        }

        // Unowned cycles are shared across departments.
        if ($cycle->department_id === null) {
            return true;
        }

        // The assigned Dev is named on the cycle, and that naming is the grant.
        // They build what is under test and are routinely in a different
        // department to the one that owns it. Kept in step with the listing
        // scope in UatController::index — the two rules must never disagree, or
        // a row appears in the list and then 403s when opened.
        if ($cycle->dev_lead_id && (int) $cycle->dev_lead_id === (int) $user->id) {
            return true;
        }

        return (int) $cycle->department_id === (int) DepartmentContext::homeDepartmentId($user);
    }
}
