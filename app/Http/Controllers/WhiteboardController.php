<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesSharedCanvas;
use App\Models\Whiteboard;
use App\Support\SceneSanitizer;
use App\Support\WhiteboardScene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Standalone whiteboards (/whiteboards): freehand ink, shapes, text and sticky notes on pages,
 * shared by visibility like diagrams. Gated by whiteboards.{view,create,edit,delete}; every
 * route taking a whiteboard also runs EnsureCanvasVisible.
 *
 * The task-board whiteboard is separate (TaskBoardWhiteboardController): one per board,
 * governed by board membership, and it can hold snapshots of the board's cards.
 */
class WhiteboardController extends Controller
{
    use ManagesSharedCanvas;

    public function index(Request $request)
    {
        return $this->canvasIndex($request);
    }

    public function store(Request $request)
    {
        return $this->canvasStore($request);
    }

    public function show(Request $request, Whiteboard $whiteboard)
    {
        return $this->canvasShow($request, $whiteboard);
    }

    public function state(Request $request, Whiteboard $lightWhiteboard): JsonResponse
    {
        return $this->canvasState($request, $lightWhiteboard);
    }

    public function saveScene(Request $request, Whiteboard $lightWhiteboard): JsonResponse
    {
        return $this->canvasSaveScene($request, $lightWhiteboard);
    }

    public function update(Request $request, Whiteboard $lightWhiteboard)
    {
        return $this->canvasUpdate($request, $lightWhiteboard);
    }

    public function duplicate(Request $request, Whiteboard $whiteboard)
    {
        return $this->canvasDuplicate($request, $whiteboard);
    }

    public function destroy(Request $request, Whiteboard $lightWhiteboard)
    {
        return $this->canvasDestroy($request, $lightWhiteboard);
    }

    protected function canvasConfig(): array
    {
        return [
            'model' => Whiteboard::class,
            'permission' => 'whiteboards',
            'noun' => 'whiteboard',
            'singular' => 'whiteboard',
            'plural' => 'whiteboards',
            'pages' => ['index' => 'Whiteboards/Index', 'show' => 'Whiteboards/Show'],
            'routes' => 'whiteboards',
            'max_scene_bytes' => 4_000_000,
        ];
    }

    protected function canvasSanitizer(): SceneSanitizer
    {
        return WhiteboardScene::sanitizer(withCards: false);
    }
}
