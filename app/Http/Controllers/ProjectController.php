<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = Project::with(['store', 'tasks'])
            ->orderBy('target_go_live', 'desc')
            ->paginate(10);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
        ]);
    }

    public function create()
    {
        return Inertia::render('Projects/Create', [
            'stores' => Store::all(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|string',
            'turn_over_date' => 'nullable|date',
            'training_date' => 'nullable|date',
            'testing_date' => 'nullable|date',
            'mock_service_date' => 'nullable|date',
            'turn_over_to_franchisee_date' => 'nullable|date',
            'target_go_live' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        $project = Project::create($validated);
        $project->recalculateStatus();

        return redirect()->route('projects.show', $project->id)
            ->with('success', 'Project created successfully.');
    }

    public function show(Project $project)
    {
        $project->load([
            'store',
            'teamMembers.user:id,name,profile_photo',
            'tasks',
            'tasks.assignedUser:id,name,profile_photo',
            'tasks.supportUser:id,name,profile_photo',
            'assets'
        ]);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::all(['id', 'name']),
            'stores' => Store::all(['id', 'name']),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|string',
            'turn_over_date' => 'nullable|date',
            'training_date' => 'nullable|date',
            'testing_date' => 'nullable|date',
            'mock_service_date' => 'nullable|date',
            'turn_over_to_franchisee_date' => 'nullable|date',
            'target_go_live' => 'nullable|date',
            'remarks' => 'nullable|string',
        ]);

        $project->update($validated);
        $project->recalculateStatus();

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted successfully.');
    }
}
