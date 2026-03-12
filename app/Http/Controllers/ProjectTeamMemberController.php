<?php

namespace App\Http\Controllers;

use App\Models\ProjectTeamMember;
use Illuminate\Http\Request;

class ProjectTeamMemberController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'nullable|exists:users,id',
            'external_name' => 'nullable|string|max:255',
            'role_type' => 'required|string|max:255',
            'team_category' => 'required|string|max:255',
        ]);

        ProjectTeamMember::create($validated);

        return redirect()->back()->with('success', 'Team member added successfully.');
    }

    public function destroy(ProjectTeamMember $projects_team_member)
    {
        $projects_team_member->delete();

        return redirect()->back()->with('success', 'Team member removed successfully.');
    }
}
