<?php

namespace App\Http\Controllers;

use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskCard;
use App\Models\TaskCardActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TaskBoardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:task_lists.view', only: ['index', 'show', 'toggleStar', 'toggleWatch']),
            new Middleware('can:task_lists.create', only: ['store']),
            new Middleware('can:task_lists.edit', only: ['update']),
            new Middleware('can:task_lists.delete', only: ['destroy', 'restore']),
            new Middleware('can:task_lists.manage_members', only: ['storeMember', 'updateMember', 'destroyMember']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $showClosed = $request->boolean('closed');

        $boards = TaskBoard::query()
            ->with(['creator:id,name', 'members:id,name,profile_photo'])
            ->when(!$this->canSeeAllBoards($user), function ($query) use ($user) {
                $query->whereHas('memberRecords', fn ($memberQuery) => $memberQuery->where('user_id', $user->id));
            })
            ->when(!$showClosed, fn ($query) => $query->whereNull('closed_at'))
            ->latest('updated_at')
            ->get()
            ->map(fn (TaskBoard $board) => $this->boardSummary($board, $user));

        return Inertia::render('TaskLists/Index', [
            'boards' => $boards,
            'users' => $this->activeUsers(),
            'filters' => [
                'closed' => $showClosed,
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

        return redirect()->route('task-lists.show', $board)->with('success', 'Task board created successfully.');
    }

    public function show(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAccess($taskBoard, $request->user());

        $taskBoard->memberRecords()
            ->where('user_id', $request->user()->id)
            ->update(['last_opened_at' => now()]);

        $taskBoard->load([
            'creator:id,name',
            'members:id,name,email,profile_photo',
            'watchers:id,name',
            'labels',
            'activities.actor:id,name,profile_photo',
            'activities.card:id,title',
        ]);

        $cards = $taskBoard->cards()
            ->reorder()
            ->with([
                'creator:id,name,profile_photo',
                'assignees:id,name,email,profile_photo',
                'labels',
                'watchers:id,name',
                'checklists.items.assignee:id,name,profile_photo',
                'comments.user:id,name,profile_photo',
                'attachments.user:id,name,profile_photo',
                'activities.actor:id,name,profile_photo',
            ])
            ->orderBy('status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('TaskLists/Show', [
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

        return redirect()->route('task-lists.index')->with('success', 'Task board closed successfully.');
    }

    public function restore(Request $request, TaskBoard $taskBoard)
    {
        $this->ensureBoardAdmin($taskBoard, $request->user());

        $taskBoard->update(['closed_at' => null]);
        $this->recordActivity($taskBoard, null, $request->user()->id, 'board.restored', 'reopened this board');

        return redirect()->route('task-lists.show', $taskBoard)->with('success', 'Task board restored successfully.');
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
            'members_count' => $board->members->count(),
            'members' => $this->memberPayload($board),
            'my_role' => $this->canSeeAllBoards($user) ? ($memberRecord?->role ?? 'admin') : $memberRecord?->role,
            'starred' => (bool) $memberRecord?->starred,
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
            'watching' => $board->watchers->contains('id', $user->id),
        ];
    }

    private function cardPayload(TaskCard $card): array
    {
        return [
            'id' => $card->id,
            'task_board_id' => $card->task_board_id,
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
            'checklist_totals' => $card->checklist_totals,
        ];
    }

    private function memberPayload(TaskBoard $board): array
    {
        return $board->members->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'profile_photo' => $member->profile_photo,
            'role' => $member->pivot->role,
            'starred' => (bool) $member->pivot->starred,
        ])->values()->all();
    }

    private function activeUsers()
    {
        return User::active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_photo']);
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
