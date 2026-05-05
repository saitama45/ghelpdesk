<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\TaskCardActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskBoardSyncService
{
    public function openBoard(Project $project, User $actor): TaskBoard
    {
        return DB::transaction(function () use ($project, $actor) {
            $board = $this->ensureProjectBoard($project, $actor);
            $this->syncProject($project, $actor, $board);

            return $board->fresh(['project.store']);
        });
    }

    public function syncProject(Project $project, ?User $actor = null, ?TaskBoard $board = null): ?TaskBoard
    {
        $board ??= $project->taskBoard()->first();

        if (!$board) {
            return null;
        }

        return DB::transaction(function () use ($project, $actor, $board) {
            $project->loadMissing(['store', 'teamMembers.user']);
            $this->syncBoardMembers($project, $board, $actor);

            ProjectTask::query()
                ->where('project_id', $project->id)
                ->with(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask'])
                ->orderBy('parent_task_id')
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->each(fn (ProjectTask $task) => $this->syncProjectTask($task, $actor, $board));

            return $board->fresh(['project.store']);
        });
    }

    public function syncProjectTask(ProjectTask $task, ?User $actor = null, ?TaskBoard $board = null): ?TaskCard
    {
        $task->loadMissing(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask']);
        $board ??= $task->project?->taskBoard;

        if (!$board) {
            return null;
        }

        return DB::transaction(function () use ($task, $actor, $board) {
            $this->syncBoardMembers($task->project, $board, $actor);

            $card = TaskCard::where('project_task_id', $task->id)->first();
            $status = $this->cardStatusForProjectTask($task, $card);
            $dateFields = $this->cardDateFields($task);

            if (!$card) {
                $card = TaskCard::create([
                    'task_board_id' => $board->id,
                    'project_task_id' => $task->id,
                    'title' => $task->name,
                    'description' => null,
                    'status' => $status,
                    'sort_order' => $this->nextCardSortOrder($board, $status),
                    'start_at' => $dateFields['start_at'],
                    'due_at' => $dateFields['due_at'],
                    'due_complete' => $status === 'Done',
                    'created_by' => $actor?->id ?? $board->created_by,
                ]);

                $this->recordActivity($board, $card, $actor?->id, 'project.card.created', 'created from project activity');
            } else {
                $updates = [
                    'title' => $task->name,
                    'status' => $status,
                    'start_at' => $dateFields['start_at'],
                    'due_at' => $dateFields['due_at'],
                    'due_complete' => $status === 'Done',
                ];

                if ($card->status !== $status) {
                    $updates['sort_order'] = $this->nextCardSortOrder($board, $status);
                }

                $card->update($updates);
            }

            $this->syncCardAssignees($card, $task);

            return $card->fresh($this->cardRelations());
        });
    }

    public function syncProjectTaskIds(iterable $taskIds, ?User $actor = null): void
    {
        ProjectTask::query()
            ->whereIn('id', collect($taskIds)->filter()->unique()->values())
            ->with(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask'])
            ->get()
            ->each(fn (ProjectTask $task) => $this->syncProjectTask($task, $actor));
    }

    public function archiveProjectTaskCards(iterable $taskIds, ?User $actor = null): void
    {
        TaskCard::query()
            ->whereIn('project_task_id', collect($taskIds)->filter()->unique()->values())
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

            $status = $data['status'] ?? 'Backlogs';
            $taskState = $this->projectTaskStateForCardStatus($status, null);
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
                'project_task_id' => $task->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $status,
                'sort_order' => $this->nextCardSortOrder($board, $status),
                'start_at' => $data['start_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'cover_type' => $data['cover_type'] ?? null,
                'cover_value' => $data['cover_value'] ?? null,
                'due_complete' => $status === 'Done',
                'created_by' => $actor->id,
            ]);

            $this->syncBoardMembers($project, $board, $actor);
            $this->syncCardAssignees($card, $task);
            $card->watchers()->syncWithoutDetaching([$actor->id]);
            $this->recordActivity($board, $card, $actor->id, 'project.card.created', 'created this project activity card');

            return $this->syncProjectTask($task->fresh(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask']), $actor, $board);
        });
    }

    public function syncProjectTaskFromCard(TaskCard $card, array $data, User $actor): ?TaskCard
    {
        $task = $card->projectTask;

        if (!$task) {
            return $card;
        }

        DB::transaction(function () use ($task, $data) {
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
                $updates = [
                    ...$updates,
                    ...$this->projectTaskStateForCardStatus($data['status'], $task->progress),
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

        return $this->syncProjectTask($task->fresh(['project.taskBoard', 'assignedUser', 'supportUser', 'parentTask']), $actor);
    }

    public function cardRelations(): array
    {
        return [
            'creator:id,name,profile_photo',
            'assignees:id,name,email,profile_photo',
            'labels',
            'watchers:id,name',
            'checklists.items.assignee:id,name,profile_photo',
            'comments.user:id,name,profile_photo',
            'attachments.user:id,name,profile_photo',
            'activities.actor:id,name,profile_photo',
            'projectTask.assignedUser:id,name,email,profile_photo',
            'projectTask.supportUser:id,name,email,profile_photo',
            'projectTask.parentTask:id,name,category',
        ];
    }

    private function ensureProjectBoard(Project $project, User $actor): TaskBoard
    {
        $project->loadMissing('store');

        $board = TaskBoard::withTrashed()->where('project_id', $project->id)->first();

        if ($board) {
            if ($board->trashed()) {
                $board->restore();
                $board->update(['closed_at' => null]);
            }

            $this->ensureBoardMember($board, $actor->id, 'admin');

            return $board;
        }

        $board = TaskBoard::create([
            'project_id' => $project->id,
            'title' => $project->name . ' Task List',
            'description' => 'Project activity board for ' . ($project->store?->name ?? $project->name) . '.',
            'background_type' => 'color',
            'background_value' => '#1d4ed8',
            'created_by' => $actor->id,
        ]);

        $this->ensureBoardMember($board, $actor->id, 'admin');

        foreach ($this->defaultLabels() as $index => $label) {
            $board->labels()->create([
                'name' => $label['name'],
                'color' => $label['color'],
                'sort_order' => $index,
            ]);
        }

        $this->recordActivity($board, null, $actor->id, 'project.board.created', 'created this project board');

        return $board;
    }

    private function syncBoardMembers(Project $project, TaskBoard $board, ?User $actor = null): void
    {
        $project->loadMissing(['teamMembers.user', 'tasks']);

        if ($actor) {
            $this->ensureBoardMember($board, $actor->id, 'admin');
        }

        $teamUserIds = $project->teamMembers
            ->pluck('user_id')
            ->filter();

        $taskUserIds = ProjectTask::query()
            ->where('project_id', $project->id)
            ->get(['assigned_to', 'support_by'])
            ->flatMap(fn (ProjectTask $task) => [$task->assigned_to, $task->support_by])
            ->filter();

        $teamUserIds
            ->merge($taskUserIds)
            ->unique()
            ->each(fn ($userId) => $this->ensureBoardMember($board, (int) $userId, 'member'));
    }

    private function ensureBoardMember(TaskBoard $board, int $userId, string $role): void
    {
        $record = $board->memberRecords()->firstOrNew(['user_id' => $userId]);
        $record->role = $record->exists && $record->role === 'admin' ? 'admin' : $role;
        $record->save();
    }

    private function syncCardAssignees(TaskCard $card, ProjectTask $task): void
    {
        $userIds = collect([$task->assigned_to, $task->support_by])->filter()->unique()->values()->all();

        foreach ($userIds as $userId) {
            $this->ensureBoardMember($card->board, (int) $userId, 'member');
        }

        $card->assignees()->sync($userIds);
        $card->watchers()->syncWithoutDetaching($userIds);
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

    private function cardStatusForProjectTask(ProjectTask $task, ?TaskCard $card = null): string
    {
        if ($task->status === 'Done' || (int) $task->progress >= 100) {
            return 'Done';
        }

        if ($card?->status === 'For Verification' && (int) $task->progress >= 90) {
            return 'For Verification';
        }

        if ($task->status === 'Pending' || (int) $task->progress <= 0) {
            return 'Backlogs';
        }

        return 'In Progress';
    }

    private function projectTaskStateForCardStatus(string $status, ?int $currentProgress): array
    {
        $progress = (int) ($currentProgress ?? 0);

        return match ($status) {
            'Done' => ['status' => 'Done', 'progress' => 100],
            'For Verification' => ['status' => 'Ongoing', 'progress' => min(max($progress, 90), 99)],
            'In Progress' => ['status' => 'Ongoing', 'progress' => min(max($progress, 1), 99)],
            default => ['status' => 'Pending', 'progress' => 0],
        };
    }

    private function projectTaskStatusForProgress(int $progress): string
    {
        if ($progress >= 100) {
            return 'Done';
        }

        return $progress > 0 ? 'Ongoing' : 'Pending';
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
