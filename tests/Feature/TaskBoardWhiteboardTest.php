<?php

namespace Tests\Feature;

use App\Models\TaskBoard;
use App\Models\TaskBoardWhiteboard;
use App\Models\TaskCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TaskBoardWhiteboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_member_opens_the_whiteboard_with_live_cards(): void
    {
        [$board, $editor] = $this->boardWithMember('member', ['task_boards.view', 'task_boards.edit']);
        $board->seedDefaultColumns();
        $backlog = $board->columns()->where('role', 'backlog')->first();

        TaskCard::create(['task_board_id' => $board->id, 'task_board_column_id' => $backlog->id, 'title' => 'Fix POS printer', 'status' => $backlog->name, 'created_by' => $editor->id]);
        TaskCard::create(['task_board_id' => $board->id, 'task_board_column_id' => $backlog->id, 'title' => 'Archived one', 'status' => $backlog->name, 'created_by' => $editor->id, 'archived_at' => now()]);

        $this->actingAs($editor)
            ->get(route('task-boards.whiteboard', $board))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TaskBoards/Whiteboard')
                ->where('canEdit', true)
                ->where('whiteboard.revision', 0)
                ->has('cards', 1)
                ->where('cards.0.title', 'Fix POS printer')
                ->where('cards.0.status', $backlog->name)
                ->where('cards.0.color', $backlog->color));
    }

    public function test_viewer_without_edit_rights_sees_a_read_only_whiteboard_and_cannot_save(): void
    {
        [$board, $observer] = $this->boardWithMember('observer', ['task_boards.view', 'task_boards.edit']);
        [, $viewOnly] = $this->boardWithMember('member', ['task_boards.view'], $board);

        $this->actingAs($observer)
            ->get(route('task-boards.whiteboard', $board))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));

        $this->actingAs($observer)
            ->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(0))
            ->assertForbidden();

        $this->actingAs($viewOnly)
            ->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(0))
            ->assertForbidden();

        $this->assertDatabaseCount('task_board_whiteboards', 0);
    }

    public function test_non_member_cannot_open_or_poll_the_whiteboard(): void
    {
        [$board] = $this->boardWithMember('member', ['task_boards.view']);
        $outsider = User::factory()->create();
        $outsider->givePermissionTo(Permission::findOrCreate('task_boards.view'));

        $this->actingAs($outsider)->get(route('task-boards.whiteboard', $board))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('task-boards.whiteboard.state', $board))->assertForbidden();
    }

    public function test_closed_board_whiteboard_is_read_only(): void
    {
        [$board, $editor] = $this->boardWithMember('admin', ['task_boards.view', 'task_boards.edit']);
        $board->update(['closed_at' => now()]);

        $this->actingAs($editor)
            ->get(route('task-boards.whiteboard', $board))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));

        $this->actingAs($editor)
            ->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(0))
            ->assertForbidden();
    }

    public function test_save_creates_the_scene_and_strips_anything_not_whitelisted(): void
    {
        [$board, $editor] = $this->boardWithMember('member', ['task_boards.view', 'task_boards.edit']);

        $this->actingAs($editor)
            ->putJson(route('task-boards.whiteboard.update', $board), [
                'revision' => 0,
                'scene' => ['pages' => [[
                    'id' => 'page-a',
                    'name' => 'Agenda',
                    'background' => 'neon',
                    'elements' => [
                        ['id' => 'pen1', 'type' => 'pen', 'points' => [1.234, 2, 3, 4.56, 7], 'color' => '#ef4444', 'size' => 4, 'opacity' => 1, 'onclick' => 'alert(1)'],
                        ['id' => 'txt1', 'type' => 'text', 'x' => 10, 'y' => 20, 'text' => 'Hello', 'color' => 'url(javascript:alert(1))', 'size' => 24],
                        ['id' => 'dot1', 'type' => 'pen', 'points' => [5], 'color' => '#000000', 'size' => 2],
                        ['id' => 'bad id!', 'type' => 'rect', 'x1' => 0, 'y1' => 0, 'x2' => 1, 'y2' => 1],
                        ['id' => 'img1', 'type' => 'image', 'src' => 'https://example.com/x.png'],
                    ],
                ]]],
            ])
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('updated_by', $editor->name);

        $scene = TaskBoardWhiteboard::where('task_board_id', $board->id)->firstOrFail()->scene;

        $this->assertSame('dots', $scene['pages'][0]['background']);
        $this->assertSame(['pen1', 'txt1'], array_column($scene['pages'][0]['elements'], 'id'));
        $this->assertSame([1.2, 2, 3, 4.6], $scene['pages'][0]['elements'][0]['points']);
        $this->assertArrayNotHasKey('onclick', $scene['pages'][0]['elements'][0]);
        $this->assertSame('#111827', $scene['pages'][0]['elements'][1]['color']);
    }

    public function test_stale_save_is_refused_with_the_current_scene(): void
    {
        [$board, $editor] = $this->boardWithMember('member', ['task_boards.view', 'task_boards.edit']);

        $this->actingAs($editor)->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(0, 'first'))->assertOk();
        $this->actingAs($editor)->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(1, 'second'))->assertOk();

        $this->actingAs($editor)
            ->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(1, 'stale'))
            ->assertStatus(409)
            ->assertJsonPath('revision', 2)
            ->assertJsonPath('scene.pages.0.elements.0.text', 'second');

        $this->assertSame(2, TaskBoardWhiteboard::where('task_board_id', $board->id)->value('revision'));
    }

    public function test_poll_returns_the_scene_only_when_the_revision_moved(): void
    {
        [$board, $editor] = $this->boardWithMember('member', ['task_boards.view', 'task_boards.edit']);
        $this->actingAs($editor)->putJson(route('task-boards.whiteboard.update', $board), $this->savePayload(0, 'note'))->assertOk();

        $this->actingAs($editor)
            ->getJson(route('task-boards.whiteboard.state', ['taskBoard' => $board, 'revision' => 1]))
            ->assertOk()
            ->assertExactJson(['changed' => false, 'revision' => 1]);

        $this->actingAs($editor)
            ->getJson(route('task-boards.whiteboard.state', ['taskBoard' => $board, 'revision' => 0]))
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('scene.pages.0.elements.0.text', 'note')
            ->assertJsonStructure(['cards']);

        $this->actingAs($editor)
            ->getJson(route('task-boards.whiteboard.state', ['taskBoard' => $board, 'revision' => 1, 'cards' => 1]))
            ->assertJsonPath('changed', false)
            ->assertJsonStructure(['cards']);
    }

    /**
     * @return array{0: TaskBoard, 1: User}
     */
    private function boardWithMember(string $role, array $permissions, ?TaskBoard $board = null): array
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        $board ??= TaskBoard::create(['title' => 'Ops board', 'background_type' => 'color', 'background_value' => '#0f766e', 'created_by' => $user->id]);
        $board->memberRecords()->create(['user_id' => $user->id, 'role' => $role]);

        return [$board, $user];
    }

    private function savePayload(int $revision, string $text = 'note'): array
    {
        return [
            'revision' => $revision,
            'scene' => ['pages' => [[
                'id' => 'page-1',
                'name' => 'Page 1',
                'background' => 'dots',
                'elements' => [['id' => 'n1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 160, 'text' => $text, 'color' => '#fef08a']],
            ]]],
        ];
    }
}
