<?php

namespace App\Http\Controllers;

use App\Support\DepartmentContext;
use Illuminate\Http\Request;

class DepartmentContextController extends Controller
{
    /**
     * Set the viewed department for the current session. Any authenticated user
     * may switch, but only to a department under their active entity. Switching
     * to (or away from) their home department flips the derived access view.
     */
    public function switch(Request $request)
    {
        $request->validate([
            'department_id' => 'required|integer',
        ]);

        $accessibleIds = DepartmentContext::accessibleDepartmentIds($request->user());

        if (! in_array((int) $request->department_id, $accessibleIds, true)) {
            return redirect()->back()->with('error', 'You do not have access to that department.');
        }

        session([DepartmentContext::SESSION_KEY => (int) $request->department_id]);

        $name = DepartmentContext::accessibleDepartments($request->user())
            ->firstWhere('id', (int) $request->department_id)?->name;

        return redirect()->back()->with('success', $name ? "Viewing {$name}." : 'Department switched.');
    }

    /**
     * Open a department FROM the Executive/enterprise view in a single round-trip:
     * clear the home override (restore the DB home), set the viewed department, and
     * redirect to that department's workspace. One request avoids the multi-load
     * flash of chaining separate belong + switch + visit calls.
     */
    public function openDepartment(Request $request)
    {
        $request->validate(['department_id' => 'required|integer']);

        if (! in_array((int) $request->department_id, DepartmentContext::accessibleDepartmentIds($request->user()), true)) {
            return redirect()->back()->with('error', 'You do not have access to that department.');
        }

        session()->forget(DepartmentContext::HOME_SESSION_KEY);            // leave Executive
        session([DepartmentContext::SESSION_KEY => (int) $request->department_id]); // view it

        return redirect()->route('hub.show', 'services');
    }

    /**
     * Set the "I belong to" home department for the session (a preview/explore
     * tool). Gated to elevated cross-scope users; accepts a department id under
     * the active entity or the Executive sentinel. Everyone else keeps their DB
     * placement (users.department_id).
     */
    public function belong(Request $request)
    {
        abort_unless($request->user()->can('dashboard.filter_entity'), 403);

        $request->validate([
            'home' => 'required|string',
        ]);

        $value = $request->input('home');

        // 'self' clears the session override → reverts to the DB home department.
        if ($value === 'self') {
            session()->forget(DepartmentContext::HOME_SESSION_KEY);
            return redirect()->back()->with('success', 'Home department restored.');
        }

        if ($value === DepartmentContext::EXECUTIVE) {
            session([DepartmentContext::HOME_SESSION_KEY => DepartmentContext::EXECUTIVE]);
            return redirect()->back()->with('success', 'You now belong to Executive.');
        }

        $id = (int) $value;
        if (! in_array($id, DepartmentContext::accessibleDepartmentIds($request->user()), true)) {
            return redirect()->back()->with('error', 'You do not have access to that department.');
        }

        session([DepartmentContext::HOME_SESSION_KEY => $id]);

        $name = DepartmentContext::accessibleDepartments($request->user())
            ->firstWhere('id', $id)?->name;

        return redirect()->back()->with('success', $name ? "You now belong to {$name}." : 'Home department set.');
    }
}
