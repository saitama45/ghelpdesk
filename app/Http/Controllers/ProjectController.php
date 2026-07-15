<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\ProjectTemplate;
use App\Services\ProjectDashboardService;
use App\Services\ProjectTaskBoardSyncService;
use App\Services\OrganizationReferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectTaskBoardSyncService $projectTaskBoards,
        private OrganizationReferenceService $organizationReferenceService,
        private ProjectDashboardService $projectDashboard
    ) {
    }

    public function index(Request $request)
    {
        $search     = trim((string) $request->input('search', ''));
        $status     = trim((string) $request->input('status', ''));
        $storeId    = $request->input('store_id');
        $typeFilter = trim((string) $request->input('type', ''));

        // Dashboard tab filters — independent of the project-list filters above.
        $dashFrom  = trim((string) $request->input('dash_from', '')) ?: now()->startOfMonth()->toDateString();
        $dashTo    = trim((string) $request->input('dash_to', '')) ?: now()->toDateString();
        $dashTypes = array_values(array_intersect(
            array_map('strval', (array) $request->input('dash_types', [])),
            Project::projectTypes()
        ));

        $projects = Project::with(['store', 'tasks', 'subject'])
            ->when($typeFilter !== '', fn ($query) => $query->where('project_type', $typeFilter))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('store', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('target_go_live', 'desc')
            ->paginate(12)
            ->withQueryString();

        $statusOptions = Project::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->map(fn (string $status) => [
                'label' => $status,
                'value' => $status,
            ])
            ->values();

        $stats = [
            'total'           => Project::count(),
            'in_progress'     => Project::where('status', 'In Progress')->count(),
            'delayed'         => Project::where('status', 'Delayed')->count(),
            'completed'       => Project::where('status', 'Completed')->count(),
            'planning'        => Project::whereIn('status', ['Planning', 'Pending'])->count(),
            'going_live_soon' => Project::whereMonth('target_go_live', now()->month)
                ->whereYear('target_go_live', now()->year)
                ->whereNotIn('status', ['Completed', 'Cancelled'])
                ->count(),
        ];

        // Per-type counts for tab badges
        $typeCounts = Project::query()
            ->selectRaw('project_type, count(*) as total')
            ->groupBy('project_type')
            ->pluck('total', 'project_type')
            ->toArray();

        return Inertia::render('Projects/Index', [
            'projects'    => $projects,
            'stats'       => $stats,
            'typeCounts'  => $typeCounts,
            'projectTypes' => Project::projectTypes(),
            'filters' => [
                'search'   => $search,
                'status'   => $status,
                'store_id' => $storeId ? (int) $storeId : null,
                'type'     => $typeFilter,
            ],
            'dashboardFilters' => [
                'dash_from'  => $dashFrom,
                'dash_to'    => $dashTo,
                'dash_types' => $dashTypes,
            ],
            // Only resolved when the Dashboard tab asks for it (router.reload({ only: ['dashboard'] })).
            'dashboard' => Inertia::optional(
                fn () => $this->projectDashboard->build($dashTypes, $dashFrom, $dashTo)
            ),
            'statusOptions' => $statusOptions,
            'storeOptions' => Store::orderBy('name')->get(['id', 'name'])
                ->map(fn (Store $store) => ['label' => $store->name, 'value' => $store->id]),
        ]);
    }

    public function create(Request $request)
    {
        $availableBoards = \App\Models\TaskBoard::whereNull('project_id')
            ->where('board_source', 'manual')
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn ($b) => ['id' => $b->id, 'title' => $b->title])
            ->values();

        $projectTypes = Project::projectTypes();
        $defaultType = in_array($request->query('type'), $projectTypes, true)
            ? $request->query('type')
            : ($projectTypes[0] ?? 'Store Opening');

        return Inertia::render('Projects/Create', [
            'stores'          => Store::orderBy('name')->get(['id', 'name']),
            'vendors'         => Vendor::active()->orderBy('name')->get(['id', 'name']),
            'departments'     => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'projectTypes'    => $projectTypes,
            'defaultType'     => $defaultType,
            'boardYears'      => $this->boardYears(),
            'availableBoards' => $availableBoards,
        ]);
    }

    public function store(Request $request)
    {
        $isStoreOpening = $request->input('project_type') === 'Store Opening';

        $validated = $request->validate([
            'project_type'  => 'required|string|in:' . implode(',', Project::projectTypes()),
            'store_id'      => $isStoreOpening ? 'required|exists:stores,id' : 'nullable|exists:stores,id',
            'subject_type'  => 'nullable|string',
            'subject_id'    => 'nullable|integer',
            'name'          => 'required|string|max:255',
            'status'        => 'required|string',
            'board_id'      => 'nullable|exists:task_boards,id',
            'turn_over_date'               => 'nullable|date',
            'training_date'                => 'nullable|date',
            'testing_date'                 => 'nullable|date',
            'mock_service_date'            => 'nullable|date',
            'turn_over_to_franchisee_date' => 'nullable|date',
            'target_go_live' => 'nullable|date',
            'board_month'    => 'nullable|integer|min:1|max:12',
            'board_year'     => 'nullable|integer|min:2000|max:2100',
            'remarks'        => 'nullable|string',
        ]);

        $boardId = $validated['board_id'] ?? null;
        unset($validated['board_id']);

        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $project = Project::create($validated);
        $project->recalculateStatus();

        // Link existing board to this new project (board cards become project tasks)
        if ($boardId) {
            $board = \App\Models\TaskBoard::find($boardId);
            if ($board && $board->project_id === null) {
                $board->update(['project_id' => $project->id]);
                $this->projectTaskBoards->importBoardCardsAsProjectTasks($board, $project);
                $project->recalculateStatus();
            }
        }

        return redirect()->route('projects.show', $project->id)
            ->with('success', 'Project created successfully.');
    }

    public function show(Project $project)
    {
        $project->load([
            'store',
            'subject',
            'teamMembers.user:id,name,profile_photo,department,org_path',
            'taskBoard:id,project_id,title,closed_at',
            'tasks',
            'tasks.assignedUser:id,name,profile_photo,org_path',
            'tasks.supportUser:id,name,profile_photo,org_path',
            'assets'
        ]);

        $storeClass = $project->store->class ?? 'Regular';

        // Manual boards not yet linked to any project — for "Attach Board" modal
        $availableBoards = \App\Models\TaskBoard::whereNull('project_id')
            ->where('board_source', 'manual')
            ->orderBy('title')
            ->get(['id', 'title', 'department', 'sub_unit', 'created_at'])
            ->map(fn ($b) => ['id' => $b->id, 'title' => $b->title])
            ->values();

        return Inertia::render('Projects/Show', [
            'project'        => $project,
            // Whether the viewer may manage the whole project (edit every row,
            // apply templates, add/delete/reorder). Non-managers may only edit
            // the activity / sub-task rows assigned to them.
            'canManageProject' => $project->isManagedBy(auth()->user()),
            'projectTypes'   => Project::projectTypes(),
            'users'          => User::active()->orderBy('name')->get(['id', 'name', 'department', 'org_path']),
            'stores'         => Store::orderBy('name')->get(['id', 'name']),
            'vendors'        => Vendor::active()->orderBy('name')->get(['id', 'name']),
            'departments'    => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departmentOptions' => $this->departmentOptions(),
            'hierarchicalDepartments' => $this->organizationReferenceService->tree(true),
            'boardYears'     => $this->boardYears(),
            'availableBoards' => $availableBoards,
            'taskListTargets' => $this->projectTaskBoards->monthlyTargetPreview($project),
            'project_templates' => ProjectTemplate::whereIn('store_class', [$storeClass, 'Both'])
                ->withCount('activities')
                ->get(),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        // Editing project-level details is a management action — only the project
        // owner (or an admin) may do it. On legacy ownerless projects the first
        // editor claims ownership.
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to edit this project.');

        $isStoreOpening = $request->input('project_type', $project->project_type) === 'Store Opening';

        $validated = $request->validate([
            'project_type'  => 'required|string|in:' . implode(',', Project::projectTypes()),
            'store_id'      => $isStoreOpening ? 'required|exists:stores,id' : 'nullable|exists:stores,id',
            'subject_type'  => 'nullable|string',
            'subject_id'    => 'nullable|integer',
            'name'          => 'required|string|max:255',
            'status'        => 'required|string',
            'turn_over_date'               => 'nullable|date',
            'training_date'                => 'nullable|date',
            'testing_date'                 => 'nullable|date',
            'mock_service_date'            => 'nullable|date',
            'turn_over_to_franchisee_date' => 'nullable|date',
            'target_go_live' => 'nullable|date',
            'board_month'    => 'nullable|integer|min:1|max:12',
            'board_year'     => 'nullable|integer|min:2000|max:2100',
            'remarks'        => 'nullable|string',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $project->update($validated);
        $project->recalculateStatus();

        if ($request->boolean('auto_create_monthly_boards')) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, true);
        }

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function duplicate(Project $project)
    {
        $actorId = auth()->id();

        DB::transaction(function () use ($project, $actorId) {
            $newProject = $project->replicate(['id', 'created_at', 'updated_at']);
            $newProject->name = 'Copy of ' . $project->name;
            $newProject->status = 'Planning';
            // The user duplicating the project owns the copy.
            $newProject->created_by = $actorId;
            $newProject->updated_by = $actorId;
            $newProject->save();

            // Load tasks with their sub-tasks
            $tasks = $project->tasks()->with('subTasks')->whereNull('parent_task_id')->get();

            foreach ($tasks as $parentTask) {
                $newParent = $parentTask->replicate(['id', 'project_id', 'parent_task_id', 'created_at', 'updated_at']);
                $newParent->project_id = $newProject->id;
                $newParent->status = 'Pending';
                $newParent->progress = 0;
                $newParent->assigned_to = null;
                $newParent->created_by = $actorId;
                $newParent->updated_by = $actorId;
                $newParent->save();

                foreach ($parentTask->subTasks as $subTask) {
                    $newSub = $subTask->replicate(['id', 'project_id', 'parent_task_id', 'created_at', 'updated_at']);
                    $newSub->project_id = $newProject->id;
                    $newSub->parent_task_id = $newParent->id;
                    $newSub->status = 'Pending';
                    $newSub->progress = 0;
                    $newSub->assigned_to = null;
                    $newSub->created_by = $actorId;
                    $newSub->updated_by = $actorId;
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
            // Clean up all task cards associated with the project to prevent constraint conflicts
            $cardIds = \App\Models\TaskCard::where('project_id', $project->id)->pluck('id')->all();
            if (!empty($cardIds)) {
                // Delete many-to-many associations
                DB::table('task_card_assignees')->whereIn('task_card_id', $cardIds)->delete();
                DB::table('task_card_label')->whereIn('task_card_id', $cardIds)->delete();
                DB::table('task_card_watchers')->whereIn('task_card_id', $cardIds)->delete();

                // Delete child models
                \App\Models\TaskCardAttachment::whereIn('task_card_id', $cardIds)->delete();
                \App\Models\TaskCardComment::whereIn('task_card_id', $cardIds)->delete();

                // Dissociate or delete activities
                DB::table('task_card_activities')->whereIn('task_card_id', $cardIds)->update(['task_card_id' => null]);

                // Delete checklist items
                $checklistIds = \App\Models\TaskChecklist::whereIn('task_card_id', $cardIds)->pluck('id')->all();
                if (!empty($checklistIds)) {
                    \App\Models\TaskChecklistItem::whereIn('task_checklist_id', $checklistIds)->delete();
                    \App\Models\TaskChecklist::whereIn('id', $checklistIds)->delete();
                }

                // Delete task cards themselves
                \App\Models\TaskCard::whereIn('id', $cardIds)->forceDelete();
            }

            if ($project->taskBoard) {
                $project->taskBoard->forceDelete();
            }
            $project->teamMembers()->delete();
            $project->assets()->delete();
            
            // Delete subtasks first to avoid parent_task_id constraint cycle issues
            $project->tasks()->whereNotNull('parent_task_id')->forceDelete();
            $project->tasks()->whereNull('parent_task_id')->forceDelete();
            
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
