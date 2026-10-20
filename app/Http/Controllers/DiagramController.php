<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesSharedCanvas;
use App\Models\Diagram;
use App\Support\SceneSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diagrams: a draw.io-style editor for flowcharts and diagrams (nodes + connectors on pages).
 *
 * Routes are gated by diagrams.{view,create,edit,delete}; every route taking a diagram also
 * runs EnsureCanvasVisible. List/save/share behaviour is shared with standalone whiteboards
 * ({@see ManagesSharedCanvas}); this class adds the element whitelist and image uploads.
 */
class DiagramController extends Controller
{
    use ManagesSharedCanvas;

    private const PORTS = 'n|e|s|w';

    private const ARROWS = 'none|arrow|open|block|diamond|circle';

    /** Allowed fields per element type; anything else the client sends is dropped. */
    private const ELEMENT_FIELDS = [
        'node' => [
            'shape' => 'slug:rectangle',
            'x' => 'number', 'y' => 'number', 'w' => 'number:120', 'h' => 'number:60',
            'rotation' => 'number',
            'text' => 'text',
            'fill' => 'optional_color', 'stroke' => 'optional_color', 'strokeWidth' => 'number:1.5',
            'dashed' => 'bool', 'rounded' => 'bool', 'opacity' => 'number:1',
            'fontSize' => 'number:14', 'fontColor' => 'color:#1f2937', 'bold' => 'bool', 'italic' => 'bool',
            'align' => 'enum:center|left|right', 'valign' => 'enum:middle|top|bottom',
            'src' => 'storage_path',
            'groupId' => 'ref',
        ],
        'edge' => [
            'source' => 'ref', 'sourcePort' => 'optional_enum:'.self::PORTS,
            'target' => 'ref', 'targetPort' => 'optional_enum:'.self::PORTS,
            'x1' => 'number', 'y1' => 'number', 'x2' => 'number', 'y2' => 'number',
            'points' => 'points',
            'routing' => 'enum:orthogonal|straight|curved',
            'label' => 'text',
            'stroke' => 'color:#1f2937', 'strokeWidth' => 'number:1.5', 'dashed' => 'bool',
            'startArrow' => 'enum:'.self::ARROWS, 'endArrow' => 'enum:arrow|none|open|block|diamond|circle',
            'fontSize' => 'number:12', 'fontColor' => 'color:#1f2937',
            'groupId' => 'ref',
        ],
    ];

    public function index(Request $request)
    {
        return $this->canvasIndex($request);
    }

    public function store(Request $request)
    {
        return $this->canvasStore($request);
    }

    public function show(Request $request, Diagram $diagram)
    {
        return $this->canvasShow($request, $diagram);
    }

    public function state(Request $request, Diagram $lightDiagram): JsonResponse
    {
        return $this->canvasState($request, $lightDiagram);
    }

    public function saveScene(Request $request, Diagram $lightDiagram): JsonResponse
    {
        return $this->canvasSaveScene($request, $lightDiagram);
    }

    public function update(Request $request, Diagram $lightDiagram)
    {
        return $this->canvasUpdate($request, $lightDiagram);
    }

    public function duplicate(Request $request, Diagram $diagram)
    {
        return $this->canvasDuplicate($request, $diagram);
    }

    public function destroy(Request $request, Diagram $lightDiagram)
    {
        return $this->canvasDestroy($request, $lightDiagram);
    }

    /**
     * Picture for an image shape (inserted, pasted, dropped, or imported from a .drawio file).
     * Raster formats only: an SVG opened directly from /serve-storage could run script.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('diagrams.edit') || $user->can('diagrams.create'), 403);

        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:10240',
        ]);

        $file = $request->file('image');
        $path = $file->store('diagram-images/'.now()->format('Y/m'), 'public');
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [0, 0];

        return response()->json([
            'url' => '/serve-storage/'.$path,
            'width' => $width,
            'height' => $height,
        ]);
    }

    protected function canvasConfig(): array
    {
        return [
            'model' => Diagram::class,
            'permission' => 'diagrams',
            'noun' => 'diagram',
            'singular' => 'diagram',
            'plural' => 'diagrams',
            'pages' => ['index' => 'Diagrams/Index', 'show' => 'Diagrams/Show'],
            'routes' => 'diagrams',
            'max_scene_bytes' => 3_000_000,
        ];
    }

    protected function canvasSanitizer(): SceneSanitizer
    {
        return new SceneSanitizer(
            elementFields: self::ELEMENT_FIELDS,
            pageFields: ['name' => 'name', 'background' => 'color:#ffffff', 'grid' => 'bool:true'],
            maxPages: 30,
            maxElementsPerPage: 3000,
            maxPointValues: 400,
        );
    }
}
