<?php

namespace App\Http\Controllers;

use App\Models\TaskBoard;
use App\Models\TaskCard;
use App\Models\TaskCardActivity;
use App\Models\TaskCardAttachment;
use App\Models\TaskCardComment;
use App\Models\TaskChecklist;
use App\Models\TaskChecklistItem;
use App\Models\TaskLabel;
use App\Models\User;
use App\Services\ProjectTaskBoardSyncService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TaskCardController extends Controller implements HasMiddleware
{
    public function __construct(private ProjectTaskBoardSyncService $projectTaskBoards)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:task_boards.view', only: ['storeComment', 'destroyComment', 'toggleWatch']),
            new Middleware('can:task_boards.edit', except: ['storeComment', 'destroyComment', 'toggleWatch']),
        ];
    }

    public function store(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardEditor($taskBoard, $request->user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['required', Rule::in(TaskCard::STATUSES)],
            'category' => 'nullable|string|max:255',
            'parent_project_task_id' => 'nullable|integer|exists:project_tasks,id',
            'assignee_ids' => 'nullable|array',
            'assignee_ids.*' => 'integer|exists:users,id',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'integer|exists:task_labels,id',
            'start_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'cover_type' => 'nullable|in:color,image',
            'cover_value' => 'nullable|string|max:1000',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['start_at', 'due_at']);

        if ($taskBoard->project_id) {
            $card = $this->projectTaskBoards->createProjectCard($taskBoard, $validated, $request->user());

            return response()->json(['card' => $this->freshCard($card)], 201);
        }

        $card = DB::transaction(function () use ($request, $taskBoard, $validated) {
            $sortOrder = ((int) $taskBoard->cards()
                ->reorder()
                ->where('status', $validated['status'])
                ->whereNull('archived_at')
                ->max('sort_order')) + 1000;

            $card = TaskCard::create([
                'task_board_id' => $taskBoard->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
                'sort_order' => $sortOrder,
                'start_at' => $validated['start_at'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'cover_type' => $validated['cover_type'] ?? null,
                'cover_value' => $validated['cover_value'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->syncAssignees($card, $validated['assignee_ids'] ?? []);
            $this->syncLabels($card, $validated['label_ids'] ?? []);
            $card->watchers()->syncWithoutDetaching([$request->user()->id]);
            $this->recordActivity($taskBoard, $card, $request->user()->id, 'card.created', 'created this card');

            return $card;
        });

        return response()->json(['card' => $this->freshCard($card)], 201);
    }

    public function update(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['sometimes', 'required', Rule::in(TaskCard::STATUSES)],
            'assignee_ids' => 'nullable|array',
            'assignee_ids.*' => 'integer|exists:users,id',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'integer|exists:task_labels,id',
            'start_at' => 'nullable|date',
            'due_at' => 'nullable|date',
            'due_reminder_minutes' => 'nullable|integer|min:0|max:43200',
            'due_complete' => 'nullable|boolean',
            'cover_type' => 'nullable|in:color,image',
            'cover_value' => 'nullable|string|max:1000',
            'project_category' => 'nullable|string|max:255',
            'project_progress' => 'nullable|integer|min:0|max:100',
            'project_assigned_to' => 'nullable|integer|exists:users,id',
            'project_support_by' => 'nullable|integer|exists:users,id',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['start_at', 'due_at']);

        $syncedCard = null;

        DB::transaction(function () use ($request, $taskCard, $validated, &$syncedCard) {
            $updates = [];
            foreach ([
                'title',
                'description',
                'status',
                'start_at',
                'due_at',
                'due_reminder_minutes',
                'due_complete',
                'cover_type',
                'cover_value',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if ($updates) {
                $taskCard->update($updates);
            }

            if (array_key_exists('assignee_ids', $validated)) {
                $this->syncAssignees($taskCard, $validated['assignee_ids'] ?? []);
            }

            if (array_key_exists('label_ids', $validated)) {
                $this->syncLabels($taskCard, $validated['label_ids'] ?? []);
            }

            $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.updated', 'updated this card');

            if ($taskCard->project_task_id) {
                $syncedCard = $this->projectTaskBoards->syncProjectTaskFromCard($taskCard->fresh('projectTask'), $validated, $request->user());
            }
        });

        return response()->json(['card' => $this->freshCard($syncedCard ?: $taskCard)]);
    }

    public function move(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'status' => ['required', Rule::in(TaskCard::STATUSES)],
            'ordered_card_ids' => 'required|array',
            'ordered_card_ids.*' => 'integer|exists:task_cards,id',
        ]);

        DB::transaction(function () use ($request, $taskCard, $validated) {
            $oldStatus = $taskCard->status;
            $ids = collect($validated['ordered_card_ids'])->unique()->values();

            TaskCard::whereIn('id', $ids)
                ->where('task_board_id', $taskCard->task_board_id)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->get();

            foreach ($ids as $index => $id) {
                TaskCard::where('id', $id)
                    ->where('task_board_id', $taskCard->task_board_id)
                    ->update([
                        'status' => $validated['status'],
                        'sort_order' => ($index + 1) * 1000,
                    ]);
            }

            if ($oldStatus !== $validated['status']) {
                $this->recordActivity(
                    $taskCard->board,
                    $taskCard,
                    $request->user()->id,
                    'card.moved',
                    "moved this card from {$oldStatus} to {$validated['status']}",
                    ['from' => $oldStatus, 'to' => $validated['status']]
                );
            } else {
                $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.reordered', 'reordered this card');
            }
        });

        if ($taskCard->project_task_id) {
            $this->projectTaskBoards->syncProjectTaskFromCard($taskCard->fresh('projectTask'), [
                'status' => $validated['status'],
            ], $request->user());
        }

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function archive(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $taskCard->update(['archived_at' => now()]);
        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.archived', 'archived this card');

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function restore(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $taskCard->update(['archived_at' => null]);
        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.restored', 'restored this card');

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function destroy(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardAdmin($taskCard->board, $request->user());

        if (!$taskCard->archived_at) {
            abort(422, 'Archive the card before deleting it.');
        }

        $taskCard->delete();

        return response()->json(['deleted' => true, 'id' => $taskCard->id]);
    }

    public function storeLabel(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardEditor($taskBoard, $request->user());

        $validated = $request->validate([
            'name' => 'nullable|string|max:80',
            'color' => 'required|string|max:40',
        ]);

        $label = $taskBoard->labels()->create([
            'name' => $validated['name'] ?? null,
            'color' => $validated['color'],
            'sort_order' => ((int) $taskBoard->labels()->max('sort_order')) + 1,
        ]);

        $this->recordActivity($taskBoard, null, $request->user()->id, 'label.created', 'created a label');

        return response()->json(['label' => $label, 'labels' => $taskBoard->labels()->get()], 201);
    }

    public function updateLabel(Request $request, TaskLabel $taskLabel)
    {
        $this->ensureBoardEditor($taskLabel->board, $request->user());

        $validated = $request->validate([
            'name' => 'nullable|string|max:80',
            'color' => 'required|string|max:40',
        ]);

        $taskLabel->update($validated);

        return response()->json(['label' => $taskLabel->fresh(), 'labels' => $taskLabel->board->labels()->get()]);
    }

    public function destroyLabel(Request $request, TaskLabel $taskLabel)
    {
        $this->ensureBoardEditor($taskLabel->board, $request->user());

        $board = $taskLabel->board;
        $taskLabel->cards()->detach();
        $taskLabel->delete();

        return response()->json(['deleted' => true, 'labels' => $board->labels()->get()]);
    }

    public function storeChecklist(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $checklist = $taskCard->checklists()->create([
            'title' => $validated['title'],
            'sort_order' => ((int) $taskCard->checklists()->max('sort_order')) + 1,
        ]);

        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'checklist.created', 'added a checklist');

        return response()->json(['checklist' => $checklist, 'card' => $this->freshCard($taskCard)], 201);
    }

    public function updateChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $taskChecklist->update($validated);

        return response()->json(['card' => $this->freshCard($taskChecklist->card)]);
    }

    public function destroyChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $card = $taskChecklist->card;
        $taskChecklist->delete();
        $this->recordActivity($card->board, $card, $request->user()->id, 'checklist.deleted', 'deleted a checklist');

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function storeChecklistItem(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'parent_item_id' => 'nullable|integer|exists:task_checklist_items,id',
            'assigned_to' => 'nullable|exists:users,id',
            'due_at' => 'nullable|date',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['due_at']);

        $parentItem = null;
        if (!empty($validated['parent_item_id'])) {
            $parentItem = TaskChecklistItem::where('task_checklist_id', $taskChecklist->id)
                ->whereNull('parent_item_id')
                ->findOrFail($validated['parent_item_id']);
        }

        $taskChecklist->allItems()->create([
            'title' => $validated['title'],
            'parent_item_id' => $parentItem?->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
            'sort_order' => ((int) $taskChecklist->allItems()
                ->where('parent_item_id', $parentItem?->id)
                ->max('sort_order')) + 1,
        ]);

        return response()->json(['card' => $this->freshCard($taskChecklist->card)], 201);
    }

    public function updateChecklistItem(Request $request, TaskChecklistItem $taskChecklistItem)
    {
        $this->ensureBoardEditor($taskChecklistItem->checklist->card->board, $request->user());

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'is_complete' => 'nullable|boolean',
            'assigned_to' => 'nullable|exists:users,id',
            'due_at' => 'nullable|date',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['due_at']);

        $taskChecklistItem->update($validated);

        if ($taskChecklistItem->project_task_id) {
            $this->projectTaskBoards->syncProjectTaskFromChecklistItem($taskChecklistItem->fresh(['projectTask', 'checklist.card.project']), $request->user());
        }

        return response()->json(['card' => $this->freshCard($taskChecklistItem->checklist->card)]);
    }

    public function destroyChecklistItem(Request $request, TaskChecklistItem $taskChecklistItem)
    {
        $this->ensureBoardEditor($taskChecklistItem->checklist->card->board, $request->user());

        $card = $taskChecklistItem->checklist->card;
        $taskChecklistItem->delete();

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function duplicateChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $card = $taskChecklist->card;
        $newSortOrder = ((int) $card->checklists()->max('sort_order')) + 1;

        $newChecklist = $card->checklists()->create([
            'title' => $taskChecklist->title . ' (Copy)',
            'sort_order' => $newSortOrder,
        ]);

        foreach ($taskChecklist->items as $item) {
            $newItem = $newChecklist->allItems()->create([
                'title' => $item->title,
                'is_complete' => false,
                'assigned_to' => $item->assigned_to,
                'due_at' => $item->due_at,
                'sort_order' => $item->sort_order,
            ]);

            foreach ($item->children as $child) {
                $newChecklist->allItems()->create([
                    'title' => $child->title,
                    'is_complete' => false,
                    'assigned_to' => $child->assigned_to,
                    'due_at' => $child->due_at,
                    'sort_order' => $child->sort_order,
                    'parent_item_id' => $newItem->id,
                ]);
            }
        }

        return response()->json(['card' => $this->freshCard($card)], 201);
    }

    public function duplicateChecklistItem(Request $request, TaskChecklistItem $taskChecklistItem)
    {
        $this->ensureBoardEditor($taskChecklistItem->checklist->card->board, $request->user());

        $checklist = $taskChecklistItem->checklist;
        $card = $checklist->card;

        $newItem = $checklist->allItems()->create([
            'title' => $taskChecklistItem->title,
            'is_complete' => false,
            'assigned_to' => $taskChecklistItem->assigned_to,
            'due_at' => $taskChecklistItem->due_at,
            'sort_order' => $taskChecklistItem->sort_order + 1,
            'parent_item_id' => $taskChecklistItem->parent_item_id,
        ]);

        if (!$taskChecklistItem->parent_item_id) {
            foreach ($taskChecklistItem->children as $child) {
                $checklist->allItems()->create([
                    'title' => $child->title,
                    'is_complete' => false,
                    'assigned_to' => $child->assigned_to,
                    'due_at' => $child->due_at,
                    'sort_order' => $child->sort_order,
                    'parent_item_id' => $newItem->id,
                ]);
            }
        }

        return response()->json(['card' => $this->freshCard($card)], 201);
    }

    public function storeComment(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardAccess($taskCard->board, $request->user());

        $validated = $request->validate([
            'comment_text' => 'required|string|max:5000',
        ]);

        $comment = $taskCard->comments()->create([
            'user_id' => $request->user()->id,
            'comment_text' => $validated['comment_text'],
        ]);

        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'comment.created', 'commented on this card');

        return response()->json(['comment' => $comment->load('user:id,name,profile_photo'), 'card' => $this->freshCard($taskCard)], 201);
    }

    public function destroyComment(Request $request, TaskCardComment $taskCardComment)
    {
        $board = $taskCardComment->card->board;

        if ((int) $taskCardComment->user_id !== (int) $request->user()->id) {
            $this->ensureBoardEditor($board, $request->user());
        } else {
            $this->ensureBoardAccess($board, $request->user());
        }

        $card = $taskCardComment->card;
        $taskCardComment->delete();

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function storeAttachment(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'attachment' => 'required|file|max:51200',
        ]);

        $file = $validated['attachment'];
        $path = $file->store('task-card-attachments/' . $taskCard->id, 'public');

        $attachment = $taskCard->attachments()->create([
            'user_id' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'file_storage_path' => $path,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        if (!$taskCard->cover_type && str_starts_with((string) $file->getMimeType(), 'image/')) {
            $taskCard->update([
                'cover_type' => 'image',
                'cover_value' => $path,
            ]);
        }

        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'attachment.created', 'attached ' . $attachment->file_name);

        return response()->json(['attachment' => $attachment->load('user:id,name,profile_photo'), 'card' => $this->freshCard($taskCard)], 201);
    }

    public function destroyAttachment(Request $request, TaskCardAttachment $taskCardAttachment)
    {
        $this->ensureBoardEditor($taskCardAttachment->card->board, $request->user());

        $card = $taskCardAttachment->card;

        if (Storage::disk('public')->exists($taskCardAttachment->file_storage_path)) {
            Storage::disk('public')->delete($taskCardAttachment->file_storage_path);
        }

        $taskCardAttachment->delete();

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function toggleWatch(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardAccess($taskCard->board, $request->user());

        $validated = $request->validate([
            'watching' => 'required|boolean',
        ]);

        if ($validated['watching']) {
            $taskCard->watchers()->syncWithoutDetaching([$request->user()->id]);
        } else {
            $taskCard->watchers()->detach($request->user()->id);
        }

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    private function syncAssignees(TaskCard $card, array $assigneeIds): void
    {
        $validIds = $card->board->memberRecords()
            ->whereIn('user_id', $assigneeIds)
            ->pluck('user_id')
            ->all();

        $card->assignees()->sync($validIds);
        $card->watchers()->syncWithoutDetaching($validIds);
    }

    private function normalizeDateTimeFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $data[$field] = blank($data[$field])
                ? null
                : Carbon::parse($data[$field])->format('Y-m-d H:i:s');
        }

        return $data;
    }

    private function syncLabels(TaskCard $card, array $labelIds): void
    {
        $validIds = $card->board->labels()
            ->whereIn('id', $labelIds)
            ->pluck('id')
            ->all();

        $card->labels()->sync($validIds);
    }

    private function freshCard(TaskCard $card): array
    {
        $card = $card->fresh([
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
        ]);

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

    private function ensureBoardEditor(TaskBoard $board, User $user): void
    {
        if ($this->canSeeAllBoards($user) || in_array($board->memberRole($user), ['admin', 'member'], true)) {
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
