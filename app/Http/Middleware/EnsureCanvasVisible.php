<?php

namespace App\Http\Middleware;

use App\Models\Contracts\SharedCanvas;
use App\Support\SharedCanvasAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every route that acts on one shared canvas (diagram, standalone whiteboard). The
 * index query only hides rows; this is the boundary that stops a private or other-department
 * record from opening by URL. Same rule as the listing ({@see SharedCanvasAccess}).
 */
class EnsureCanvasVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bound in full ({diagram}) or without LOBs ({lightDiagram}) depending on the route.
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof SharedCanvas && ! SharedCanvasAccess::canView($request->user(), $parameter)) {
                abort(403, 'This is private or belongs to another department.');
            }
        }

        return $next($request);
    }
}
