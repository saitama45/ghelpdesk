<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Diagram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DiagramTest extends TestCase
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

    public function test_list_shows_own_department_and_shared_diagrams_but_not_private_or_other_departments(): void
    {
        $owner = $this->user($this->ops, ['diagrams.view']);
        $colleague = $this->user($this->ops, ['diagrams.view']);
        $outsider = $this->user($this->finance, ['diagrams.view']);

        $this->diagram($owner, 'Owner private', 'private');
        $this->diagram($owner, 'Ops flow', 'department');
        $this->diagram($outsider, 'Finance flow', 'department');
        $this->diagram($outsider, 'Company map', 'everyone');

        $this->actingAs($colleague)
            ->get(route('diagrams.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Diagrams/Index')
                ->has('diagrams.data', 2)
                ->where('diagrams.data', fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['Company map', 'Ops flow']));

        $this->actingAs($owner)
            ->get(route('diagrams.index', ['scope' => 'mine']))
            ->assertInertia(fn (Assert $page) => $page->has('diagrams.data', 2));
    }

    public function test_url_is_refused_for_diagrams_the_list_hides(): void
    {
        $owner = $this->user($this->ops, ['diagrams.view', 'diagrams.edit']);
        $outsider = $this->user($this->finance, ['diagrams.view', 'diagrams.edit']);

        $private = $this->diagram($owner, 'Owner private', 'private');
        $opsOnly = $this->diagram($owner, 'Ops flow', 'department');
        $shared = $this->diagram($owner, 'Shared', 'everyone');

        foreach ([$private, $opsOnly] as $hidden) {
            $this->actingAs($outsider)->get(route('diagrams.show', $hidden))->assertForbidden();
            $this->actingAs($outsider)->getJson(route('diagrams.state', $hidden))->assertForbidden();
            $this->actingAs($outsider)->putJson(route('diagrams.scene', $hidden), $this->savePayload(0))->assertForbidden();
        }

        $this->actingAs($outsider)->get(route('diagrams.show', $shared))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Diagrams/Show')
                ->where('can.edit', true)
                ->where('can.manage', false));
    }

    public function test_module_needs_the_view_permission_and_writes_need_their_own(): void
    {
        $noAccess = $this->user($this->ops, []);
        $viewer = $this->user($this->ops, ['diagrams.view']);
        $diagram = $this->diagram($viewer, 'Mine', 'department');

        $this->actingAs($noAccess)->get(route('diagrams.index'))->assertForbidden();
        $this->actingAs($noAccess)->get(route('diagrams.show', $diagram))->assertForbidden();

        $this->actingAs($viewer)->get(route('diagrams.show', $diagram))
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false));
        $this->actingAs($viewer)->putJson(route('diagrams.scene', $diagram), $this->savePayload(0))->assertForbidden();
        $this->actingAs($viewer)->post(route('diagrams.store'), ['title' => 'X', 'visibility' => 'private'])->assertForbidden();
        $this->actingAs($viewer)->post(route('diagrams.duplicate', $diagram))->assertForbidden();
        $this->actingAs($viewer)->delete(route('diagrams.destroy', $diagram))->assertForbidden();

        $this->assertSame(1, Diagram::count());
    }

    public function test_create_stamps_the_creators_department_and_sanitises_the_scene(): void
    {
        $user = $this->user($this->ops, ['diagrams.view', 'diagrams.create']);

        $response = $this->actingAs($user)->post(route('diagrams.store'), [
            'title' => 'Escalation flow',
            'visibility' => 'department',
            'scene' => ['pages' => [[
                'id' => 'p1',
                'name' => '',
                'elements' => [
                    ['id' => 'n1', 'type' => 'node', 'shape' => 'decision', 'x' => 10.26, 'y' => 20, 'text' => 'OK?', 'fill' => 'red', 'onclick' => 'x'],
                    ['id' => 'n2', 'type' => 'node', 'shape' => '<script>', 'x' => 0, 'y' => 0],
                    ['id' => 'c1', 'type' => 'edge', 'source' => 'n1', 'sourcePort' => 'up', 'target' => 'n2', 'routing' => 'zigzag', 'endArrow' => 'block'],
                    ['id' => 'i1', 'type' => 'image', 'src' => 'https://example.com/x.png'],
                ],
            ]]],
        ]);

        $diagram = Diagram::firstOrFail();
        $response->assertRedirect(route('diagrams.show', $diagram));

        $this->assertSame($this->ops->id, $diagram->department_id);
        $this->assertSame($user->id, $diagram->created_by);

        $page = $diagram->scene['pages'][0];
        [$decision, $badShape, $edge] = $page['elements'];
        $this->assertSame('Page 1', $page['name']);
        $this->assertTrue($page['grid']);
        $this->assertSame('#ffffff', $page['background']);
        $this->assertCount(3, $page['elements']);
        $this->assertSame(10.3, $decision['x']);
        $this->assertNull($decision['fill']);
        $this->assertSame(1, $decision['opacity']);
        $this->assertSame(120, $decision['w']);
        $this->assertArrayNotHasKey('onclick', $decision);
        $this->assertSame('rectangle', $badShape['shape']);
        $this->assertNull($edge['sourcePort']);
        $this->assertSame('orthogonal', $edge['routing']);
        $this->assertSame('block', $edge['endArrow']);
        $this->assertSame('none', $edge['startArrow']);
    }

    public function test_stale_save_gets_409_with_the_current_scene_and_preview_must_be_svg(): void
    {
        $editor = $this->user($this->ops, ['diagrams.view', 'diagrams.edit']);
        $diagram = $this->diagram($editor, 'Flow', 'department');

        $this->actingAs($editor)->putJson(route('diagrams.scene', $diagram), [...$this->savePayload(0, 'first'), 'preview' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>'])
            ->assertOk()
            ->assertJsonPath('revision', 1);
        $this->assertStringStartsWith('<svg', $diagram->fresh()->preview);

        $this->actingAs($editor)->putJson(route('diagrams.scene', $diagram), [...$this->savePayload(1, 'second'), 'preview' => '<img src=x onerror=alert(1)>'])
            ->assertOk();
        $this->assertNull($diagram->fresh()->preview);

        $this->actingAs($editor)->putJson(route('diagrams.scene', $diagram), $this->savePayload(1, 'stale'))
            ->assertStatus(409)
            ->assertJsonPath('revision', 2)
            ->assertJsonPath('scene.pages.0.elements.0.text', 'second');

        $this->assertSame(2, $diagram->fresh()->revision);
    }

    public function test_poll_returns_the_scene_only_after_the_revision_moved(): void
    {
        $editor = $this->user($this->ops, ['diagrams.view', 'diagrams.edit']);
        $diagram = $this->diagram($editor, 'Flow', 'department');
        $this->actingAs($editor)->putJson(route('diagrams.scene', $diagram), $this->savePayload(0, 'note'))->assertOk();

        $this->actingAs($editor)->getJson(route('diagrams.state', ['lightDiagram' => $diagram->id, 'revision' => 1]))
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonMissingPath('scene');

        $this->actingAs($editor)->getJson(route('diagrams.state', ['lightDiagram' => $diagram->id, 'revision' => 0]))
            ->assertJsonPath('changed', true)
            ->assertJsonPath('scene.pages.0.elements.0.text', 'note');
    }

    public function test_only_the_owner_changes_visibility_or_deletes(): void
    {
        $owner = $this->user($this->ops, ['diagrams.view', 'diagrams.edit', 'diagrams.delete']);
        $colleague = $this->user($this->ops, ['diagrams.view', 'diagrams.edit', 'diagrams.delete']);
        $diagram = $this->diagram($owner, 'Flow', 'department');

        $this->actingAs($colleague)->putJson(route('diagrams.update', $diagram), ['title' => 'Renamed by colleague'])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed by colleague');
        $this->actingAs($colleague)->putJson(route('diagrams.update', $diagram), ['visibility' => 'everyone'])->assertForbidden();
        $this->actingAs($colleague)->delete(route('diagrams.destroy', $diagram))->assertForbidden();

        $this->actingAs($owner)->putJson(route('diagrams.update', $diagram), ['visibility' => 'private'])
            ->assertOk()
            ->assertJsonPath('visibility', 'private');

        $this->assertSame('private', $diagram->fresh()->visibility);
        $this->assertSame(0, $diagram->fresh()->revision);
    }

    public function test_duplicate_belongs_to_the_person_who_copied_it(): void
    {
        $owner = $this->user($this->ops, ['diagrams.view']);
        $copier = $this->user($this->finance, ['diagrams.view', 'diagrams.create']);
        $source = $this->diagram($owner, 'Company map', 'everyone');

        $this->actingAs($copier)->post(route('diagrams.duplicate', $source))->assertRedirect();

        $copy = Diagram::where('id', '!=', $source->id)->firstOrFail();
        $this->assertSame('Copy of Company map', $copy->title);
        $this->assertSame($copier->id, $copy->created_by);
        $this->assertSame($this->finance->id, $copy->department_id);
        $this->assertSame($source->scene, $copy->scene);
    }

    public function test_scene_keeps_rotation_and_groups_and_only_uploaded_picture_paths(): void
    {
        $editor = $this->user($this->ops, ['diagrams.view', 'diagrams.edit']);
        $diagram = $this->diagram($editor, 'Flow', 'department');

        $this->actingAs($editor)->putJson(route('diagrams.scene', $diagram), [
            'revision' => 0,
            'scene' => ['pages' => [[
                'id' => 'page-1',
                'elements' => [
                    ['id' => 'n1', 'type' => 'node', 'shape' => 'process', 'x' => 0, 'y' => 0, 'rotation' => 45.25, 'groupId' => 'g1'],
                    ['id' => 'n2', 'type' => 'node', 'shape' => 'image', 'x' => 0, 'y' => 0, 'src' => '/serve-storage/diagram-images/2026/10/a.png', 'groupId' => 'g1'],
                    ['id' => 'n3', 'type' => 'node', 'shape' => 'image', 'x' => 0, 'y' => 0, 'src' => 'https://evil.example/x.png'],
                    ['id' => 'n4', 'type' => 'node', 'shape' => 'image', 'x' => 0, 'y' => 0, 'src' => '/serve-storage/../.env'],
                    ['id' => 'c1', 'type' => 'edge', 'source' => 'n1', 'target' => 'n2', 'groupId' => 'not valid!'],
                ],
            ]]],
        ])->assertOk();

        [$rotated, $picture, $external, $traversal, $edge] = $diagram->fresh()->scene['pages'][0]['elements'];
        $this->assertSame(45.3, $rotated['rotation']);
        $this->assertSame('g1', $rotated['groupId']);
        $this->assertSame('/serve-storage/diagram-images/2026/10/a.png', $picture['src']);
        $this->assertNull($external['src']);
        $this->assertNull($traversal['src']);
        $this->assertNull($edge['groupId']);
    }

    public function test_picture_upload_stores_raster_images_only_for_editors(): void
    {
        Storage::fake('public');
        $editor = $this->user($this->ops, ['diagrams.view', 'diagrams.edit']);
        $viewer = $this->user($this->ops, ['diagrams.view']);

        $response = $this->actingAs($editor)
            ->post(route('diagrams.images'), ['image' => UploadedFile::fake()->image('chart.png', 640, 480)])
            ->assertOk()
            ->assertJsonPath('width', 640)
            ->assertJsonPath('height', 480);

        $url = $response->json('url');
        $this->assertStringStartsWith('/serve-storage/diagram-images/', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/serve-storage/')));

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($editor)->postJson(route('diagrams.images'), ['image' => $svg])->assertStatus(422);

        $this->actingAs($viewer)
            ->post(route('diagrams.images'), ['image' => UploadedFile::fake()->image('chart.png')])
            ->assertForbidden();
    }

    private function user(Department $department, array $permissions): User
    {
        $user = User::factory()->create(['department_id' => $department->id]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    private function diagram(User $owner, string $title, string $visibility): Diagram
    {
        return Diagram::create([
            'title' => $title,
            'visibility' => $visibility,
            'department_id' => $owner->department_id,
            'scene' => ['version' => 1, 'pages' => [['id' => 'page-1', 'name' => 'Page 1', 'background' => '#ffffff', 'grid' => true, 'elements' => []]]],
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
                'background' => '#ffffff',
                'grid' => true,
                'elements' => [['id' => 'n1', 'type' => 'node', 'shape' => 'process', 'x' => 0, 'y' => 0, 'w' => 120, 'h' => 60, 'text' => $text]],
            ]]],
        ];
    }
}
