<?php

namespace Tests\Feature;

use App\Models\CanvasAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Picture library behind the whiteboard Elements panel. */
class CanvasAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_editors_upload_pictures_and_everyone_sees_shared_ones_only(): void
    {
        $alice = $this->user(['whiteboards.view', 'whiteboards.edit']);
        $bob = $this->user(['whiteboards.view', 'whiteboards.edit']);

        $shared = $this->actingAs($alice)
            ->post(route('canvas-assets.store'), ['image' => UploadedFile::fake()->image('Dome camera.png', 400, 300)])
            ->assertCreated()
            ->assertJsonPath('asset.name', 'Dome camera')
            ->assertJsonPath('asset.shared', true)
            ->assertJsonPath('asset.width', 400)
            ->json('asset');
        $this->assertStringStartsWith('/serve-storage/canvas-assets/', $shared['url']);
        Storage::disk('public')->assertExists(substr($shared['url'], strlen('/serve-storage/')));

        $this->actingAs($alice)
            ->post(route('canvas-assets.store'), ['image' => UploadedFile::fake()->image('screenshot.png'), 'shared' => '0'])
            ->assertCreated()
            ->assertJsonPath('asset.shared', false);

        $this->actingAs($alice)->getJson(route('canvas-assets.index'))->assertJsonCount(2, 'assets');
        $this->actingAs($bob)->getJson(route('canvas-assets.index'))
            ->assertJsonCount(1, 'assets')
            ->assertJsonPath('assets.0.name', 'Dome camera')
            ->assertJsonPath('assets.0.can_manage', false);
    }

    public function test_svg_is_refused_and_viewers_cannot_upload(): void
    {
        $editor = $this->user(['whiteboards.view', 'whiteboards.edit']);
        $viewer = $this->user(['whiteboards.view']);
        $outsider = $this->user([]);

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($editor)->postJson(route('canvas-assets.store'), ['image' => $svg])->assertStatus(422);

        $this->actingAs($viewer)->getJson(route('canvas-assets.index'))->assertOk();
        $this->actingAs($viewer)->post(route('canvas-assets.store'), ['image' => UploadedFile::fake()->image('a.png')])->assertForbidden();
        $this->actingAs($outsider)->getJson(route('canvas-assets.index'))->assertForbidden();

        $this->assertSame(0, CanvasAsset::count());
    }

    public function test_task_board_editors_can_use_the_library_too(): void
    {
        $boardEditor = $this->user(['task_boards.view', 'task_boards.edit']);

        $this->actingAs($boardEditor)
            ->post(route('canvas-assets.store'), ['image' => UploadedFile::fake()->image('router.png')])
            ->assertCreated();
    }

    public function test_only_the_uploader_renames_or_removes_a_picture(): void
    {
        $owner = $this->user(['whiteboards.view', 'whiteboards.edit']);
        $other = $this->user(['whiteboards.view', 'whiteboards.edit']);
        $id = $this->actingAs($owner)
            ->post(route('canvas-assets.store'), ['image' => UploadedFile::fake()->image('cam.png')])
            ->json('asset.id');
        $path = CanvasAsset::findOrFail($id)->path;

        $this->actingAs($other)->putJson(route('canvas-assets.update', $id), ['name' => 'Hijack'])->assertForbidden();
        $this->actingAs($other)->deleteJson(route('canvas-assets.destroy', $id))->assertForbidden();

        $this->actingAs($owner)->putJson(route('canvas-assets.update', $id), ['name' => 'CCTV bullet', 'category' => 'CCTV'])
            ->assertOk()
            ->assertJsonPath('asset.name', 'CCTV bullet');
        $this->actingAs($owner)->deleteJson(route('canvas-assets.destroy', $id))->assertOk();

        $this->assertNull(CanvasAsset::find($id));
        // Boards that already use the picture keep working: the file stays.
        Storage::disk('public')->assertExists($path);
    }

    private function user(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}
