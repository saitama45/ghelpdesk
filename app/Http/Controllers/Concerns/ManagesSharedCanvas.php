<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\DepartmentContext;
use App\Support\SceneSanitizer;
use App\Support\SharedCanvasAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * List / create / open / poll / save / rename / duplicate / delete for a shared canvas
 * module (diagrams, standalone whiteboards). The using controller supplies
 * {@see canvasConfig()} and the scene whitelist ({@see canvasSanitizer()}).
 *
 * Saves are optimistic: the client sends the revision it started from; a stale save gets
 * 409 + the current scene so the client can merge its own edits on top and retry. Routes
 * that run often bind the record WITHOUT its scene/preview LOBs (light binding), and every
 * route taking a record runs EnsureCanvasVisible.
 */
trait ManagesSharedCanvas
{
    private const MAX_PREVIEW_BYTES = 60_000;

    /**
     * @return array{model: class-string<Model>, permission: string, noun: string, singular: string, plural: string, pages: array{index: string, show: string}, routes: string, max_scene_bytes: int}
     */
    abstract protected function canvasConfig(): array;

    abstract protected function canvasSanitizer(): SceneSanitizer;

    protected function canvasIndex(Request $request)
    {
        $config = $this->canvasConfig();
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'all';

        $records = SharedCanvasAccess::scopeVisible($config['model']::query(), $user)
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($scope === 'mine', fn ($query) => $query->where('created_by', $user->id))
            ->with(['creator:id,name', 'editor:id,name', 'department:id,name,code'])
            // Pinned columns: never pull the scene LOB for a list.
            ->select(['id', 'title', 'description', 'visibility', 'department_id', 'preview', 'page_count', 'created_by', 'updated_by', 'created_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Model $record) => $this->canvasSummary($record, $user));

        return Inertia::render($config['pages']['index'], [
            $config['plural'] => $records,
            'filters' => ['search' => $search, 'scope' => $scope],
            'can' => [
                'create' => $user->can("{$config['permission']}.create"),
                'edit' => $user->can("{$config['permission']}.edit"),
                'delete' => $user->can("{$config['permission']}.delete"),
            ],
        ]);
    }

    protected function canvasStore(Request $request)
    {
        $config = $this->canvasConfig();
        $user = $request->user();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'visibility' => ['required', Rule::in($config['model']::VISIBILITIES)],
            'scene' => 'nullable|array',
        ]);

        $scene = $this->canvasSanitizer()->sanitize($validated['scene'] ?? []);

        $record = $config['model']::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'visibility' => $validated['visibility'],
            'department_id' => DepartmentContext::homeDepartmentId($user),
            'scene' => $scene,
            'page_count' => count($scene['pages']),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->route("{$config['routes']}.show", $record)->with('success', ucfirst($config['noun']).' created.');
    }

    protected function canvasShow(Request $request, Model $record)
    {
        $config = $this->canvasConfig();
        $user = $request->user();
        $record->load(['creator:id,name', 'editor:id,name', 'department:id,name,code']);

        return Inertia::render($config['pages']['show'], [
            $config['singular'] => [
                ...$this->canvasSummary($record, $user),
                'revision' => $record->revision,
                'scene' => $record->scene,
            ],
            'can' => [
                'edit' => SharedCanvasAccess::canEdit($user, $record, $config['permission']),
                'manage' => SharedCanvasAccess::canManage($user, $record),
                'delete' => SharedCanvasAccess::canManage($user, $record) && $user->can("{$config['permission']}.delete"),
                'create' => $user->can("{$config['permission']}.create"),
            ],
        ]);
    }

    /**
     * Cheap poll for open editors: only the revision (plus title/visibility) unless the
     * revision moved past the one the client has, so an idle canvas never re-reads the scene.
     */
    protected function canvasState(Request $request, Model $light): JsonResponse
    {
        $known = (int) $request->query('revision', -1);
        $changed = $light->revision !== $known;

        $response = [
            'changed' => $changed,
            'revision' => $light->revision,
            'title' => $light->title,
            'visibility' => $light->visibility,
        ];

        if ($changed) {
            $response['scene'] = $light::query()->whereKey($light->getKey())->first(['id', 'scene'])?->scene;
            $response['updated_at'] = $light->updated_at;
            $response['updated_by'] = $light->editor()->value('name');
        }

        return response()->json($response);
    }

    protected function canvasSaveScene(Request $request, Model $light): JsonResponse
    {
        $config = $this->canvasConfig();
        $user = $request->user();
        abort_unless(SharedCanvasAccess::canEdit($user, $light, $config['permission']), 403);

        $validated = $request->validate([
            'revision' => 'required|integer|min:0',
            'scene' => 'required|array',
            'scene.pages' => 'required|array|min:1|max:30',
            'preview' => 'nullable|string',
        ]);

        $scene = $this->canvasSanitizer()->sanitize($validated['scene']);
        $encoded = json_encode($scene, JSON_UNESCAPED_UNICODE);

        if ($encoded === false || strlen($encoded) > $config['max_scene_bytes']) {
            return response()->json([
                'message' => 'This '.$config['noun'].' is too large to save. Split it across pages.',
            ], 422);
        }

        $base = (int) $validated['revision'];
        $changes = [
            'scene' => $encoded,
            'revision' => $base + 1,
            'page_count' => count($scene['pages']),
            'updated_by' => $user->id,
        ];

        if (array_key_exists('preview', $validated)) {
            $changes['preview'] = $this->canvasPreview($validated['preview']);
        }

        // Conditional write: only lands if nobody saved since the client's base revision.
        $updated = $light::query()->whereKey($light->getKey())->where('revision', $base)->update($changes);

        if ($updated === 0) {
            $current = $light::query()->whereKey($light->getKey())->first(['id', 'revision', 'scene', 'updated_by']);

            return response()->json([
                'message' => 'Someone else saved this '.$config['noun'].'. Your changes were merged with theirs.',
                'revision' => $current->revision,
                'scene' => $current->scene,
                'updated_by' => $current->editor()->value('name'),
            ], 409);
        }

        return response()->json([
            'revision' => $base + 1,
            'updated_at' => $light::query()->whereKey($light->getKey())->value('updated_at'),
            'updated_by' => $user->name,
        ]);
    }

    /** Title and description (editors); visibility (owner or admin only). */
    protected function canvasUpdate(Request $request, Model $light)
    {
        $config = $this->canvasConfig();
        $user = $request->user();
        abort_unless(SharedCanvasAccess::canEdit($user, $light, $config['permission']), 403);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:500',
            'visibility' => ['sometimes', 'required', Rule::in($config['model']::VISIBILITIES)],
        ]);

        if (array_key_exists('visibility', $validated)
            && $validated['visibility'] !== $light->visibility
            && ! SharedCanvasAccess::canManage($user, $light)) {
            abort(403, 'Only the owner can change who sees this '.$config['noun'].'.');
        }

        // Plain attribute update: the revision belongs to scene saves only.
        $light->fill($validated);
        $light->updated_by = $user->id;
        $light->save();

        if ($request->wantsJson()) {
            return response()->json([
                'title' => $light->title,
                'description' => $light->description,
                'visibility' => $light->visibility,
            ]);
        }

        return redirect()->back()->with('success', ucfirst($config['noun']).' updated.');
    }

    protected function canvasDuplicate(Request $request, Model $record)
    {
        $config = $this->canvasConfig();
        $user = $request->user();

        $copy = $config['model']::create([
            'title' => mb_substr('Copy of '.$record->title, 0, 255),
            'description' => $record->description,
            'visibility' => $record->visibility,
            'department_id' => DepartmentContext::homeDepartmentId($user),
            'scene' => $record->scene,
            'preview' => $record->preview,
            'page_count' => $record->page_count,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $message = ucfirst($config['noun']).' duplicated.';

        return $request->boolean('open')
            ? redirect()->route("{$config['routes']}.show", $copy)->with('success', $message)
            : redirect()->back()->with('success', $message);
    }

    protected function canvasDestroy(Request $request, Model $light)
    {
        $config = $this->canvasConfig();
        abort_unless(SharedCanvasAccess::canManage($request->user(), $light), 403, 'Only the owner can delete this '.$config['noun'].'.');

        $light->delete();

        return redirect()->route("{$config['routes']}.index")->with('success', ucfirst($config['noun']).' deleted.');
    }

    protected function canvasSummary(Model $record, User $user): array
    {
        return [
            'id' => $record->id,
            'title' => $record->title,
            'description' => $record->description,
            'visibility' => $record->visibility,
            'department' => $record->department?->code ?: $record->department?->name,
            'preview' => $record->preview,
            'page_count' => $record->page_count,
            'creator' => $record->creator?->name,
            'editor' => $record->editor?->name,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
            'is_mine' => (int) $record->created_by === (int) $user->id,
            'can_manage' => SharedCanvasAccess::canManage($user, $record),
        ];
    }

    /** List-card thumbnail: an SVG shown through <img> (scripts never run there), size-capped. */
    private function canvasPreview(?string $svg): ?string
    {
        if ($svg === null || strlen($svg) > self::MAX_PREVIEW_BYTES || ! str_starts_with(ltrim($svg), '<svg')) {
            return null;
        }

        return $svg;
    }
}
