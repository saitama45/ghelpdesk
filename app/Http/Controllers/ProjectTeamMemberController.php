<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProjectTaskBoardsJob;
use App\Models\ProjectTeamMember;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectTeamMemberController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required_without:external_name|nullable|exists:users,id',
            'external_name' => 'required_without:user_id|nullable|string|max:255',
            // Department and role describe an internal member. An external member
            // is just a name, so both stay optional on that path.
            'department' => 'required_with:user_id|nullable|string|max:255',
            // Optional: members are targeted by department, and the sub-unit is
            // only inherited from the selected system user's org_path.
            'sub_unit' => 'nullable|string|max:255',
            'role_type' => 'nullable|string|max:255',
            'team_category' => 'nullable|string|max:255',
        ], [
            'user_id.required_without' => 'Please select a system user or enter an external name.',
            'external_name.required_without' => 'Please select a system user or enter an external name.',
        ]);

        $validated['sub_unit'] = $validated['sub_unit'] ?? null;

        if (!empty($validated['user_id'])) {
            $user = User::find($validated['user_id']);
            $validated['department'] = $validated['department'] ?: $user?->department;
            $validated['sub_unit'] = $validated['sub_unit'] ?: $user?->org_path;
        }

        $existsQuery = ProjectTeamMember::where('project_id', $validated['project_id']);
        if (!empty($validated['user_id'])) {
            $existsQuery->where('user_id', $validated['user_id']);
        } else {
            $existsQuery->where('external_name', $validated['external_name']);
        }

        if ($existsQuery->exists()) {
            return back()->withErrors([
                'user_id' => 'This member is already in the project team.',
                'external_name' => 'This member is already in the project team.'
            ])->withInput();
        }

        ProjectTeamMember::create($validated);

        // The board rebuild is seconds of work and nothing on this page waits for
        // it, so it runs on the queue — the member row itself is already saved.
        SyncProjectTaskBoardsJob::dispatch(
            (int) $validated['project_id'],
            $request->user()?->id,
            $request->boolean('auto_create_monthly_boards')
        );

        return redirect()->back()->with('success', 'Team member added successfully.');
    }

    public function destroy(Request $request, ProjectTeamMember $projects_team_member)
    {
        $project = $projects_team_member->project;
        $projects_team_member->delete();

        if ($project) {
            SyncProjectTaskBoardsJob::dispatch((int) $project->id, $request->user()?->id, true);
        }

        return redirect()->back()->with('success', 'Team member removed successfully.');
    }
}
