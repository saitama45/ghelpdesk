<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\Whiteboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Standalone whiteboards (/whiteboards) — shared by visibility, separate from task-board whiteboards. */
class WhiteboardTest extends TestCase
{
    use RefreshDatabase;

    private Department $ops;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = Department::create(['name' => 'Operations', 'code' => 'OPS', 'is_active' => true]);
        $this->finance = Department::create(['name' => 'Finance', 'code' => 'FIN', 'is_active' => true]);
    }

    public function test_list_and_urls_follow_visibility(): void
    {
        $owner = $this->user($this->ops, ['whiteboards.view', 'whiteboards.edit']);
        $colleague = $this->user($this->ops, ['whiteboards.view']);
        $outsider = $this->user($this->finance, ['whiteboards.view']);

        $private = $this->whiteboard($owner, 'Owner sketch', 'private');
        $opsOnly = $this->whiteboard($owner, 'Ops retro', 'department');
        $shared = $this->whiteboard($outsider, 'Company brainstorm', 'everyone');

        $this->actingAs($colleague)
            ->get(route('whiteboards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Whiteboards/Index')
                ->where('whiteboards.data', fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['Company brainstorm', 'Ops retro']));

        foreach ([$private, $opsOnly] as $hidden) {
            $this->actingAs($outsider)->get(route('whiteboards.show', $hidden))->assertForbidden();
            $this->actingAs($outsider)->getJson(route('whiteboards.state', $hidden))->assertForbidden();
        }

        $this->actingAs($colleague)->get(route('whiteboards.show', $shared))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Whiteboards/Show')
                ->where('can.edit', false)
                ->where('can.manage', false));
    }

    public function test_module_and_writes_need_their_permissions(): void
    {
        $noAccess = $this->user($this->ops, []);
        $viewer = $this->user($this->ops, ['whiteboards.view']);
        $board = $this->whiteboard($viewer, 'Mine', 'department');

        $this->actingAs($noAccess)->get(route('whiteboards.index'))->assertForbidden();
        $this->actingAs($noAccess)->get(route('whiteboards.show', $board))->assertForbidden();
        $this->actingAs($viewer)->putJson(route('whiteboards.scene', $board), $this->savePayload(0))->assertForbidden();
        $this->actingAs($viewer)->post(route('whiteboards.store'), ['title' => 'X', 'visibility' => 'private'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('whiteboards.destroy', $board))->assertForbidden();

        $this->assertSame(1, Whiteboard::count());
    }

    public function test_create_save_and_conflict(): void
    {
        $editor = $this->user($this->ops, ['whiteboards.view', 'whiteboards.create', 'whiteboards.edit']);

        $this->actingAs($editor)->post(route('whiteboards.store'), [
            'title' => 'Retro',
            'visibility' => 'department',
            'scene' => ['pages' => [['id' => 'p1', 'background' => 'grid', 'elements' => [
                ['id' => 's1', 'type' => 'sticky', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 160, 'text' => 'Went well', 'color' => '#fef08a'],
                // Board-card snapshots only exist on task-board whiteboards.
                ['id' => 'k1', 'type' => 'card', 'x' => 0, 'y' => 0, 'cardId' => 5, 'title' => 'Card'],
            ]]]],
        ])->assertRedirect();

        $board = Whiteboard::firstOrFail();
        $this->assertSame($this->ops->id, $board->department_id);
        $this->assertSame(['s1'], array_column($board->scene['pages'][0]['elements'], 'id'));
        $this->assertSame('grid', $board->scene['pages'][0]['background']);

        $this->actingAs($editor)->putJson(route('whiteboards.scene', $board), $this->savePayload(0, 'first'))->assertOk()->assertJsonPath('revision', 1);
        $this->actingAs($editor)->putJson(route('whiteboards.scene', $board), $this->savePayload(0, 'stale'))
            ->assertStatus(409)
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('scene.pages.0.elements.0.text', 'first');

        $this->actingAs($editor)->getJson(route('whiteboards.state', ['lightWhiteboard' => $board->id, 'revision' => 1]))
            ->assertJsonPath('changed', false)
            ->assertJsonMissingPath('scene');
    }

    public function test_only_the_owner_changes_visibility_or_deletes(): void
    {
        $owner = $this->user($this->ops, ['whiteboards.view', 'whiteboards.edit', 'whiteboards.delete']);
        $colleague = $this->user($this->ops, ['whiteboards.view', 'whiteboards.edit', 'whiteboards.delete']);
        $board = $this->whiteboard($owner, 'Sketch', 'department');

        $this->actingAs($colleague)->putJson(route('whiteboards.update', $board), ['title' => 'Renamed'])->assertOk();
        $this->actingAs($colleague)->putJson(route('whiteboards.update', $board), ['visibility' => 'everyone'])->assertForbidden();
        $this->actingAs($colleague)->delete(route('whiteboards.destroy', $board))->assertForbidden();

        $this->assertSame('Renamed', $board->fresh()->title);
        $this->assertSame('department', $board->fresh()->visibility);
    }

    private function user(Department $department, array $permissions): User
    {
        $user = User::factory()->create(['department_id' => $department->id]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function whiteboard(User $owner, string $title, string $visibility): Whiteboard
    {
        return Whiteboard::create([
            'title' => $title,
            'visibility' => $visibility,
            'department_id' => $owner->department_id,
            'scene' => ['version' => 1, 'pages' => [['id' => 'page-1', 'name' => 'Page 1', 'background' => 'dots', 'elements' => []]]],
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
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
