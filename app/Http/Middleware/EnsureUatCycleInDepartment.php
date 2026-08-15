<?php

namespace App\Http\Middleware;

use App\Models\UatCycle;
use App\Support\DepartmentContext;
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
 *   - Executive mode may reach every department.
 *   - A cycle with no owning department is shared and reachable by everyone.
 *   - Otherwise the cycle must belong to the user's "I belong to" department.
 *
 * Dev/Admin/Solutions Admin are not special-cased: they can already switch
 * "I belong to", which is the intended escape hatch.
 */
class EnsureUatCycleInDepartment
{
    public function handle(Request $request, Closure $next): Response
    {
        $cycle = $request->route('cycle');

        if (!$cycle instanceof UatCycle) {
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

        if (!$user || DepartmentContext::isExecutive($user)) {
            return (bool) $user;
        }

        // Unowned cycles are shared across departments.
        if ($cycle->department_id === null) {
            return true;
        }

        return (int) $cycle->department_id === (int) DepartmentContext::homeDepartmentId($user);
    }
}
