<?php

namespace App\Http\Controllers;

use App\Models\CanvasAsset;
use App\Models\User;
use App\Support\SharedCanvasAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Picture library behind the whiteboard Elements panel. Anyone who can open a whiteboard,
 * diagram or task board lists it; anyone who can edit one adds to it. Shared pictures are
 * listed for everyone, the rest for their uploader only. Raster formats only: an SVG
 * opened directly from /serve-storage could run script.
 */
class CanvasAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $assets = CanvasAsset::query()
            ->where(fn ($visible) => $visible->where('shared', true)->orWhere('created_by', $user->id))
            ->with('creator:id,name')
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(fn (CanvasAsset $asset) => $this->payload($asset, $user))
            ->values();

        return response()->json(['assets' => $assets]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:10240',
            'name' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:60',
            'shared' => 'sometimes|boolean',
        ]);

        $file = $request->file('image');
        $path = $file->store('canvas-assets/'.now()->format('Y/m'), 'public');
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [0, 0];
        $name = trim((string) ($validated['name'] ?? '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $asset = CanvasAsset::create([
            'name' => mb_substr($name ?: 'Picture', 0, 120),
            'category' => isset($validated['category']) ? mb_substr(trim($validated['category']), 0, 60) ?: null : null,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'width' => (int) $width,
            'height' => (int) $height,
            'size' => (int) $file->getSize(),
            'shared' => $request->boolean('shared', true),
            'created_by' => $user->id,
        ]);

        return response()->json(['asset' => $this->payload($asset->load('creator:id,name'), $user)], 201);
    }

    /** Rename, re-categorise or share/unshare — the uploader (or an admin) only. */
    public function update(Request $request, CanvasAsset $canvasAsset): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canManage($user, $canvasAsset), 403);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'category' => 'sometimes|nullable|string|max:60',
            'shared' => 'sometimes|boolean',
        ]);

        $canvasAsset->update($validated);

        return response()->json(['asset' => $this->payload($canvasAsset->load('creator:id,name'), $user)]);
    }

    /**
     * Take a picture out of the library — the uploader (or an admin) only. The file stays on
     * disk: boards already drawn with it keep pointing at it.
     */
    public function destroy(Request $request, CanvasAsset $canvasAsset): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $canvasAsset), 403);

        $canvasAsset->delete();

        return response()->json(['deleted' => true]);
    }

    private function canManage(User $user, CanvasAsset $asset): bool
    {
        return (int) $asset->created_by === (int) $user->id || SharedCanvasAccess::seesEverything($user);
    }

    private function payload(CanvasAsset $asset, User $user): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'category' => $asset->category,
            'url' => $asset->url,
            'width' => $asset->width,
            'height' => $asset->height,
            'shared' => $asset->shared,
            'creator' => $asset->creator?->name,
            'is_mine' => (int) $asset->created_by === (int) $user->id,
            'can_manage' => $this->canManage($user, $asset),
        ];
    }
}
