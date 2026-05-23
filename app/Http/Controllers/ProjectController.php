<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use App\Models\ProjectTemplate;
use App\Services\ProjectTaskBoardSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(private ProjectTaskBoardSyncService $projectTaskBoards)
    {
    }

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
            'boardYears' => $this->boardYears(),
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
            'board_month' => 'nullable|integer|min:1|max:12',
            'board_year' => 'nullable|integer|min:2000|max:2100',
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
            'teamMembers.user:id,name,profile_photo,department,org_path',
            'taskBoard:id,project_id,title,closed_at',
            'tasks',
            'tasks.assignedUser:id,name,profile_photo,org_path',
            'tasks.supportUser:id,name,profile_photo,org_path',
            'assets'
        ]);

        $storeClass = $project->store->class ?? 'Regular';

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'department', 'org_path']),
            'stores' => Store::all(['id', 'name']),
            'departmentOptions' => $this->departmentOptions(),
            'boardYears' => $this->boardYears(),
            'taskListTargets' => $this->projectTaskBoards->monthlyTargetPreview($project),
            'project_templates' => ProjectTemplate::whereIn('store_class', [$storeClass, 'Both'])
                ->withCount('activities')
                ->get(),
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
            'board_month' => 'nullable|integer|min:1|max:12',
            'board_year' => 'nullable|integer|min:2000|max:2100',
            'remarks' => 'nullable|string',
        ]);

        $project->update($validated);
        $project->recalculateStatus();

        if ($request->boolean('auto_create_monthly_boards')) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, true);
        }

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function duplicate(Project $project)
    {
        DB::transaction(function () use ($project) {
            $newProject = $project->replicate(['id', 'created_at', 'updated_at']);
            $newProject->name = 'Copy of ' . $project->name;
            $newProject->status = 'Planning';
            $newProject->save();

            // Load tasks with their sub-tasks
            $tasks = $project->tasks()->with('subTasks')->whereNull('parent_task_id')->get();

            foreach ($tasks as $parentTask) {
                $newParent = $parentTask->replicate(['id', 'project_id', 'parent_task_id', 'created_at', 'updated_at']);
                $newParent->project_id = $newProject->id;
                $newParent->status = 'Pending';
                $newParent->progress = 0;
                $newParent->assigned_to = null;
                $newParent->save();

                foreach ($parentTask->subTasks as $subTask) {
                    $newSub = $subTask->replicate(['id', 'project_id', 'parent_task_id', 'created_at', 'updated_at']);
                    $newSub->project_id = $newProject->id;
                    $newSub->parent_task_id = $newParent->id;
                    $newSub->status = 'Pending';
                    $newSub->progress = 0;
                    $newSub->assigned_to = null;
                    $newSub->save();
                }
            }

            foreach ($project->assets as $asset) {
                $newAsset = $asset->replicate(['id', 'project_id', 'created_at', 'updated_at']);
                $newAsset->project_id = $newProject->id;
                $newAsset->save();
            }
        });

        return redirect()->back()->with('success', 'Project duplicated successfully.');
    }

    public function destroy(Project $project)
    {
        abort_unless(auth()->user()->can('projects.delete'), 403);

        DB::transaction(function () use ($project) {
            if ($project->taskBoard) {
                $project->taskBoard->forceDelete();
            }
            $project->teamMembers()->delete();
            $project->assets()->delete();
            $project->tasks()->forceDelete();
            
            $project->forceDelete();
        });

        return redirect()->route('projects.index')
            ->with('success', 'Project permanently deleted.');
    }

    private function departmentOptions(): array
    {
        return User::active()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->orderBy('department')
            ->orderBy('org_path')
            ->get(['department', 'org_path'])
            ->groupBy(fn (User $user) => trim((string) $user->department))
            ->map(fn ($users, string $department) => [
                'name' => $department,
                'sub_units' => $users
                    ->pluck('org_path')
                    ->map(fn ($orgPath) => trim((string) $orgPath))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function boardYears(): array
    {
        $year = (int) now()->year;

        return range($year - 1, $year + 3);
    }
}
