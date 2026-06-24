<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\TaskCardActivity;
use App\Models\TaskChecklist;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskBoardSyncService
{
    public function openBoard(Project $project, User $actor, bool $autoCreateMonthlyBoards = true): TaskBoard
    {
        $boards = $this->syncProjectToMonthlyBoards($project, $actor, $autoCreateMonthlyBoards);

        if ($boards->isEmpty()) {
            throw ValidationException::withMessages([
                'task_list' => 'Set the project board month/year and at least one project team department/sub-unit target first.',
            ]);
        }

        return $boards->first()->fresh();
    }

    public function syncProject(Project $project, ?User $actor = null, ?TaskBoard $board = null, bool $autoCreateMonthlyBoards = true): ?TaskBoard
    {
        if ($board?->project_id && $board->board_source === 'project') {
            return $this->syncLegacyProjectBoard($project, $actor, $board);
        }

        // A manual board that was linked to a project: mirror its checklist structure and
        // clean up any stray card-level activities. Cards are containers, not activities.
        if ($board?->project_id && in_array($board->board_source, ['manual', null], true)) {
            $this->syncManualLinkedBoard($board);

            return $board->fresh();
        }

        return $this->syncProjectToMonthlyBoards($project, $actor, $autoCreateMonthlyBoards)->first();
    }

    public function syncProjectToMonthlyBoards(Project $project, ?User $actor = null, bool $autoCreateMonthlyBoards = true): Collection
    {
        $project->loadMissing(['store', 'teamMembers.user', 'tasks.subTasks', 'tasks.assignedUser', 'tasks.supportUser']);

        if (!$project->board_month || !$project->board_year) {
            return collect();
        }

        $targets = $this->projectTargets($project);

        if ($targets->isEmpty()) {
            return collect();
        }

        $boards = collect();

        return DB::transaction(function () use ($project, $actor, $autoCreateMonthlyBoards, $targets, $boards) {
            foreach ($targets as $target) {
                $board = $this->findOrCreateMonthlyBoard($project, $target, $actor, $autoCreateMonthlyBoards);
                $this->syncMonthlyBoardMembers($board, $project, $target, $actor);
                $card = $this->syncProjectCard($board, $project, $actor);
                $this->syncProjectChecklists($card, $project);
                $boards->push($board->fresh(['members']));
            }

            return $boards;
        });
    }

    public function monthlyTargetPreview(Project $project): array
    {
        $project->loadMissing(['teamMembers.user']);

        if (!$project->board_month || !$project->board_year) {
            return [
                'month' => $project->board_month,
                'year' => $project->board_year,
                'targets' => [],
                'missing' => [],
            ];
        }

        $periodLabel = $this->periodLabel((int) $project->board_month, (int) $project->board_year);
        $targets = $this->projectTargets($project)
            ->map(function (array $target) use ($project, $periodLabel) {
                $monthlyKey = $this->monthlyBoardKey(
                    $target['department'],
                    $target['sub_unit'],
                    (int) $project->board_month,
                    (int) $project->board_year
                );
                $board = TaskBoard::withTrashed()->where('monthly_key', $monthlyKey)->first();

                return [
                    'department' => $target['department'],
                    'sub_unit' => $target['sub_unit'],
                    'month' => (int) $project->board_month,
                    'year' => (int) $project->board_year,
                    'title' => $this->monthlyBoardTitle($target['department'], $target['sub_unit'], $periodLabel),
                    'exists' => (bool) $board,
                    'board_id' => $board?->id,
                ];
            })
            ->values();

        return [
            'month' => (int) $project->board_month,
            'year' => (int) $project->board_year,
            'targets' => $targets->all(),
            'missing' => $targets->where('exists', false)->values()->all(),
        ];
    }

    /**
     * Import task cards from a manual board into a project as ProjectTask records.
     * Skips import if the project already has tasks.
     * Returns true if import ran, false if skipped.
     */
    public function importBoardCardsAsProjectTasks(TaskBoard $board, Project $project): bool
    {
        if ($project->tasks()->exists()) {
            return false;
        }

        $cards = $board->cards()
            ->with(['checklists.items.children'])
            ->reorder()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Canonical mapping: each card's checklists become milestones, items become
        // activities, and subtasks become project subtasks (linked for two-way sync).
        foreach ($cards as $card) {
            $card->setRelation('board', $board);
            if ($card->checklists->isNotEmpty()) {
                $this->syncProjectStructureFromCard($card, null);
            }
        }

        return true;
    }

    /**
     * Re-sync a manual board that is linked to a project. The card itself is never a
     * project activity — only its checklists (milestones), items (activities), and
     * subtasks (subtasks) map onto the project. This also cleans up any stray
     * card-level project tasks created by the old card=activity behavior.
     */
    public function syncManualLinkedBoard(TaskBoard $board): void
    {
        $project = $board->project;
        if (!$project) {
            return;
        }

        DB::transaction(function () use ($board, $project) {
            // Cards must not be project activities. Capture & detach any that were linked
            // to a project task directly (legacy card=activity), then drop the orphans.
            $cardLevelTaskIds = $board->cards()
                ->whereNotNull('project_task_id')
                ->pluck('project_task_id')
                ->map(fn ($id) => (int) $id)
                ->unique();

            if ($cardLevelTaskIds->isNotEmpty()) {
                $board->cards()->whereNotNull('project_task_id')->update(['project_task_id' => null]);
            }

            // Mirror every card's checklist structure onto the project (create/rename/link).
            $cards = $board->cards()
                ->whereNull('archived_at')
                ->with(['checklists.items.children'])
                ->reorder()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($cards as $card) {
                $card->setRelation('board', $board);
                if ($card->checklists->isNotEmpty()) {
                    $this->syncProjectStructureFromCard($card, null);
                }
            }

            // Delete the captured card-level tasks that are not now linked to a checklist
            // item (i.e. genuine strays). Tasks created directly on the Gantt are left alone
            // because they were never referenced by a card's project_task_id.
            if ($cardLevelTaskIds->isNotEmpty()) {
                $stillLinked = TaskChecklistItem::whereIn('project_task_id', $cardLevelTaskIds->all())
                    ->pluck('project_task_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique();

                $strays = $cardLevelTaskIds->diff($stillLinked);

                if ($strays->isNotEmpty()) {
                    ProjectTask::whereIn('id', $strays->all())->whereNotNull('parent_task_id')->delete();
                    ProjectTask::whereIn('id', $strays->all())->delete();
                }
            }

            $project->fresh()?->recalculateStatus();
        });
    }

    public function mapColumnNameToTaskStatus(string $columnName): string
    {
        $name = strtolower($columnName);
        if (str_contains($name, 'done') || str_contains($name, 'complet') || str_contains($name, 'finish')) {
            return 'Done';
        }
        if (str_contains($name, 'progress') || str_contains($name, 'ongoing') || str_contains($name, 'active')) {
            return 'Ongoing';
        }
        if (str_contains($name, 'n/a') || str_contains($name, 'n-a') || str_contains($name, 'not applicable')) {
            return 'N/A';
        }
        return 'Pending';
    }

    public function syncProjectTask(ProjectTask $task, ?User $actor = null, ?TaskBoard $board = null): ?TaskBoard
    {
        $task->loadMissing(['project.teamMembers.user']);

        if (!$task->project) {
            return null;
        }

        return $this->syncProject($task->project, $actor, $board);
    }

    public function syncProjectTaskIds(iterable $taskIds, ?User $actor = null, bool $autoCreateMonthlyBoards = true): void
    {
        ProjectTask::query()
            ->whereIn('id', collect($taskIds)->filter()->unique()->values())
            ->with('project')
            ->get()
            ->pluck('project')
            ->filter()
            ->unique('id')
            ->each(fn (Project $project) => $this->syncProject($project, $actor, null, $autoCreateMonthlyBoards));
    }

    public function archiveProjectTaskCards(iterable $taskIds, ?User $actor = null): void
    {
        $ids = collect($taskIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        TaskChecklistItem::query()
            ->whereIn('parent_item_id', TaskChecklistItem::whereIn('project_task_id', $ids)->pluck('id'))
            ->delete();

        TaskChecklistItem::whereIn('project_task_id', $ids)->delete();

        TaskCard::query()
            ->whereIn('project_task_id', $ids)
            ->whereNull('archived_at')
            ->with('board')
            ->get()
            ->each(function (TaskCard $card) use ($actor) {
                $card->update(['archived_at' => now()]);
                $this->recordActivity($card->board, $card, $actor?->id, 'project.card.archived', 'archived after project activity deletion');
            });
    }

    public function createProjectCard(TaskBoard $board, array $data, User $actor): TaskCard
    {
        if (!$board->project_id) {
            throw ValidationException::withMessages([
                'task_board_id' => 'This board is not linked to a project.',
            ]);
        }

        return DB::transaction(function () use ($board, $data, $actor) {
            $project = $board->project()->with(['teamMembers.user'])->firstOrFail();
            $parentTask = null;

            if (!empty($data['parent_project_task_id'])) {
                $parentTask = ProjectTask::query()
                    ->where('project_id', $project->id)
                    ->whereNull('parent_task_id')
                    ->findOrFail($data['parent_project_task_id']);
            }

            $statusName = $data['status'] ?? ($board->columnNameForRole('backlog') ?? 'Backlogs');
            $column = $board->columnForName($statusName);
            $role = $column?->role ?? 'backlog';
            $taskState = $this->projectTaskStateForRole($role, null) ?: ['status' => 'Pending', 'progress' => 0];
            $category = $parentTask?->category ?: ($data['category'] ?? 'General');
            $assigneeIds = collect($data['assignee_ids'] ?? [])->filter()->values();

            $task = ProjectTask::create([
                'project_id' => $project->id,
                'parent_task_id' => $parentTask?->id,
                'name' => $data['title'],
                'category' => $category ?: 'General',
                'assigned_to' => $assigneeIds->first(),
                'status' => $taskState['status'],
                'progress' => $taskState['progress'],
                'start_date' => $this->dateFromDateTime($data['start_at'] ?? null),
                'end_date' => $this->dateFromDateTime($data['due_at'] ?? null),
                'order' => $this->nextProjectTaskOrder($project, $category ?: 'General', $parentTask?->id),
            ]);

            $card = TaskCard::create([
                'task_board_id' => $board->id,
                'task_board_column_id' => $column?->id,
                'project_task_id' => $task->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $statusName,
                'sort_order' => $this->nextCardSortOrder($board, $statusName),
                'start_at' => $data['start_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'cover_type' => $data['cover_type'] ?? null,
                'cover_value' => $data['cover_value'] ?? null,
                'due_complete' => $role === 'done',
                'created_by' => $actor->id,
            ]);

            $this->syncLegacyBoardMembers($project, $board, $actor);
            $this->syncLegacyCardAssignees($card, $task);
            $card->watchers()->syncWithoutDetaching([$actor->id]);
            $this->recordActivity($board, $card, $actor->id, 'project.card.created', 'created this project activity card');

            return $card->fresh($this->cardRelations());
        });
    }

    public function syncProjectTaskFromCard(TaskCard $card, array $data, User $actor): ?TaskCard
    {
        $task = $card->projectTask;

        if (!$task) {
            return $card;
        }

        DB::transaction(function () use ($card, $task, $data) {
            $updates = [];

            if (array_key_exists('title', $data)) {
                $updates['name'] = $data['title'];
            }

            if (array_key_exists('project_category', $data)) {
                $updates['category'] = $data['project_category'] ?: 'General';
            }

            if (array_key_exists('start_at', $data)) {
                $updates['start_date'] = $this->dateFromDateTime($data['start_at'] ?? null);
            }

            if (array_key_exists('due_at', $data)) {
                $updates['end_date'] = $this->dateFromDateTime($data['due_at'] ?? null);
            }

            if (array_key_exists('status', $data)) {
                $role = $card->board?->roleForColumnName($data['status']) ?? 'in_progress';
                $updates = [
                    ...$updates,
                    ...$this->projectTaskStateForRole($role, $task->progress),
                ];
            }

            if (array_key_exists('project_progress', $data)) {
                $progress = (int) $data['project_progress'];
                $updates['progress'] = $progress;
                $updates['status'] = $this->projectTaskStatusForProgress($progress);
            }

            if (array_key_exists('project_assigned_to', $data)) {
                $updates['assigned_to'] = $data['project_assigned_to'] ?: null;
                $updates['external_assignment'] = null;
            }

            if (array_key_exists('project_support_by', $data)) {
                $updates['support_by'] = $data['project_support_by'] ?: null;
            }

            if ($updates) {
                $task->update($updates);
            }
        });

        if ($card->board?->project_id) {
            return $this->syncLegacyProjectTask($task->fresh(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask']), $actor, $card->board);
        }

        return $this->syncProject($task->project, $actor);
    }

    public function syncProjectTaskFromChecklistItem(TaskChecklistItem $item, User $actor): void
    {
        $task = $item->projectTask;

        if (!$task) {
            return;
        }

        $updates = [
            'name' => $item->title,
            'assigned_to' => $item->assigned_to ?: null,
            'external_assignment' => null,
            'end_date' => $item->due_at?->toDateString(),
        ];

        if ($item->is_complete) {
            $updates['status'] = 'Done';
            $updates['progress'] = 100;
        } elseif ((int) $task->progress >= 100) {
            $updates['status'] = 'Pending';
            $updates['progress'] = 0;
        }

        $task->update($updates);
        $this->syncProject($task->project, $actor);
    }

    /**
     * Board → Project structure sync (canonical mapping):
     *   Checklist  -> Milestone (project task `category`)
     *   Item       -> Activity  (top-level project task)
     *   Subtask    -> Subtask   (project task with parent_task_id)
     *
     * Creates/renames/links project tasks to mirror the card's checklist structure, so
     * editing the board shows up on /projects (Gantt). Linked by checklist_item.project_task_id.
     */
    public function syncProjectStructureFromCard(TaskCard $card, ?User $actor = null): void
    {
        $project = $card->board?->project ?: $card->project;
        if (!$project) {
            return;
        }

        $card->loadMissing(['checklists.items.children']);

        DB::transaction(function () use ($card, $project) {
            $milestoneOrder = 0;

            foreach ($card->checklists as $checklist) {
                $milestoneOrder++;
                $milestone = trim((string) $checklist->title) ?: 'General';
                $itemOrder = 0;

                foreach ($checklist->items as $item) {
                    $itemOrder++;
                    $activity = $this->upsertProjectTaskFromChecklistItem($project, $item, null, $milestone, $milestoneOrder, $itemOrder);

                    if ((int) $item->project_task_id !== (int) $activity->id) {
                        $item->forceFill(['project_task_id' => $activity->id])->saveQuietly();
                    }

                    $subOrder = 0;
                    foreach (($item->children ?? collect()) as $sub) {
                        $subOrder++;
                        $subtask = $this->upsertProjectTaskFromChecklistItem($project, $sub, $activity->id, $milestone, $milestoneOrder, $subOrder);

                        if ((int) $sub->project_task_id !== (int) $subtask->id) {
                            $sub->forceFill(['project_task_id' => $subtask->id])->saveQuietly();
                        }
                    }
                }
            }

            $project->fresh()?->recalculateStatus();
        });
    }

    private function upsertProjectTaskFromChecklistItem(
        Project $project,
        TaskChecklistItem $item,
        ?int $parentTaskId,
        string $milestone,
        int $milestoneOrder,
        int $order
    ): ProjectTask {
        $task = $item->project_task_id
            ? ProjectTask::where('project_id', $project->id)->find($item->project_task_id)
            : null;

        if (!$task) {
            $task = new ProjectTask(['project_id' => $project->id]);
        }

        // The board item's % (weight) is the activity/subtask progress on the Gantt.
        $progress = (int) round(max(0, min(100, (float) ($item->weight ?? 0))));
        $status = $progress >= 100 ? 'Done' : ($progress > 0 ? 'Ongoing' : 'Pending');

        $task->forceFill([
            'parent_task_id' => $parentTaskId,
            'name' => $item->title,
            'category' => $milestone,
            'milestone_order' => $milestoneOrder,
            'order' => $order,
            'status' => $status,
            'progress' => $progress,
            'assigned_to' => $item->assigned_to ?: null,
            'external_assignment' => null,
            'end_date' => $item->due_at?->toDateString(),
        ])->save();

        return $task;
    }

    /**
     * Project → Board (two-way): mirror the project's Gantt structure onto every manual
     * board linked to the project. Milestones become checklists, activities become items,
     * and subtasks become child items — creating any that are missing and updating the
     * rest. This is the inverse of syncProjectStructureFromCard, so adding a milestone /
     * activity / subtask on /projects shows up on the task board, and vice versa.
     */
    public function syncLinkedBoardItemsFromProject(Project $project): void
    {
        $boards = TaskBoard::where('project_id', $project->id)
            ->where(function ($query) {
                $query->where('board_source', 'manual')->orWhereNull('board_source');
            })
            ->get();

        if ($boards->isEmpty()) {
            return;
        }

        $tasks = ProjectTask::where('project_id', $project->id)
            ->orderBy('parent_task_id')
            ->orderBy('milestone_order')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $topLevel = $tasks->whereNull('parent_task_id')
            ->sortBy([['milestone_order', 'asc'], ['order', 'asc'], ['id', 'asc']])
            ->values();
        $childrenByParent = $tasks->whereNotNull('parent_task_id')
            ->sortBy([['order', 'asc'], ['id', 'asc']])
            ->groupBy('parent_task_id');

        foreach ($boards as $board) {
            $this->mirrorProjectStructureOntoBoard($board, $topLevel, $childrenByParent);
        }
    }

    private function mirrorProjectStructureOntoBoard(TaskBoard $board, Collection $topLevel, Collection $childrenByParent): void
    {
        $primaryCard = $this->resolveStructureCard($board);
        if (!$primaryCard) {
            return;
        }

        DB::transaction(function () use ($board, $topLevel, $childrenByParent, $primaryCard) {
            // Existing board items already linked to project tasks, keyed by project_task_id.
            $linkedItems = TaskChecklistItem::query()
                ->whereNotNull('project_task_id')
                ->whereHas('checklist.card', fn ($query) => $query->where('task_board_id', $board->id))
                ->get()
                ->keyBy('project_task_id');

            foreach ($topLevel as $task) {
                $milestone = $task->category ?: 'General';
                $item = $linkedItems->get($task->id);

                if ($item) {
                    // A renamed milestone renames its checklist (each checklist == one milestone).
                    if ($item->checklist && $item->checklist->title !== $milestone) {
                        $item->checklist->forceFill(['title' => $milestone])->saveQuietly();
                    }
                    $checklistId = $item->task_checklist_id;
                } else {
                    $checklist = $this->findOrCreateChecklistByTitle($board, $primaryCard, $milestone);
                    $item = new TaskChecklistItem(['project_task_id' => $task->id]);
                    $checklistId = $checklist->id;
                }

                $this->fillBoardItemFromTask($item, $task, $checklistId, null);
                $item->saveQuietly();

                foreach (($childrenByParent->get($task->id) ?? collect()) as $sub) {
                    $childItem = $linkedItems->get($sub->id) ?: new TaskChecklistItem(['project_task_id' => $sub->id]);
                    $this->fillBoardItemFromTask($childItem, $sub, $item->task_checklist_id, $item->id);
                    $childItem->saveQuietly();
                }
            }
        });
    }

    private function fillBoardItemFromTask(TaskChecklistItem $item, ProjectTask $task, int $checklistId, ?int $parentItemId): void
    {
        $item->forceFill([
            'task_checklist_id' => $checklistId,
            'parent_item_id' => $parentItemId,
            'project_task_id' => $task->id,
            'title' => $task->name,
            'weight' => (int) round(max(0, min(100, (float) $task->progress))),
            'is_complete' => $task->status === 'Done' || (int) $task->progress >= 100,
            'assigned_to' => $task->assigned_to ?: null,
            'due_at' => $task->end_date ? Carbon::parse($task->end_date)->endOfDay()->format('Y-m-d H:i:s') : null,
            'sort_order' => (int) ($task->order ?? 0),
        ]);
    }

    /**
     * Find a checklist with the given title anywhere on the board, otherwise create it on
     * the structure card so a brand-new milestone gets a home.
     */
    private function findOrCreateChecklistByTitle(TaskBoard $board, TaskCard $primaryCard, string $title): TaskChecklist
    {
        $existing = TaskChecklist::query()
            ->whereHas('card', fn ($query) => $query->where('task_board_id', $board->id))
            ->where('title', $title)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $primaryCard->checklists()->create([
            'title' => $title,
            'sort_order' => ((int) $primaryCard->checklists()->max('sort_order')) + 1,
        ]);
    }

    /**
     * The card that hosts project structure on a manual board: the one already holding the
     * most project-linked items, else the first card, else a freshly created card.
     */
    private function resolveStructureCard(TaskBoard $board): ?TaskCard
    {
        $cards = $board->cards()
            ->whereNull('archived_at')
            ->with('checklists.items')
            ->reorder()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            return $this->createStructureCard($board);
        }

        return $cards
            ->sortByDesc(fn (TaskCard $card) => $card->checklists
                ->flatMap->items
                ->whereNotNull('project_task_id')
                ->count())
            ->first();
    }

    private function createStructureCard(TaskBoard $board): TaskCard
    {
        $column = $board->columnForRole('backlog') ?? $board->columns()->first();
        $statusName = $column?->name ?? 'Backlogs';

        return TaskCard::create([
            'task_board_id' => $board->id,
            'task_board_column_id' => $column?->id,
            'title' => $board->project?->name ?: 'Project Tasks',
            'status' => $statusName,
            'sort_order' => $this->nextCardSortOrder($board, $statusName),
            'created_by' => $board->created_by,
        ]);
    }

    /**
     * Project → Board: drop board checklist items linked to project tasks that were deleted.
     */
    public function removeBoardItemsForProjectTasks(iterable $taskIds): void
    {
        $ids = collect($taskIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        // Subtask items first, then parents, to avoid orphaning child rows mid-delete.
        TaskChecklistItem::whereIn('project_task_id', $ids->all())->whereNotNull('parent_item_id')->delete();
        TaskChecklistItem::whereIn('project_task_id', $ids->all())->delete();
    }

    /**
     * Remove the project task(s) mirrored from a checklist item (and its subtask children).
     */
    public function deleteProjectTasksForChecklistItem(TaskChecklistItem $item): void
    {
        $taskIds = collect();

        // Children first (board subtasks → project subtasks).
        foreach (($item->children ?? collect()) as $child) {
            if ($child->project_task_id) {
                $taskIds->push((int) $child->project_task_id);
            }
        }
        if ($item->project_task_id) {
            $taskIds->push((int) $item->project_task_id);
        }

        if ($taskIds->isEmpty()) {
            return;
        }

        $project = ProjectTask::whereIn('id', $taskIds->all())->first()?->project;

        // Delete subtasks before parents to avoid parent_task_id constraint issues.
        ProjectTask::whereIn('id', $taskIds->all())->whereNotNull('parent_task_id')->delete();
        ProjectTask::whereIn('id', $taskIds->all())->whereNull('parent_task_id')->delete();

        $project?->fresh()?->recalculateStatus();
    }

    public function cardRelations(): array
    {
        return [
            'column:id,name,role',
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
            'projectTask.assignedUser:id,name,email,profile_photo,org_path',
            'projectTask.supportUser:id,name,email,profile_photo,org_path',
            'projectTask.parentTask:id,name,category',
        ];
    }

    private function projectTargets(Project $project): Collection
    {
        return $project->teamMembers
            ->map(function ($member) {
                $department = $this->cleanOrgValue($member->department ?: $member->user?->department);
                $subUnit = $this->cleanOrgValue($member->sub_unit ?: $member->user?->org_path);

                if ($department === '' || $subUnit === '') {
                    return null;
                }

                return [
                    'department' => $department,
                    'sub_unit' => $subUnit,
                    'key' => $this->normalizeOrgKey($department) . '|' . $this->normalizeOrgKey($subUnit),
                ];
            })
            ->filter()
            ->unique('key')
            ->sortBy(fn (array $target) => $target['department'] . '|' . $target['sub_unit'])
            ->values();
    }

    private function findOrCreateMonthlyBoard(Project $project, array $target, ?User $actor, bool $autoCreateMonthlyBoards): TaskBoard
    {
        $month = (int) $project->board_month;
        $year = (int) $project->board_year;
        $monthlyKey = $this->monthlyBoardKey($target['department'], $target['sub_unit'], $month, $year);

        $board = TaskBoard::withTrashed()->where('monthly_key', $monthlyKey)->first();

        if ($board) {
            if ($board->trashed()) {
                $board->restore();
            }

            return $board;
        }

        if (!$autoCreateMonthlyBoards || !$actor) {
            throw ValidationException::withMessages([
                'task_board' => 'The matching monthly board does not exist yet. Confirm auto-create monthly boards and try again.',
            ]);
        }

        $periodLabel = $this->periodLabel($month, $year);
        $board = TaskBoard::create([
            'board_source' => 'monthly',
            'department' => $target['department'],
            'sub_unit' => $target['sub_unit'],
            'board_month' => $month,
            'board_year' => $year,
            'monthly_key' => $monthlyKey,
            'title' => $this->monthlyBoardTitle($target['department'], $target['sub_unit'], $periodLabel),
            'description' => "Monthly task board for {$target['department']} / {$target['sub_unit']} - {$periodLabel}.",
            'background_type' => 'color',
            'background_value' => '#0f766e',
            'created_by' => $actor->id,
        ]);

        foreach ($this->defaultLabels() as $index => $label) {
            $board->labels()->create([
                'name' => $label['name'],
                'color' => $label['color'],
                'sort_order' => $index,
            ]);
        }

        $board->seedDefaultColumns();

        $this->recordActivity($board, null, $actor->id, 'board.monthly_created', 'auto-created this monthly board for project sync');

        return $board;
    }

    private function syncMonthlyBoardMembers(TaskBoard $board, Project $project, array $target, ?User $actor): void
    {
        if ($actor) {
            $this->ensureBoardMember($board, $actor->id, 'admin');
        }

        User::active()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->get(['id', 'department', 'org_path'])
            ->filter(fn (User $user) => $this->normalizeOrgKey($user->department) === $this->normalizeOrgKey($target['department'])
                && $this->normalizeOrgKey($user->org_path) === $this->normalizeOrgKey($target['sub_unit']))
            ->each(fn (User $user) => $this->ensureBoardMember($board, $user->id, 'member'));

        $this->projectUserIds($project)
            ->each(fn (int $userId) => $this->ensureBoardMember($board, $userId, 'member'));
    }

    private function syncProjectCard(TaskBoard $board, Project $project, ?User $actor): TaskCard
    {
        $role = $this->cardRoleForProject($project);
        $column = $board->columnForRole($role);
        $statusName = $column?->name ?? $this->fallbackRoleName($role);
        $card = TaskCard::firstOrNew([
            'task_board_id' => $board->id,
            'project_id' => $project->id,
        ]);

        $isNew = !$card->exists;
        $card->fill([
            'project_task_id' => null,
            'task_board_column_id' => $column?->id,
            'title' => $project->name,
            'description' => $project->remarks,
            'status' => $statusName,
            'start_at' => $project->turn_over_date ? Carbon::parse($project->turn_over_date)->startOfDay()->format('Y-m-d H:i:s') : null,
            'due_at' => $project->target_go_live ? Carbon::parse($project->target_go_live)->endOfDay()->format('Y-m-d H:i:s') : null,
            'due_complete' => $role === 'done',
            'created_by' => $card->created_by ?: ($actor?->id ?? $board->created_by),
        ]);

        if ($isNew) {
            $card->sort_order = $this->nextCardSortOrder($board, $statusName);
        } elseif ($card->isDirty('status')) {
            $card->sort_order = $this->nextCardSortOrder($board, $statusName);
        }

        $card->save();
        $this->syncProjectCardAssignees($card, $project);

        if ($isNew) {
            $this->recordActivity($board, $card, $actor?->id, 'project.card.created', 'created this project card');
        }

        return $card;
    }

    private function syncProjectChecklists(TaskCard $card, Project $project): void
    {
        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with(['assignedUser', 'subTasks.assignedUser'])
            ->orderBy('parent_task_id')
            ->orderBy('milestone_order')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $topLevelTasks = $tasks->whereNull('parent_task_id')->values();
        $validTaskIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();

        $milestoneGroups = $topLevelTasks
            ->groupBy(fn (ProjectTask $task) => $task->category ?: 'General')
            ->sortBy(fn (Collection $tasks) => $tasks->min('milestone_order') ?? PHP_INT_MAX)
            ->values();

        foreach ($milestoneGroups as $index => $milestoneTasks) {
            $milestone = (string) ($milestoneTasks->first()->category ?: 'General');
            $checklist = $this->syncChecklist($card, $milestone, (int) $index + 1);

            foreach ($milestoneTasks->sortBy([['order', 'asc'], ['id', 'asc']])->values() as $task) {
                $activityItem = $this->syncChecklistItem($card, $checklist, $task, null);

                $task->subTasks
                    ->sortBy([['order', 'asc'], ['id', 'asc']])
                    ->values()
                    ->each(fn (ProjectTask $subTask) => $this->syncChecklistItem($card, $checklist, $subTask, $activityItem));
            }
        }

        $this->deleteStaleProjectChecklistItems($card, $validTaskIds);
        $this->deleteEmptyProjectChecklists($card);
    }

    private function syncChecklist(TaskCard $card, string $title, int $sortOrder): TaskChecklist
    {
        $checklist = $card->checklists()->where('title', $title)->first()
            ?: $card->checklists()->create([
                'title' => $title,
                'sort_order' => $sortOrder,
            ]);

        $checklist->update([
            'title' => $title,
            'sort_order' => $sortOrder,
        ]);

        return $checklist;
    }

    private function syncChecklistItem(TaskCard $card, TaskChecklist $checklist, ProjectTask $task, ?TaskChecklistItem $parent): TaskChecklistItem
    {
        $item = TaskChecklistItem::where('project_task_id', $task->id)
            ->whereHas('checklist', fn ($query) => $query->where('task_card_id', $card->id))
            ->first() ?: new TaskChecklistItem(['project_task_id' => $task->id]);

        $item->fill([
            'task_checklist_id' => $checklist->id,
            'parent_item_id' => $parent?->id,
            'title' => $task->name,
            'is_complete' => $task->status === 'Done' || (int) $task->progress >= 100,
            'assigned_to' => $task->assigned_to,
            'due_at' => $task->end_date ? Carbon::parse($task->end_date)->endOfDay()->format('Y-m-d H:i:s') : null,
            'sort_order' => (int) ($task->order ?? 0),
        ]);
        $item->save();

        return $item;
    }

    private function deleteStaleProjectChecklistItems(TaskCard $card, array $validTaskIds): void
    {
        $checklistIds = $card->checklists()->pluck('id');

        if ($checklistIds->isEmpty()) {
            return;
        }

        $staleIds = TaskChecklistItem::query()
            ->whereIn('task_checklist_id', $checklistIds)
            ->whereNotNull('project_task_id')
            ->when($validTaskIds, fn ($query) => $query->whereNotIn('project_task_id', $validTaskIds))
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        TaskChecklistItem::whereIn('parent_item_id', $staleIds)->delete();
        TaskChecklistItem::whereIn('id', $staleIds)->delete();
    }

    private function deleteEmptyProjectChecklists(TaskCard $card): void
    {
        $card->checklists()->get()->each(function (TaskChecklist $checklist) {
            if (!$checklist->allItems()->exists()) {
                $checklist->delete();
            }
        });
    }

    private function syncProjectCardAssignees(TaskCard $card, Project $project): void
    {
        $userIds = $this->projectUserIds($project)->all();

        foreach ($userIds as $userId) {
            $this->ensureBoardMember($card->board, (int) $userId, 'member');
        }

        $card->assignees()->sync($userIds);
        $card->watchers()->syncWithoutDetaching($userIds);
    }

    private function projectUserIds(Project $project): Collection
    {
        $teamUserIds = $project->teamMembers->pluck('user_id')->filter();

        $taskUserIds = ProjectTask::query()
            ->where('project_id', $project->id)
            ->get(['assigned_to', 'support_by'])
            ->flatMap(fn (ProjectTask $task) => [$task->assigned_to, $task->support_by])
            ->filter();

        return $teamUserIds
            ->merge($taskUserIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function syncLegacyProjectBoard(Project $project, ?User $actor, TaskBoard $board): TaskBoard
    {
        return DB::transaction(function () use ($project, $actor, $board) {
            $project->loadMissing(['store', 'teamMembers.user']);
            $this->syncLegacyBoardMembers($project, $board, $actor);

            ProjectTask::query()
                ->where('project_id', $project->id)
                ->with(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask'])
                ->orderBy('parent_task_id')
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->each(fn (ProjectTask $task) => $this->syncLegacyProjectTask($task, $actor, $board));

            return $board->fresh(['project.store']);
        });
    }

    private function syncLegacyProjectTask(ProjectTask $task, ?User $actor, TaskBoard $board): TaskCard
    {
        $card = TaskCard::where('project_task_id', $task->id)->with('column')->first();
        $role = $this->cardRoleForProjectTask($task, $card);
        $column = $board->columnForRole($role);
        $statusName = $column?->name ?? $this->fallbackRoleName($role);
        $dateFields = $this->cardDateFields($task);

        if (!$card) {
            $card = TaskCard::create([
                'task_board_id' => $board->id,
                'task_board_column_id' => $column?->id,
                'project_task_id' => $task->id,
                'title' => $task->name,
                'description' => null,
                'status' => $statusName,
                'sort_order' => $this->nextCardSortOrder($board, $statusName),
                'start_at' => $dateFields['start_at'],
                'due_at' => $dateFields['due_at'],
                'due_complete' => $role === 'done',
                'created_by' => $actor?->id ?? $board->created_by,
            ]);

            $this->recordActivity($board, $card, $actor?->id, 'project.card.created', 'created from project activity');
        } else {
            $card->update([
                'task_board_column_id' => $column?->id,
                'title' => $task->name,
                'status' => $statusName,
                'start_at' => $dateFields['start_at'],
                'due_at' => $dateFields['due_at'],
                'due_complete' => $role === 'done',
            ]);
        }

        $this->syncLegacyCardAssignees($card, $task);

        return $card->fresh($this->cardRelations());
    }

    private function syncLegacyBoardMembers(Project $project, TaskBoard $board, ?User $actor = null): void
    {
        if ($actor) {
            $this->ensureBoardMember($board, $actor->id, 'admin');
        }

        $this->projectUserIds($project)
            ->each(fn (int $userId) => $this->ensureBoardMember($board, $userId, 'member'));
    }

    private function syncLegacyCardAssignees(TaskCard $card, ProjectTask $task): void
    {
        $userIds = collect([$task->assigned_to, $task->support_by])->filter()->unique()->values()->all();

        foreach ($userIds as $userId) {
            $this->ensureBoardMember($card->board, (int) $userId, 'member');
        }

        $card->assignees()->sync($userIds);
        $card->watchers()->syncWithoutDetaching($userIds);
    }

    private function ensureBoardMember(TaskBoard $board, int $userId, string $role): void
    {
        $record = $board->memberRecords()->firstOrNew(['user_id' => $userId]);
        $record->role = $record->exists && $record->role === 'admin' ? 'admin' : $role;
        $record->save();
    }

    private function cardDateFields(ProjectTask $task): array
    {
        return [
            'start_at' => $task->start_date ? Carbon::parse($task->start_date)->startOfDay()->format('Y-m-d H:i:s') : null,
            'due_at' => $task->end_date ? Carbon::parse($task->end_date)->endOfDay()->format('Y-m-d H:i:s') : null,
        ];
    }

    private function dateFromDateTime(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->toDateString();
    }

    private function cardRoleForProject(Project $project): string
    {
        return match (strtolower((string) $project->status)) {
            'completed', 'done' => 'done',
            'in progress', 'ongoing', 'delayed' => 'in_progress',
            default => 'backlog',
        };
    }

    private function cardRoleForProjectTask(ProjectTask $task, ?TaskCard $card = null): string
    {
        if ($task->status === 'Done' || (int) $task->progress >= 100) {
            return 'done';
        }

        if ($card?->column_role === 'for_verification' && (int) $task->progress >= 90) {
            return 'for_verification';
        }

        if ($task->status === 'Pending' || (int) $task->progress <= 0) {
            return 'backlog';
        }

        return 'in_progress';
    }

    /**
     * Map a column role onto the underlying project-task status/progress. Returns an
     * empty array for custom columns (role null/unknown) so the task is left unchanged.
     */
    private function projectTaskStateForRole(string $role, ?int $currentProgress): array
    {
        $progress = (int) ($currentProgress ?? 0);

        return match ($role) {
            'done' => ['status' => 'Done', 'progress' => 100],
            'for_verification' => ['status' => 'Ongoing', 'progress' => min(max($progress, 90), 99)],
            'in_progress' => ['status' => 'Ongoing', 'progress' => min(max($progress, 1), 99)],
            'backlog' => ['status' => 'Pending', 'progress' => 0],
            default => [],
        };
    }

    private function fallbackRoleName(string $role): string
    {
        return match ($role) {
            'done' => 'Done',
            'for_verification' => 'For Verification',
            'in_progress' => 'In Progress',
            default => 'Backlogs',
        };
    }

    private function projectTaskStatusForProgress(int $progress): string
    {
        return $progress >= 100 ? 'Done' : ($progress > 0 ? 'Ongoing' : 'Pending');
    }

    private function nextCardSortOrder(TaskBoard $board, string $status): int
    {
        return ((int) $board->cards()
            ->reorder()
            ->where('status', $status)
            ->whereNull('archived_at')
            ->max('sort_order')) + 1000;
    }

    private function nextProjectTaskOrder(Project $project, string $category, ?int $parentTaskId): int
    {
        $query = ProjectTask::query()
            ->where('project_id', $project->id)
            ->where('parent_task_id', $parentTaskId);

        if (!$parentTaskId) {
            $query->where('category', $category);
        }

        return ((int) $query->max('order')) + 1;
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

    private function periodLabel(int $month, int $year): string
    {
        return CarbonImmutable::create($year, $month, 1)->format('F Y');
    }

    private function cleanOrgValue(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function normalizeOrgKey(?string $value): string
    {
        return strtolower($this->cleanOrgValue($value));
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
}
