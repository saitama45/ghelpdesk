<?php

namespace App\Http\Controllers;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskCard;
use App\Models\TaskCardActivity;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\OrganizationReferenceService;
use App\Services\ProjectTaskBoardSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TaskBoardController extends Controller implements HasMiddleware
{
    public function __construct(
        private ProjectTaskBoardSyncService $projectTaskBoards,
        private OrganizationReferenceService $orgReference,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:task_boards.view', only: ['index', 'show', 'toggleStar', 'toggleWatch', 'openProjectBoard', 'syncProject']),
            new Middleware('can:task_boards.create', only: ['store', 'generateMonthly']),
            new Middleware('can:task_boards.edit', only: ['update']),
            new Middleware('can:task_boards.delete', only: ['destroy', 'restore']),
            new Middleware('can:task_boards.manage_members', only: ['storeMember', 'updateMember', 'destroyMember']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $showClosed = $request->boolean('closed');

        // Resolve department filter — same default-to-user's-dept logic as Dashboard
        $departmentIdFilter = $request->input('department_id');
        $departmentNodeIdFilter = $request->input('department_node_id');

        if (
            ! $request->boolean('skip_default_department')
            && ! $request->has('department_id')
            && ! $request->has('department_node_id')
        ) {
            $departmentIdFilter = $user->department_id
                ?? optional($user->loadMissing('departmentNode')->departmentNode)->department_id;
        }

        // Resolve the department name string to match board.department
        $departmentNameFilter = null;
        $legacyDepartmentFilter = null;
        $orgPathFilter = null;
        $nodeName = null;
        $legacySubUnitFilter = null;
        
        if ($departmentIdFilter) {
            $dept = \App\Models\Department::find($departmentIdFilter);
            $departmentNameFilter = $dept?->name;
            $legacyDepartmentFilter = $dept?->code;
        } elseif ($departmentNodeIdFilter) {
            $payload = $this->orgReference->payloadFromNodeId((int) $departmentNodeIdFilter);
            $departmentNameFilter = $payload['department'] ?? null;
            $legacyDepartmentFilter = $payload['department_code'] ?? null;
            $orgPathFilter = $payload['org_path'] ?? null;
            $nodeName = $payload['node_name'] ?? null;
            
            if (!empty($payload['node_code'])) {
                $parts = explode('-', $payload['node_code']);
                $legacySubUnitFilter = end($parts);
            }
        }

        $isExplicitFilter = $request->has('department_id') || $request->has('department_node_id');

        $boards = TaskBoard::query()
            ->with([
                'creator:id,name',
                'members:id,name,profile_photo,org_path',
                'project.store:id,name',
                'cards.projectTask:id,project_id,parent_task_id,name,category,status,progress',
            ])
            ->when(! $this->canSeeAllBoards($user), function ($query) use ($user) {
                $query->whereHas('memberRecords', fn ($q) => $q->where('user_id', $user->id));
            })
            ->when(! $showClosed, fn ($query) => $query->whereNull('closed_at'))
            ->when($departmentNameFilter, function ($query) use ($departmentNameFilter, $legacyDepartmentFilter, $orgPathFilter, $nodeName, $legacySubUnitFilter, $user, $isExplicitFilter) {
                if ($isExplicitFilter) {
                    $query->where(function ($q) use ($departmentNameFilter, $legacyDepartmentFilter, $orgPathFilter, $nodeName, $legacySubUnitFilter) {
                        $q->where(function ($deptQuery) use ($departmentNameFilter, $legacyDepartmentFilter) {
                            $deptQuery->where('department', $departmentNameFilter);
                            if ($legacyDepartmentFilter) {
                                $deptQuery->orWhere('department', $legacyDepartmentFilter);
                            }
                        });

                        if ($orgPathFilter) {
                            $q->where(function ($subQuery) use ($orgPathFilter, $nodeName, $legacySubUnitFilter) {
                                $subQuery->where('sub_unit', 'like', $orgPathFilter . '%');
                                if ($nodeName) {
                                    $subQuery->orWhere('sub_unit', 'like', $nodeName . '%');
                                }
                                if ($legacySubUnitFilter) {
                                    $subQuery->orWhere('sub_unit', $legacySubUnitFilter);
                                }
                            });
                        }
                    });
                } else {
                    $query->where(function ($q) use ($departmentNameFilter, $legacyDepartmentFilter, $user) {
                        $q->where('department', $departmentNameFilter);
                        if ($legacyDepartmentFilter) {
                            $q->orWhere('department', $legacyDepartmentFilter);
                        }
                        $q->orWhereNull('department')
                          ->orWhere('department', '')
                          ->orWhere('created_by', $user->id)
                          ->orWhereHas('memberRecords', fn ($m) => $m->where('user_id', $user->id));
                    });
                }
            })
            ->latest('updated_at')
            ->get()
            ->map(fn (TaskBoard $board) => $this->boardSummary($board, $user));

        return Inertia::render('TaskBoards/Index', [
            'boards' => $boards,
            'users' => $this->activeUsers(),
            'monthlyDepartments' => $this->monthlyDepartmentOptions(),
            'hierarchicalDepartments' => $this->orgReference->tree(true),
            'filters' => [
                'closed' => $showClosed,
                'department_id' => $departmentIdFilter,
                'department_node_id' => $departmentNodeIdFilter,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'background_type' => 'nullable|in:color,image',
            'background_value' => 'nullable|string|max:1000',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);

        $board = DB::transaction(function () use ($request, $validated) {
            $board = TaskBoard::create([
                'board_source' => 'manual',
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'background_type' => $validated['background_type'] ?? 'color',
                'background_value' => $validated['background_value'] ?? '#0f766e',
                'created_by' => $request->user()->id,
            ]);

            $memberIds = collect($validated['member_ids'] ?? [])
                ->push($request->user()->id)
                ->unique()
                ->values();

            foreach ($memberIds as $memberId) {
                $board->memberRecords()->create([
                    'user_id' => $memberId,
                    'role' => $memberId === $request->user()->id ? 'admin' : 'member',
                ]);
            }

            foreach ($this->defaultLabels() as $index => $label) {
                $board->labels()->create([
                    'name' => $label['name'],
                    'color' => $label['color'],
                    'sort_order' => $index,
                ]);
            }

            $this->recordActivity($board, null, $request->user()->id, 'board.created', 'created this board');

            return $board;
        });

        return redirect()->route('task-boards.show', $board)->with('success', 'Task board created successfully.');
    }

    public function generateMonthly(Request $request)
    {
        $validated = $request->validate([
            'department' => 'required|string|max:255',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $department = $this->cleanOrgValue($validated['department']);
        $month = (int) $validated['month'];
        $year = (int) $validated['year'];

        if ($department === '') {
            throw ValidationException::withMessages([
                'department' => 'Select a department.',
            ]);
        }

        $usersBySubUnit = $this->activeUsersForDepartment($department)
            ->groupBy(fn (User $user) => $this->cleanOrgValue($user->org_path))
            ->filter(fn ($users, $subUnit) => (string) $subUnit !== '')
            ->sortKeys();

        if ($usersBySubUnit->isEmpty()) {
            throw ValidationException::withMessages([
                'department' => 'No active sub-units found for this department.',
            ]);
        }

        $periodLabel = CarbonImmutable::create($year, $month, 1)->format('F Y');
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($request, $department, $month, $year, $periodLabel, $usersBySubUnit, &$created, &$skipped) {
            foreach ($usersBySubUnit as $subUnit => $subUnitUsers) {
                $monthlyKey = $this->monthlyBoardKey($department, $subUnit, $month, $year);
                $title = $this->monthlyBoardTitle($department, $subUnit, $periodLabel);

                $existing = TaskBoard::withTrashed()
                    ->where('monthly_key', $monthlyKey)
                    ->first();

                if ($existing) {
                    $skipped[] = [
                        'id' => $existing->id,
                        'title' => $existing->title,
                        'sub_unit' => $subUnit,
                    ];

                    continue;
                }

                $board = TaskBoard::create([
                    'board_source' => 'monthly',
                    'department' => $department,
                    'sub_unit' => $subUnit,
                    'board_month' => $month,
                    'board_year' => $year,
                    'monthly_key' => $monthlyKey,
                    'title' => $title,
                    'description' => "Monthly task board for {$department} / {$subUnit} - {$periodLabel}.",
                    'background_type' => 'color',
                    'background_value' => '#0f766e',
                    'created_by' => $request->user()->id,
                ]);

                $memberIds = $subUnitUsers
                    ->pluck('id')
                    ->push($request->user()->id)
                    ->unique()
                    ->values();

                foreach ($memberIds as $memberId) {
                    $board->memberRecords()->create([
                        'user_id' => $memberId,
                        'role' => (int) $memberId === (int) $request->user()->id ? 'admin' : 'member',
                    ]);
                }

                foreach ($this->defaultLabels() as $index => $label) {
                    $board->labels()->create([
                        'name' => $label['name'],
                        'color' => $label['color'],
                        'sort_order' => $index,
                    ]);
                }

                $this->recordActivity(
                    $board,
                    null,
                    $request->user()->id,
                    'board.monthly_created',
                    'generated this monthly board',
                    [
                        'department' => $department,
                        'sub_unit' => $subUnit,
                        'month' => $month,
                        'year' => $year,
                    ]
                );

                $created[] = [
                    'id' => $board->id,
                    'title' => $board->title,
                    'sub_unit' => $subUnit,
                ];
            }
        });

        $message = count($created) . ' monthly board' . (count($created) === 1 ? '' : 's') . ' created.';

        if (count($skipped) > 0) {
            $message .= ' ' . count($skipped) . ' existing board' . (count($skipped) === 1 ? '' : 's') . ' skipped.';
        }

        return redirect()
            ->route('task-boards.index')
            ->with('success', $message)
            ->with('monthly_generation', [
                'created' => $created,
                'skipped' => $skipped,
            ]);
    }

    public function show(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAccess($taskBoard, $request->user());

        $taskBoard->memberRecords()
            ->where('user_id', $request->user()->id)
            ->update(['last_opened_at' => now()]);

        $taskBoard->load([
            'creator:id,name',
            'members:id,name,email,profile_photo,org_path',
            'watchers:id,name',
            'project.store:id,name',
            'labels',
            'activities.actor:id,name,profile_photo',
            'activities.card:id,title',
        ]);

        $cards = $taskBoard->cards()
            ->reorder()
            ->with([
                'creator:id,name,profile_photo',
                'assignees:id,name,email,profile_photo,org_path',
                'labels',
                'watchers:id,name',
                'project:id,name,status,store_id,board_month,board_year',
                'project.store:id,name',
                'checklists.items.assignee:id,name,profile_photo,org_path',
                'checklists.items.children.assignee:id,name,profile_photo,org_path',
                'comments.user:id,name,profile_photo',
                'attachments.user:id,name,profile_photo',
                'activities.actor:id,name,profile_photo',
                'projectTask.assignedUser:id,name,email,profile_photo',
                'projectTask.supportUser:id,name,email,profile_photo',
                'projectTask.parentTask:id,name,category',
            ])
            ->orderBy('status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('TaskBoards/Show', [
            'board' => $this->boardDetail($taskBoard, $cards, $request->user()),
            'statuses' => TaskCard::STATUSES,
            'users' => $this->activeUsers(),
        ]);
    }

    public function update(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'background_type' => 'nullable|in:color,image',
            'background_value' => 'nullable|string|max:1000',
        ]);

        $taskBoard->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'background_type' => $validated['background_type'] ?? $taskBoard->background_type,
            'background_value' => $validated['background_value'] ?? $taskBoard->background_value,
        ]);

        $this->recordActivity($taskBoard, null, $request->user()->id, 'board.updated', 'updated board settings');

        return $this->jsonOrBack($request, ['board' => $this->boardSummary($taskBoard->fresh(['members']), $request->user())], 'Board updated successfully.');
    }

    public function destroy(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $taskBoard->update(['closed_at' => now()]);
        $this->recordActivity($taskBoard, null, $request->user()->id, 'board.closed', 'closed this board');

        return redirect()->route('task-boards.index')->with('success', 'Task board closed successfully.');
    }

    public function restore(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $taskBoard->update(['closed_at' => null]);
        $this->recordActivity($taskBoard, null, $request->user()->id, 'board.restored', 'reopened this board');

        return redirect()->route('task-boards.show', $taskBoard)->with('success', 'Task board restored successfully.');
    }

    public function openProjectBoard(Request $request, Project $project)
    {
        $board = $this->projectTaskBoards->openBoard($project, $request->user(), $request->boolean('auto_create_monthly_boards', true));

        return redirect()
            ->route('task-boards.show', $board)
            ->with('success', 'Project task board is ready.');
    }

    public function syncProject(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardEditor($taskBoard, $request->user());

        if (!$taskBoard->project_id) {
            abort(422, 'This board is not linked to a project.');
        }

        $this->projectTaskBoards->syncProject($taskBoard->project, $request->user(), $taskBoard, true);

        if ($request->wantsJson()) {
            return response()->json(['synced' => true]);
        }

        return redirect()->back()->with('success', 'Project activities synced to this task board.');
    }

    public function toggleStar(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAccess($taskBoard, $request->user());

        $validated = $request->validate([
            'starred' => 'required|boolean',
        ]);

        $record = $taskBoard->memberRecords()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['role' => 'admin']
        );
        $record->update(['starred' => $validated['starred']]);

        return response()->json(['starred' => $record->starred]);
    }

    public function toggleWatch(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAccess($taskBoard, $request->user());

        $validated = $request->validate([
            'watching' => 'required|boolean',
        ]);

        if ($validated['watching']) {
            $taskBoard->watchers()->syncWithoutDetaching([$request->user()->id]);
        } else {
            $taskBoard->watchers()->detach($request->user()->id);
        }

        return response()->json(['watching' => $validated['watching']]);
    }

    public function storeMember(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $validated = $request->validate([
            'user_id' => 'nullable|required_without:user_ids|exists:users,id',
            'user_ids' => 'nullable|required_without:user_id|array',
            'user_ids.*' => 'integer|exists:users,id',
            'role' => 'required|in:admin,member,observer',
        ]);

        $userIds = collect($validated['user_ids'] ?? [$validated['user_id']])
            ->filter()
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            TaskBoardMember::updateOrCreate(
                ['task_board_id' => $taskBoard->id, 'user_id' => $userId],
                ['role' => $validated['role']]
            );
        }

        $names = User::whereIn('id', $userIds)->pluck('name')->implode(', ');
        $this->recordActivity($taskBoard, null, $request->user()->id, 'member.added', 'added ' . $names . ' to this board');

        return response()->json([
            'members' => $this->memberPayload($taskBoard->fresh('members')),
        ]);
    }

    public function updateMember(Request $request, TaskBoard $taskBoard, User $user)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $validated = $request->validate([
            'role' => 'required|in:admin,member,observer',
        ]);

        $record = $taskBoard->memberRecords()->where('user_id', $user->id)->firstOrFail();
        $record->update(['role' => $validated['role']]);

        $this->recordActivity($taskBoard, null, $request->user()->id, 'member.updated', 'changed ' . $user->name . ' to ' . $validated['role']);

        return response()->json([
            'members' => $this->memberPayload($taskBoard->fresh('members')),
        ]);
    }

    public function destroyMember(Request $request, TaskBoard $taskBoard, User $user)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        if ((int) $user->id === (int) $taskBoard->created_by) {
            abort(422, 'The board creator cannot be removed.');
        }

        $taskBoard->memberRecords()->where('user_id', $user->id)->delete();
        $taskBoard->watchers()->detach($user->id);
        $taskBoard->cards()->each(fn (TaskCard $card) => $card->assignees()->detach($user->id));

        $this->recordActivity($taskBoard, null, $request->user()->id, 'member.removed', 'removed ' . $user->name . ' from this board');

        return response()->json([
            'members' => $this->memberPayload($taskBoard->fresh('members')),
        ]);
    }

    private function canSeeAllBoards(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'Solutions Admin']);
    }

    private function ensureBoardAccess(TaskBoard $board, User $user): void
    {
        if ($this->canSeeAllBoards($user) || $board->isMember($user)) {
            return;
        }

        abort(403);
    }

    private function ensureBoardAdmin(TaskBoard $board, User $user): void
    {
        if ($this->canSeeAllBoards($user) || $board->memberRole($user) === 'admin') {
            return;
        }

        abort(403);
    }

    private function ensureBoardEditor(TaskBoard $board, User $user): void
    {
        if ($this->canSeeAllBoards($user) || in_array($board->memberRole($user), ['admin', 'member'], true)) {
            return;
        }

        abort(403);
    }

    private function boardSummary(TaskBoard $board, User $user): array
    {
        $memberRecord = $board->memberRecords->firstWhere('user_id', $user->id);

        return [
            'id' => $board->id,
            'title' => $board->title,
            'description' => $board->description,
            'background_type' => $board->background_type,
            'background_value' => $board->background_value ?: '#0f766e',
            'closed_at' => $board->closed_at,
            'created_by' => $board->created_by,
            'creator' => $board->creator,
            'board_source' => $board->board_source ?: ($board->project_id ? 'project' : 'manual'),
            'department' => $board->department,
            'sub_unit' => $board->sub_unit,
            'board_month' => $board->board_month,
            'board_year' => $board->board_year,
            'members_count' => $board->members->count(),
            'members' => $this->memberPayload($board),
            'my_role' => $this->canSeeAllBoards($user) ? ($memberRecord?->role ?? 'admin') : $memberRecord?->role,
            'starred' => (bool) $memberRecord?->starred,
            'is_project_board' => (bool) $board->project_id,
            'is_monthly_board' => $board->board_source === 'monthly',
            'project' => $this->boardProjectPayload($board),
            'updated_at' => $board->updated_at,
        ];
    }

    private function boardDetail(TaskBoard $board, $cards, User $user): array
    {
        $summary = $this->boardSummary($board, $user);

        return [
            ...$summary,
            'labels' => $board->labels,
            'cards' => $cards->map(fn (TaskCard $card) => $this->cardPayload($card))->values(),
            'activities' => $board->activities->take(80)->values(),
            'milestones' => $this->boardMilestones($cards),
            'watching' => $board->watchers->contains('id', $user->id),
        ];
    }

    private function cardPayload(TaskCard $card): array
    {
        return [
            'id' => $card->id,
            'task_board_id' => $card->task_board_id,
            'project_id' => $card->project_id,
            'title' => $card->title,
            'description' => $card->description,
            'status' => $card->status,
            'sort_order' => $card->sort_order,
            'start_at' => $card->start_at,
            'due_at' => $card->due_at,
            'due_reminder_minutes' => $card->due_reminder_minutes,
            'due_complete' => $card->due_complete,
            'cover_type' => $card->cover_type,
            'cover_value' => $card->cover_value,
            'archived_at' => $card->archived_at,
            'created_at' => $card->created_at,
            'updated_at' => $card->updated_at,
            'creator' => $card->creator,
            'assignees' => $card->assignees,
            'labels' => $card->labels,
            'watchers' => $card->watchers,
            'checklists' => $card->checklists,
            'comments' => $card->comments,
            'attachments' => $card->attachments,
            'activities' => $card->activities->take(50)->values(),
            'project_task' => $this->projectTaskPayload($card),
            'project' => $card->project ? [
                'id' => $card->project->id,
                'name' => $card->project->name,
                'status' => $card->project->status,
                'store' => $card->project->store,
                'board_month' => $card->project->board_month,
                'board_year' => $card->project->board_year,
            ] : null,
            'checklist_totals' => $card->checklist_totals,
        ];
    }

    private function projectTaskPayload(TaskCard $card): ?array
    {
        $task = $card->projectTask;

        if (!$task) {
            return null;
        }

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'parent_task_id' => $task->parent_task_id,
            'name' => $task->name,
            'category' => $task->category ?: 'General',
            'status' => $task->status,
            'progress' => $task->progress,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'end_date' => $task->end_date?->format('Y-m-d'),
            'assigned_to' => $task->assigned_to,
            'support_by' => $task->support_by,
            'external_assignment' => $task->external_assignment,
            'is_subtask' => (bool) $task->parent_task_id,
            'parent_task' => $task->parentTask ? [
                'id' => $task->parentTask->id,
                'name' => $task->parentTask->name,
                'category' => $task->parentTask->category,
            ] : null,
            'assigned_user' => $task->assignedUser,
            'support_user' => $task->supportUser,
        ];
    }

    private function boardProjectPayload(TaskBoard $board): ?array
    {
        if (!$board->project) {
            return null;
        }

        $cards = $board->relationLoaded('cards')
            ? $board->cards
            : $board->cards()->with('projectTask')->get();

        $projectTasks = $cards
            ->map(fn (TaskCard $card) => $card->projectTask)
            ->filter();

        return [
            'id' => $board->project->id,
            'name' => $board->project->name,
            'status' => $board->project->status,
            'store' => $board->project->store,
            'task_board_id' => $board->id,
            'activity_count' => $projectTasks->whereNull('parent_task_id')->count(),
            'subtask_count' => $projectTasks->whereNotNull('parent_task_id')->count(),
            'milestone_count' => $projectTasks->pluck('category')->filter()->unique()->count(),
            'progress' => $projectTasks->count()
                ? (int) round($projectTasks->avg(fn (ProjectTask $task) => (int) $task->progress))
                : 0,
        ];
    }

    private function boardMilestones($cards): array
    {
        return $cards
            ->flatMap(fn (TaskCard $card) => $card->projectTask
                ? [$card->projectTask->category ?: null]
                : ($card->checklists?->pluck('title')->all() ?? []))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function memberPayload(TaskBoard $board): array
    {
        return $board->members->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'profile_photo' => $member->profile_photo,
            'sub_unit' => $member->org_path,
            'role' => $member->pivot->role,
            'starred' => (bool) $member->pivot->starred,
        ])->values()->all();
    }

    private function monthlyDepartmentOptions(): array
    {
        $rows = User::active()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->orderBy('department')
            ->orderBy('org_path')
            ->orderBy('name')
            ->get(['id', 'department', 'org_path'])
            ->map(function (User $user) {
                $department = $this->cleanOrgValue($user->department);
                $subUnit = $this->cleanOrgValue($user->org_path);

                return [
                    'department' => $department,
                    'department_key' => $this->normalizeOrgKey($department),
                    'sub_unit' => $subUnit,
                    'sub_unit_key' => $this->normalizeOrgKey($subUnit),
                ];
            })
            ->filter(fn (array $row) => $row['department'] !== '' && $row['sub_unit'] !== '');

        return $rows
            ->groupBy('department_key')
            ->map(function ($departmentRows) {
                return [
                    'name' => $departmentRows->first()['department'],
                    'sub_units' => $departmentRows
                        ->groupBy('sub_unit_key')
                        ->map(fn ($subUnitRows) => [
                            'name' => $subUnitRows->first()['sub_unit'],
                            'user_count' => $subUnitRows->count(),
                        ])
                        ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function activeUsersForDepartment(string $department)
    {
        $departmentKey = $this->normalizeOrgKey($department);

        return User::active()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->orderBy('org_path')
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'org_path'])
            ->filter(fn (User $user) => $this->normalizeOrgKey($user->department) === $departmentKey)
            ->values();
    }

    private function monthlyBoardTitle(string $department, string $subUnit, string $periodLabel): string
    {
        return "{$subUnit} {$periodLabel}";
    }

    private function monthlyBoardKey(string $department, string $subUnit, int $month, int $year): string
    {
        $monthKey = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $orgKey = $this->normalizeOrgKey($department) . '|' . $this->normalizeOrgKey($subUnit);

        return "monthly:{$year}:{$monthKey}:" . hash('sha256', $orgKey);
    }

    private function cleanOrgValue(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function normalizeOrgKey(?string $value): string
    {
        return strtolower($this->cleanOrgValue($value));
    }

    private function activeUsers()
    {
        return User::active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_photo', 'department', 'org_path']);
    }

    private function defaultLabels(): array
    {
        return [
            ['name' => 'Urgent', 'color' => 'red'],
            ['name' => 'Support', 'color' => 'blue'],
            ['name' => 'Store', 'color' => 'emerald'],
            ['name' => 'SAP', 'color' => 'violet'],
            ['name' => 'POS', 'color' => 'amber'],
            ['name' => 'Finance', 'color' => 'pink'],
        ];
    }

    private function recordActivity(TaskBoard $board, ?TaskCard $card, ?int $actorId, string $action, string $description, array $meta = []): void
    {
        TaskCardActivity::create([
            'task_board_id' => $board->id,
            'task_card_id' => $card?->id,
            'actor_id' => $actorId,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }

    private function jsonOrBack(Request $request, array $payload, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return redirect()->back()->with('success', $message);
    }
}
