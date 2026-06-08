<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\User;
use App\Services\ProjectTaskBoardSyncService;
use Illuminate\Http\Request;

class ProjectTeamMemberController extends Controller
{
    public function __construct(private ProjectTaskBoardSyncService $projectTaskBoards)
    {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'user_id' => 'required_without:external_name|nullable|exists:users,id',
            'external_name' => 'required_without:user_id|nullable|string|max:255',
            'department' => 'required|string|max:255',
            'sub_unit' => 'required|string|max:255',
            'role_type' => 'required|string|max:255',
            'team_category' => 'required|string|max:255',
        ], [
            'user_id.required_without' => 'Please select a system user or enter an external name.',
            'external_name.required_without' => 'Please select a system user or enter an external name.',
        ]);

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

        $project = Project::with(['teamMembers.user', 'tasks'])->findOrFail($validated['project_id']);
        $this->projectTaskBoards->syncProject($project, $request->user(), null, $request->boolean('auto_create_monthly_boards'));

        return redirect()->back()->with('success', 'Team member added successfully.');
    }

    public function destroy(Request $request, ProjectTeamMember $projects_team_member)
    {
        $project = $projects_team_member->project;
        $projects_team_member->delete();

        if ($project) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, true);
        }

        return redirect()->back()->with('success', 'Team member removed successfully.');
    }
}
