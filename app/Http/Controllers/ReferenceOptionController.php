<?php

namespace App\Http\Controllers;

use App\Models\ProjectTemplate;
use App\Models\ReferenceOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReferenceOptionController extends Controller implements HasMiddleware
{
    private const ALLOWED_TYPES = ['project_type', 'store_class'];

    public static function middleware(): array
    {
        return [
            new Middleware('can:reference_options.create', only: ['store']),
            new Middleware('can:reference_options.edit',   only: ['update']),
            new Middleware('can:reference_options.delete', only: ['destroy']),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'       => ['required', 'string', 'in:' . implode(',', self::ALLOWED_TYPES)],
            'value'      => ['required', 'string', 'max:100'],
            'label'      => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['value'] = trim($validated['value']);
        $validated['label'] = trim($validated['label']);

        if (ReferenceOption::where('type', $validated['type'])->where('value', $validated['value'])->exists()) {
            return response()->json(['message' => 'This option already exists.'], 422);
        }

        $option = ReferenceOption::create([
            'type'       => $validated['type'],
            'value'      => $validated['value'],
            'label'      => $validated['label'],
            'sort_order' => $validated['sort_order'] ?? ReferenceOption::where('type', $validated['type'])->max('sort_order') + 1,
        ]);

        return response()->json($option, 201);
    }

    public function update(Request $request, ReferenceOption $referenceOption): JsonResponse
    {
        $validated = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['label'] = trim($validated['label']);

        $referenceOption->update($validated);

        return response()->json($referenceOption);
    }

    public function destroy(ReferenceOption $referenceOption): JsonResponse
    {
        $inUse = ProjectTemplate::where($referenceOption->type, $referenceOption->value)->exists();

        if ($inUse) {
            return response()->json([
                'message' => "Cannot delete \"{$referenceOption->label}\" — it is used by one or more project templates.",
            ], 422);
        }

        $referenceOption->delete();

        return response()->json(['message' => 'Deleted successfully.']);
    }
}
