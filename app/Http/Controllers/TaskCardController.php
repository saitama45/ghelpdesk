<?php

namespace App\Http\Controllers;

use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
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
    public function __construct(
        private ProjectTaskBoardSyncService $projectTaskBoards,
        private \App\Services\NotificationService $notifications
    )
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
            'status' => ['required', Rule::in($taskBoard->columns()->pluck('name')->all())],
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

        // A card is only a container — it never becomes a project activity by itself.
        // Only its checklists (milestones), items (activities), and subtasks sync to the
        // project. So every card, on any board/column, is created as a plain card.
        $card = DB::transaction(function () use ($request, $taskBoard, $validated) {
            $sortOrder = ((int) $taskBoard->cards()
                ->reorder()
                ->where('status', $validated['status'])
                ->whereNull('archived_at')
                ->max('sort_order')) + 1000;

            $card = TaskCard::create([
                'task_board_id' => $taskBoard->id,
                'task_board_column_id' => $taskBoard->columnForName($validated['status'])?->id,
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

        $this->notifications->notifyTaskCard(
            $card,
            'created',
            'New card created',
            \Illuminate\Support\Str::limit($card->title, 60),
            $request->user()->id
        );

        return response()->json(['card' => $this->freshCard($card)], 201);
    }

    public function update(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['sometimes', 'required', Rule::in($taskCard->board->columns()->pluck('name')->all())],
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
            'weight_basis' => 'nullable|in:none,item,subtask',
            'project_category' => 'nullable|string|max:255',
            'project_progress' => 'nullable|integer|min:0|max:100',
            'project_assigned_to' => 'nullable|integer|exists:users,id',
            'project_support_by' => 'nullable|integer|exists:users,id',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['start_at', 'due_at']);

        $syncedCard = null;
        $oldAssigneeIds = array_key_exists('assignee_ids', $validated)
            ? $taskCard->assignees()->pluck('users.id')->all()
            : [];

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

            if (array_key_exists('weight_basis', $validated)) {
                $updates['weight_basis'] = $validated['weight_basis'] === 'none' ? null : $validated['weight_basis'];
            }

            if (array_key_exists('status', $validated)) {
                $updates['task_board_column_id'] = $taskCard->board->columnForName($validated['status'])?->id;
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

            // When weighting is active, the computed completion is the source of truth for progress.
            $this->applyWeightedCompletion($taskCard, $request->user());
        });

        // Notify only the newly added assignees that they were assigned to this card.
        if (array_key_exists('assignee_ids', $validated)) {
            $added = array_values(array_diff(
                $taskCard->assignees()->pluck('users.id')->all(),
                $oldAssigneeIds
            ));

            if (!empty($added)) {
                $this->notifications->dispatch($added, $request->user()->id, [
                    'domain' => 'task_card',
                    'event' => 'assignment',
                    'title' => 'Assigned to a card',
                    'message' => 'You were assigned to ' . \Illuminate\Support\Str::limit($taskCard->title, 60),
                    'subject' => 'task_card:' . $taskCard->id,
                    'url' => route('task-boards.show', $taskCard->task_board_id, false) . '?card=' . $taskCard->id,
                ]);
            }
        }

        return response()->json(['card' => $this->freshCard($syncedCard ?: $taskCard)]);
    }

    public function move(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $validated = $request->validate([
            'status' => ['required', Rule::in($taskCard->board->columns()->pluck('name')->all())],
            'ordered_card_ids' => 'required|array',
            'ordered_card_ids.*' => 'integer|exists:task_cards,id',
        ]);

        $targetColumnId = $taskCard->board->columnForName($validated['status'])?->id;
        $statusBeforeMove = $taskCard->status;

        DB::transaction(function () use ($request, $taskCard, $validated, $targetColumnId) {
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
                        'task_board_column_id' => $targetColumnId,
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

        if ($statusBeforeMove !== $validated['status']) {
            $this->notifications->notifyTaskCard(
                $taskCard,
                'movement',
                'Card moved',
                \Illuminate\Support\Str::limit($taskCard->title, 40) . ": {$statusBeforeMove} → {$validated['status']}",
                $request->user()->id
            );
        }

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function duplicate(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $taskCard->loadMissing(['assignees', 'labels', 'checklists.items.children']);

        $newCard = DB::transaction(function () use ($request, $taskCard) {
            $board = $taskCard->board;

            // Place the duplicate right after the original in the same column.
            $afterSortOrder = (int) $taskCard->sort_order;
            $newSortOrder = $afterSortOrder + 500;

            // Shift cards that already sit above the insertion point to make room.
            TaskCard::where('task_board_id', $board->id)
                ->where('status', $taskCard->status)
                ->where('sort_order', '>', $afterSortOrder)
                ->whereNull('archived_at')
                ->increment('sort_order', 1000);

            $newCard = TaskCard::create([
                'task_board_id'       => $board->id,
                'task_board_column_id' => $taskCard->task_board_column_id,
                'project_id'          => null,
                'project_task_id'     => null,
                'title'               => $taskCard->title . ' (Copy)',
                'description'         => $taskCard->description,
                'status'              => $taskCard->status,
                'sort_order'          => $newSortOrder,
                'start_at'            => $taskCard->start_at,
                'due_at'              => $taskCard->due_at,
                'cover_type'          => $taskCard->cover_type,
                'cover_value'         => $taskCard->cover_value,
                'created_by'          => $request->user()->id,
            ]);

            // Copy assignees and labels.
            $newCard->assignees()->sync($taskCard->assignees->pluck('id')->all());
            $newCard->labels()->sync($taskCard->labels->pluck('id')->all());
            $newCard->watchers()->syncWithoutDetaching([$request->user()->id]);

            // Deep-copy checklists → items → subtasks (no project_task_id links on the copy).
            foreach ($taskCard->checklists as $checklist) {
                $newChecklist = $newCard->checklists()->create([
                    'title'      => $checklist->title,
                    'sort_order' => $checklist->sort_order,
                ]);

                foreach ($checklist->items as $item) {
                    $newItem = $newChecklist->allItems()->create([
                        'title'      => $item->title,
                        'is_complete' => false,
                        'weight'     => $item->weight,
                        'assigned_to' => $item->assigned_to,
                        'due_at'     => $item->due_at,
                        'sort_order' => $item->sort_order,
                    ]);

                    foreach ($item->children as $child) {
                        $newChecklist->allItems()->create([
                            'title'        => $child->title,
                            'is_complete'  => false,
                            'weight'       => $child->weight,
                            'assigned_to'  => $child->assigned_to,
                            'due_at'       => $child->due_at,
                            'sort_order'   => $child->sort_order,
                            'parent_item_id' => $newItem->id,
                        ]);
                    }
                }
            }

            $this->recordActivity($board, $newCard, $request->user()->id, 'card.duplicated', 'duplicated from card #' . $taskCard->id);

            return $newCard;
        });

        // Sync the duplicate's checklist structure to the project Gantt (creates new tasks).
        if ($taskCard->board->project_id) {
            $newCard->setRelation('board', $taskCard->board);
            $this->projectTaskBoards->syncProjectStructureFromCard($newCard->load('checklists.items.children'), $request->user());
        }

        return response()->json(['card' => $this->freshCard($newCard)], 201);
    }

    public function archive(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        // Remove any project tasks mirrored from this card's checklist structure.
        if ($taskCard->board->project_id) {
            $this->projectTaskBoards->deleteProjectTasksForCard($taskCard, 'soft');
        }

        $taskCard->update(['archived_at' => now()]);
        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.archived', 'archived this card');

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function restore(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardEditor($taskCard->board, $request->user());

        $taskCard->update(['archived_at' => null]);
        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'card.restored', 'restored this card');

        // Re-sync the card's checklist structure back to the project Gantt.
        if ($taskCard->board->project_id) {
            $this->projectTaskBoards->syncProjectStructureFromCard($taskCard->fresh(['checklists.items.children']), $request->user());
        }

        return response()->json(['card' => $this->freshCard($taskCard)]);
    }

    public function destroy(Request $request, TaskCard $taskCard)
    {
        $this->ensureBoardAdmin($taskCard->board, $request->user());

        if (!$taskCard->archived_at) {
            abort(422, 'Archive the card before deleting it.');
        }

        // Guard: clean up any remaining project tasks (in case archive was done before this feature).
        if ($taskCard->board->project_id) {
            $this->projectTaskBoards->deleteProjectTasksForCard($taskCard, 'force');
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

    public function storeColumn(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardEditor($taskBoard, $request->user());

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('task_board_columns', 'name')->where('task_board_id', $taskBoard->id),
            ],
            'color' => 'required|string|max:40',
        ]);

        $column = $taskBoard->columns()->create([
            'name' => $validated['name'],
            'color' => $validated['color'],
            'role' => null,
            'sort_order' => ((int) $taskBoard->columns()->max('sort_order')) + 1,
        ]);

        $this->recordActivity($taskBoard, null, $request->user()->id, 'column.created', "added the \"{$column->name}\" column");

        return response()->json(['column' => $column, 'columns' => $taskBoard->columns()->get()], 201);
    }

    public function updateColumn(Request $request, TaskBoardColumn $taskBoardColumn)
    {
        $board = $taskBoardColumn->board;
        $this->ensureBoardEditor($board, $request->user());

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('task_board_columns', 'name')
                    ->where('task_board_id', $board->id)
                    ->ignore($taskBoardColumn->id),
            ],
            'color' => 'required|string|max:40',
        ]);

        $oldName = $taskBoardColumn->name;

        DB::transaction(function () use ($taskBoardColumn, $board, $validated, $oldName) {
            $taskBoardColumn->update([
                'name' => $validated['name'],
                'color' => $validated['color'],
            ]);

            // Keep the legacy status string in sync with the renamed column.
            if ($oldName !== $validated['name']) {
                $board->cards()->where('status', $oldName)->update(['status' => $validated['name']]);
            }
        });

        return response()->json([
            'column' => $taskBoardColumn->fresh(),
            'columns' => $board->columns()->get(),
        ]);
    }

    public function destroyColumn(Request $request, TaskBoardColumn $taskBoardColumn)
    {
        $board = $taskBoardColumn->board;
        $this->ensureBoardEditor($board, $request->user());

        if ($board->columns()->count() <= 1) {
            abort(422, 'A board must keep at least one column.');
        }

        if ($taskBoardColumn->cards()->whereNull('archived_at')->exists()) {
            abort(422, 'Move or archive the cards in this column before deleting it.');
        }

        if ($taskBoardColumn->role && $board->project_id) {
            abort(422, 'This is a default column required to sync the project and cannot be removed.');
        }

        $name = $taskBoardColumn->name;
        $taskBoardColumn->delete();

        $this->recordActivity($board, null, $request->user()->id, 'column.deleted', "removed the \"{$name}\" column");

        return response()->json(['deleted' => true, 'columns' => $board->columns()->get()]);
    }

    public function reorderColumns(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardEditor($taskBoard, $request->user());

        $validated = $request->validate([
            'ordered_column_ids' => 'required|array',
            'ordered_column_ids.*' => 'integer',
        ]);

        $validIds = $taskBoard->columns()->pluck('id')->all();

        DB::transaction(function () use ($validated, $validIds, $taskBoard) {
            foreach ($validated['ordered_column_ids'] as $index => $columnId) {
                if (!in_array((int) $columnId, $validIds, true)) {
                    continue;
                }

                $taskBoard->columns()->whereKey($columnId)->update(['sort_order' => $index]);
            }
        });

        return response()->json(['columns' => $taskBoard->columns()->get()]);
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

        $this->notifications->notifyTaskCard(
            $taskCard,
            'checklist',
            'Checklist added',
            \Illuminate\Support\Str::limit($taskCard->title, 40) . ': added checklist "' . \Illuminate\Support\Str::limit($validated['title'], 40) . '"',
            $request->user()->id
        );

        return response()->json(['checklist' => $checklist, 'card' => $this->freshCard($taskCard)], 201);
    }

    public function updateChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'due_date' => 'nullable|date',
            'weight' => 'nullable|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $taskChecklist->update($validated);
        $this->projectTaskBoards->syncProjectStructureFromCard($taskChecklist->card, $request->user());
        $this->applyWeightedCompletion($taskChecklist->card, $request->user());

        return response()->json(['card' => $this->freshCard($taskChecklist->card)]);
    }

    public function destroyChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $card = $taskChecklist->card;
        // Remove the mirrored project tasks (milestone's activities/subtasks) before deleting.
        $taskChecklist->loadMissing('items.children');
        foreach ($taskChecklist->items as $item) {
            $this->projectTaskBoards->deleteProjectTasksForChecklistItem($item);
        }
        $taskChecklist->delete();
        $this->recordActivity($card->board, $card, $request->user()->id, 'checklist.deleted', 'deleted a checklist');
        $this->applyWeightedCompletion($card, $request->user());

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

        $this->projectTaskBoards->syncProjectStructureFromCard($taskChecklist->card, $request->user());

        $kind = $parentItem ? 'subtask' : 'activity';
        $this->notifications->notifyTaskCard(
            $taskChecklist->card,
            'item',
            'New ' . $kind . ' added',
            \Illuminate\Support\Str::limit($taskChecklist->card->title, 40) . ": new {$kind} \"" . \Illuminate\Support\Str::limit($validated['title'], 40) . '"',
            $request->user()->id,
            !empty($validated['assigned_to']) ? [(int) $validated['assigned_to']] : []
        );

        return response()->json(['card' => $this->freshCard($taskChecklist->card)], 201);
    }

    public function updateChecklistItem(Request $request, TaskChecklistItem $taskChecklistItem)
    {
        $this->ensureBoardEditor($taskChecklistItem->checklist->card->board, $request->user());

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'due_date' => 'nullable|date',
            'is_complete' => 'nullable|boolean',
            'weight' => 'nullable|numeric|min:0|max:100',
            'assigned_to' => 'nullable|exists:users,id',
            'due_at' => 'nullable|date',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $validated = $this->normalizeDateTimeFields($validated, ['due_at']);

        // Checking/unchecking an item without an explicit weight drives its progress to
        // 100%/0% so the board checkbox and the Gantt activity progress stay in lockstep.
        if (array_key_exists('is_complete', $validated) && !array_key_exists('weight', $validated)) {
            $validated['weight'] = $validated['is_complete'] ? 100 : 0;
        }

        $wasComplete = (bool) $taskChecklistItem->is_complete;
        $oldWeight = (int) $taskChecklistItem->weight;

        $taskChecklistItem->update($validated);

        // Board → Project: mirror the rename/complete/structure onto the Gantt.
        $this->projectTaskBoards->syncProjectStructureFromCard($taskChecklistItem->checklist->card, $request->user());
        $this->applyWeightedCompletion($taskChecklistItem->checklist->card, $request->user());

        // Notify board members when an item is checked/unchecked or its % changes.
        $card = $taskChecklistItem->checklist->card;
        $itemTitle = \Illuminate\Support\Str::limit($taskChecklistItem->title, 40);
        $cardTitle = \Illuminate\Support\Str::limit($card->title, 40);

        if (array_key_exists('is_complete', $validated) && (bool) $taskChecklistItem->is_complete !== $wasComplete) {
            $done = (bool) $taskChecklistItem->is_complete;
            $this->notifications->notifyTaskCard(
                $card,
                'item',
                $done ? 'Item completed' : 'Item reopened',
                "{$cardTitle}: " . ($done ? 'completed' : 'reopened') . " \"{$itemTitle}\"",
                $request->user()->id,
                [],
                $done ? 'success' : 'info'
            );
        } elseif (array_key_exists('weight', $validated) && (int) $taskChecklistItem->weight !== $oldWeight) {
            $this->notifications->notifyTaskCard(
                $card,
                'progress',
                'Item progress updated',
                "{$cardTitle}: \"{$itemTitle}\" {$oldWeight}% → " . (int) $taskChecklistItem->weight . '%',
                $request->user()->id
            );
        }

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function destroyChecklistItem(Request $request, TaskChecklistItem $taskChecklistItem)
    {
        $this->ensureBoardEditor($taskChecklistItem->checklist->card->board, $request->user());

        $card = $taskChecklistItem->checklist->card;
        // Remove the mirrored project task(s) before deleting the board item.
        $taskChecklistItem->loadMissing('children');
        // Subtasks reference their parent via the self-referencing parent_item_id FK,
        // which SQL Server will not cascade — delete the children first so removing an
        // activity that still has subtasks doesn't violate the constraint.
        foreach ($taskChecklistItem->children as $child) {
            $this->projectTaskBoards->deleteProjectTasksForChecklistItem($child);
            $child->delete();
        }
        $this->projectTaskBoards->deleteProjectTasksForChecklistItem($taskChecklistItem);
        $taskChecklistItem->delete();
        $this->applyWeightedCompletion($card, $request->user());

        return response()->json(['card' => $this->freshCard($card)]);
    }

    public function duplicateChecklist(Request $request, TaskChecklist $taskChecklist)
    {
        $this->ensureBoardEditor($taskChecklist->card->board, $request->user());

        $card = $taskChecklist->card;
        $newSortOrder = ((int) $card->checklists()->max('sort_order')) + 1;

        $newChecklist = $card->checklists()->create([
            'title' => $taskChecklist->title . ' (Copy)',
            'weight' => $taskChecklist->weight,
            'sort_order' => $newSortOrder,
        ]);

        foreach ($taskChecklist->items as $item) {
            $newItem = $newChecklist->allItems()->create([
                'title' => $item->title,
                'is_complete' => false,
                'weight' => $item->weight,
                'assigned_to' => $item->assigned_to,
                'due_at' => $item->due_at,
                'sort_order' => $item->sort_order,
            ]);

            foreach ($item->children as $child) {
                $newChecklist->allItems()->create([
                    'title' => $child->title,
                    'is_complete' => false,
                    'weight' => $child->weight,
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
            'weight' => $taskChecklistItem->weight,
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
                    'weight' => $child->weight,
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
            'mentions' => 'nullable|array',
            'mentions.*' => 'integer|exists:users,id',
        ]);

        $comment = $taskCard->comments()->create([
            'user_id' => $request->user()->id,
            'comment_text' => $validated['comment_text'],
        ]);

        $this->recordActivity($taskCard->board, $taskCard, $request->user()->id, 'comment.created', 'commented on this card');

        $this->notifications->notifyTaskCard(
            $taskCard,
            'comment',
            'New comment on card',
            \Illuminate\Support\Str::limit($taskCard->title, 40) . ': ' . \Illuminate\Support\Str::limit($validated['comment_text'], 100),
            $request->user()->id,
            array_map('intval', (array) ($validated['mentions'] ?? []))
        );

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

        $sharedPathCount = TaskCardAttachment::where('file_storage_path', $taskCardAttachment->file_storage_path)
            ->where('id', '!=', $taskCardAttachment->id)
            ->count();

        if ($sharedPathCount === 0 && Storage::disk('public')->exists($taskCardAttachment->file_storage_path)) {
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

    /**
     * When a card uses weighted completion and is linked to a project task, push the
     * computed completion into the project task's progress so it reflects on /projects.
     */
    private function applyWeightedCompletion(TaskCard $card, User $user): void
    {
        $card = $card->fresh(['checklists.items.children', 'projectTask.project']);

        if (!$card || !$card->weight_basis || !$card->projectTask) {
            return;
        }

        $completion = $card->weighted_completion;
        if ($completion === null) {
            return;
        }

        // Directly drive the linked activity's progress from the weighted completion so it
        // matches exactly on /projects (and the project's overall completion).
        $task = $card->projectTask;
        $task->update([
            'progress' => $completion,
            'status' => $completion >= 100 ? 'Done' : ($completion > 0 ? 'Ongoing' : 'Pending'),
        ]);
        $task->project?->recalculateStatus();
    }

    private function freshCard(TaskCard $card): array
    {
        $card = $card->fresh([
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
            'projectTask.assignedUser:id,name,email,profile_photo',
            'projectTask.supportUser:id,name,email,profile_photo',
            'projectTask.parentTask:id,name,category',
        ]);

        return [
            'id' => $card->id,
            'task_board_id' => $card->task_board_id,
            'column_id' => $card->task_board_column_id,
            'column_role' => $card->column_role,
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
            'weight_basis' => $card->weight_basis,
            'weighted_completion' => $card->weighted_completion,
            'weight_total' => $card->weight_total,
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
