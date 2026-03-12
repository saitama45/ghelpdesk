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
            'user_id' => 'required_without:external_name|nullable|exists:users,id',
            'external_name' => 'required_without:user_id|nullable|string|max:255',
            'role_type' => 'required|string|max:255',
            'team_category' => 'required|string|max:255',
        ], [
            'user_id.required_without' => 'Please select a system user or enter an external name.',
            'external_name.required_without' => 'Please select a system user or enter an external name.',
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
