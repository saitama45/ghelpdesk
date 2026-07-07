<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ProjectTemplate;
use App\Models\ReferenceOption;
use App\Models\Store;
use App\Models\StoreOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReferenceOptionController extends Controller implements HasMiddleware
{
    private const ALLOWED_TYPES = [
        'project_type',
        'company_type',
        'store_class',
        'store_hookup',
        'store_system',
        'store_telco',
        'store_connectivity_type',
        'store_remote_app',
    ];

    // Maps store_options reference types to the store_options.type they populate.
    private const STORE_OPTION_TYPE_MAP = [
        'store_system' => 'system',
        'store_telco' => 'telco',
        'store_connectivity_type' => 'connectivity_type',
        'store_remote_app' => 'remote_app',
    ];

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
        [$inUse, $usedByLabel] = $this->dependencyUsage($referenceOption);

        if ($inUse) {
            return response()->json([
                'message' => "Cannot delete \"{$referenceOption->label}\" — it is used by one or more {$usedByLabel}.",
            ], 422);
        }

        $referenceOption->delete();

        return response()->json(['message' => 'Deleted successfully.']);
    }

    /**
     * Determine whether a reference option is currently referenced elsewhere.
     *
     * @return array{0: bool, 1: string}
     */
    private function dependencyUsage(ReferenceOption $referenceOption): array
    {
        $type = $referenceOption->type;
        $value = $referenceOption->value;

        if ($type === 'project_type') {
            return [ProjectTemplate::where('project_type', $value)->exists(), 'project templates'];
        }

        if ($type === 'company_type') {
            return [Company::where('type', $value)->exists(), 'companies'];
        }

        if ($type === 'store_class') {
            return [Store::where('class', $value)->exists(), 'stores'];
        }

        if ($type === 'store_hookup') {
            return [Store::where('hookup', $value)->exists(), 'stores'];
        }

        if (isset(self::STORE_OPTION_TYPE_MAP[$type])) {
            $storeOptionType = self::STORE_OPTION_TYPE_MAP[$type];
            return [
                StoreOption::where('type', $storeOptionType)->where('value', $value)->exists(),
                'stores',
            ];
        }

        return [false, 'records'];
    }
}
