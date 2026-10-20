<?php

namespace App\Http\Controllers;

use App\Models\TaskBoard;
use App\Models\TaskBoardWhiteboard;
use App\Models\TaskCard;
use App\Models\User;
use App\Support\WhiteboardScene;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;

/**
 * One shared whiteboard per task board: freehand ink, shapes, text, sticky notes and
 * snapshots of board cards, split into pages that can be presented full-screen.
 *
 * Saves are optimistic: the client sends the revision it started from, and a save made
 * against a stale revision is refused with 409 plus the current scene so the client can
 * merge its own changes on top and retry.
 */
class TaskBoardWhiteboardController extends Controller implements HasMiddleware
{
    private const MAX_SCENE_BYTES = 4_000_000;

    public static function middleware(): array
    {
        return [
            new Middleware('can:task_boards.view', only: ['show', 'state']),
            new Middleware('can:task_boards.edit', only: ['update']),
        ];
    }

    public function show(Request $request, TaskBoard $taskBoard)
    {
        $user = $request->user();
        $this->ensureBoardAccess($taskBoard, $user);

        $whiteboard = $taskBoard->whiteboard()->with('editor:id,name')->first();

        return Inertia::render('TaskBoards/Whiteboard', [
            'board' => [
                'id' => $taskBoard->id,
                'title' => $taskBoard->title,
                'background_type' => $taskBoard->background_type,
                'background_value' => $taskBoard->background_value ?: '#0f766e',
                'closed_at' => $taskBoard->closed_at,
            ],
            'whiteboard' => $this->payload($whiteboard),
            'cards' => $this->cardOptions($taskBoard),
            'canEdit' => $this->canEdit($taskBoard, $user),
        ]);
    }

    /**
     * Cheap poll for viewers: returns only the revision unless it moved past the one the
     * client already has, so an idle whiteboard never re-reads the scene column.
     * `cards=1` also returns the live card list (card snapshots follow board moves).
     */
    public function state(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->ensureBoardAccess($taskBoard, $request->user());

        $known = (int) $request->query('revision', -1);
        $revision = (int) (TaskBoardWhiteboard::where('task_board_id', $taskBoard->id)->value('revision') ?? 0);
        $changed = $revision !== $known;

        $response = ['changed' => $changed, 'revision' => $revision];

        if ($changed) {
            $response = [...$response, ...$this->payload($taskBoard->whiteboard()->with('editor:id,name')->first())];
        }

        if ($changed || $request->boolean('cards')) {
            $response['cards'] = $this->cardOptions($taskBoard);
        }

        return response()->json($response);
    }

    public function update(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canEdit($taskBoard, $user), 403);

        $validated = $request->validate([
            'revision' => 'required|integer|min:0',
            'scene' => 'required|array',
            'scene.pages' => 'required|array|min:1|max:30',
        ]);

        $scene = WhiteboardScene::sanitizer(withCards: true)->sanitize($validated['scene']);
        $encoded = json_encode($scene, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > self::MAX_SCENE_BYTES) {
            return response()->json([
                'message' => 'This whiteboard is too large to save. Split it across pages or erase some ink.',
            ], 422);
        }

        $base = (int) $validated['revision'];

        // Conditional write: only lands if nobody saved since the client's base revision.
        $updated = TaskBoardWhiteboard::where('task_board_id', $taskBoard->id)
            ->where('revision', $base)
            ->update([
                'scene' => $encoded,
                'revision' => $base + 1,
                'updated_by' => $user->id,
            ]);

        if ($updated === 0) {
            $exists = TaskBoardWhiteboard::where('task_board_id', $taskBoard->id)->exists();

            if ($exists || $base !== 0) {
                return $this->conflict($taskBoard);
            }

            try {
                TaskBoardWhiteboard::create([
                    'task_board_id' => $taskBoard->id,
                    'scene' => $scene,
                    'revision' => 1,
                    'updated_by' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->conflict($taskBoard);
            }
        }

        $whiteboard = $taskBoard->whiteboard()->first(['id', 'task_board_id', 'revision', 'updated_at']);

        return response()->json([
            'revision' => $whiteboard->revision,
            'updated_at' => $whiteboard->updated_at,
            'updated_by' => $user->name,
        ]);
    }

    private function conflict(TaskBoard $taskBoard): JsonResponse
    {
        $whiteboard = $taskBoard->whiteboard()->with('editor:id,name')->first();

        return response()->json([
            'message' => 'Someone else saved this whiteboard. Your changes were merged with theirs.',
            ...$this->payload($whiteboard),
        ], 409);
    }

    private function payload(?TaskBoardWhiteboard $whiteboard): array
    {
        return [
            'revision' => $whiteboard?->revision ?? 0,
            'scene' => $whiteboard?->scene,
            'updated_at' => $whiteboard?->updated_at,
            'updated_by' => $whiteboard?->editor?->name,
        ];
    }

    /**
     * Live cards for the "Board cards" panel and for refreshing card snapshots on the canvas.
     * Pinned columns: the card description is a LOB and is never needed here.
     */
    private function cardOptions(TaskBoard $taskBoard): array
    {
        return $taskBoard->cards()
            ->reorder()
            ->whereNull('archived_at')
            ->with(['column:id,name,color', 'assignees:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'task_board_id', 'task_board_column_id', 'title', 'status', 'due_at', 'due_complete'])
            ->map(fn (TaskCard $card) => [
                'id' => $card->id,
                'title' => $card->title,
                'status' => $card->status,
                'color' => $card->column?->color ?: '#64748b',
                'column_id' => $card->task_board_column_id,
                'due_at' => $card->due_at?->format('Y-m-d H:i:s'),
                'due_complete' => (bool) $card->due_complete,
                'assignees' => $card->assignees->pluck('name')->values(),
            ])
            ->values()
            ->all();
    }

    private function canEdit(TaskBoard $board, User $user): bool
    {
        if ($board->closed_at || ! $user->can('task_boards.edit')) {
            return false;
        }

        return $this->canSeeAllBoards($user) || in_array($board->memberRole($user), ['admin', 'member'], true);
    }

    private function canSeeAllBoards(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'Solutions Admin']);
    }

    private function ensureBoardAccess(TaskBoard $board, User $user): void
    {
        abort_unless($this->canSeeAllBoards($user) || $board->isMember($user), 403);
    }
}
