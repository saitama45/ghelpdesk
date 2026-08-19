<?php

namespace App\Http\Middleware;

use App\Models\QatCycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the department axis on every route that acts on a single QAT cycle.
 *
 * Scoping the index query alone only hides rows from the list — the URL still
 * works, so anyone who knows or guesses an id could open, edit, export or delete
 * another department's cycle. A listing filter is not an authorisation boundary;
 * this is the boundary.
 *
 * The rule itself lives on the model, in {@see QatCycle::isVisibleTo()}, whose
 * query twin drives the listing. Expressing it once is deliberate: the UAT module
 * carries the same rule in two places with a comment warning that they must never
 * disagree, and when they do the symptom is a row that lists and then 403s when
 * opened.
 */
class EnsureQatCycleInDepartment
{
    public function handle(Request $request, Closure $next): Response
    {
        $cycle = $request->route('cycle');

        if (! $cycle instanceof QatCycle) {
            return $next($request);
        }

        if ($cycle->isVisibleTo($request->user())) {
            return $next($request);
        }

        abort(403, 'This QAT cycle belongs to another department.');
    }
}
