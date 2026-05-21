<?php

namespace App\Http\Controllers;

use App\Models\ActivityTemplate;
use App\Models\ProjectTemplate;
use App\Models\ReferenceOption;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityTemplateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:activity_templates.view', only: ['index']),
            new Middleware('can:activity_templates.create', only: ['store']),
            new Middleware('can:activity_templates.edit', only: ['update']),
            new Middleware('can:activity_templates.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = ProjectTemplate::with(['activities']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->filled('store_class')) {
            $query->whereIn('store_class', [$request->store_class, 'Both']);
        }

        $templates = $query->orderBy('name')
            ->paginate($request->get('per_page', 15))
            ->withQueryString();

        $subUnits = User::whereNotNull('org_path')
            ->where('org_path', '!=', '')
            ->distinct()
            ->pluck('org_path')
            ->sort()
            ->values();

        return Inertia::render('ActivityTemplates/Index', [
            'templates' => $templates,
            'subUnits' => $subUnits,
            'departmentOptions' => $this->departmentOptions(),
            'projectTypes' => ReferenceOption::ofType('project_type'),
            'storeClasses' => ReferenceOption::ofType('store_class'),
            'filters' => $request->only(['search', 'store_class']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|string|max:100',
            'store_class' => 'required|string|max:100',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            $projectTemplate = ProjectTemplate::create([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            $this->persistActivities($projectTemplate, $validated['activities']);
        });

        return redirect()->back()->with('success', 'Project template created successfully');
    }

    public function update(Request $request, ProjectTemplate $activity_template)
    {
        // Renamed parameter to match existing route binding if necessary, 
        // but typically Laravel uses the variable name from the route.
        // Assuming the route parameter is {activity_template}
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|string|max:100',
            'store_class' => 'required|string|max:100',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.client_key' => 'nullable|string|max:255',
            'activities.*.parent_client_key' => 'nullable|string|max:255',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.department' => 'nullable|string|max:255',
            'activities.*.sub_unit' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $activity_template) {
            $activity_template->update([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            $this->persistActivities($activity_template, $validated['activities']);
        });

        return redirect()->back()->with('success', 'Project template updated successfully');
    }

    public function destroy(ProjectTemplate $activity_template)
    {
        $activity_template->delete();
        return redirect()->back()->with('success', 'Project template deleted successfully');
    }

    private function persistActivities(ProjectTemplate $projectTemplate, array $activities): void
    {
        $activities = collect($activities)
            ->values()
            ->map(function (array $activity, int $index) {
                $activity['client_key'] = filled($activity['client_key'] ?? null)
                    ? (string) $activity['client_key']
                    : 'activity-' . $index;
                $activity['parent_client_key'] = filled($activity['parent_client_key'] ?? null)
                    ? (string) $activity['parent_client_key']
                    : null;

                return $activity;
            });

        $this->validateActivityHierarchy($projectTemplate, $activities);

        $submittedIds = $activities->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $existingIds = $projectTemplate->activities()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $removedIds = array_values(array_diff($existingIds, $submittedIds));

        if (!empty($removedIds)) {
            $projectTemplate->activities()
                ->whereIn('id', $removedIds)
                ->whereNotNull('parent_activity_template_id')
                ->delete();

            $projectTemplate->activities()
                ->whereIn('id', $removedIds)
                ->whereNull('parent_activity_template_id')
                ->delete();
        }

        $savedByClientKey = [];

        foreach ($activities->filter(fn ($activity) => empty($activity['parent_client_key']))->sortBy('order') as $activity) {
            $model = $this->saveActivity($projectTemplate, $activity, null);
            $savedByClientKey[$activity['client_key']] = $model;
        }

        foreach ($activities->filter(fn ($activity) => !empty($activity['parent_client_key']))->sortBy('order') as $activity) {
            $parent = $savedByClientKey[$activity['parent_client_key']] ?? null;

            if (!$parent) {
                throw ValidationException::withMessages([
                    'activities' => 'Each sub-task must belong to an activity in the same template.',
                ]);
            }

            $activity['department'] = blank($activity['department'] ?? null) ? $parent->department : $activity['department'];
            $activity['sub_unit'] = blank($activity['sub_unit'] ?? null) ? $parent->sub_unit : $activity['sub_unit'];

            $model = $this->saveActivity($projectTemplate, $activity, $parent->id);
            $savedByClientKey[$activity['client_key']] = $model;
        }
    }

    private function validateActivityHierarchy(ProjectTemplate $projectTemplate, $activities): void
    {
        $existingIds = $projectTemplate->exists
            ? $projectTemplate->activities()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $clientKeys = $activities->pluck('client_key')->all();
        $uniqueClientKeys = array_unique($clientKeys);

        if (count($clientKeys) !== count($uniqueClientKeys)) {
            throw ValidationException::withMessages([
                'activities' => 'Activity rows must have unique client keys.',
            ]);
        }

        $byClientKey = $activities->keyBy('client_key');

        foreach ($activities as $activity) {
            if (!empty($activity['id']) && !in_array((int) $activity['id'], $existingIds, true)) {
                throw ValidationException::withMessages([
                    'activities' => 'One or more activities do not belong to this template.',
                ]);
            }

            $parentClientKey = $activity['parent_client_key'] ?? null;

            if (!$parentClientKey) {
                continue;
            }

            if ($parentClientKey === $activity['client_key'] || !$byClientKey->has($parentClientKey)) {
                throw ValidationException::withMessages([
                    'activities' => 'Each sub-task must belong to an activity in the same template.',
                ]);
            }

            $parent = $byClientKey[$parentClientKey];

            if (!empty($parent['parent_client_key'])) {
                throw ValidationException::withMessages([
                    'activities' => 'Only one sub-task level is supported.',
                ]);
            }
        }
    }

    private function saveActivity(ProjectTemplate $projectTemplate, array $activity, ?int $parentActivityId): ActivityTemplate
    {
        $attributes = [
            'parent_activity_template_id' => $parentActivityId,
            'activity' => $activity['activity'],
            'milestone' => $activity['milestone'] ?? null,
            'asset_item' => $activity['asset_item'] ?? null,
            'model_specs' => $activity['model_specs'] ?? null,
            'qty' => $activity['qty'],
            'responsible' => $activity['responsible'] ?? null,
            'department' => blank($activity['department'] ?? null) ? null : $activity['department'],
            'sub_unit' => blank($activity['sub_unit'] ?? null) ? null : $activity['sub_unit'],
            'default_duration_days' => $activity['default_duration_days'],
            'order' => $activity['order'],
        ];

        if (!empty($activity['id'])) {
            $model = $projectTemplate->activities()->whereKey($activity['id'])->firstOrFail();
            $model->update($attributes);

            return $model;
        }

        return $projectTemplate->activities()->create($attributes);
    }

    private function departmentOptions(): array
    {
        $departments = \App\Models\Department::where('is_active', true)->orderBy('name')->get();
        $allNodes = \App\Models\DepartmentNode::where('is_active', true)->get();

        return $departments->map(function ($dept) use ($allNodes) {
            $deptNodes = $allNodes->where('department_id', $dept->id);
            
            $subUnits = $deptNodes->map(function ($node) use ($allNodes) {
                $pathParts = [];
                $current = $node;
                while ($current) {
                    array_unshift($pathParts, $current->name);
                    $parentId = $current->parent_id;
                    $current = $parentId ? $allNodes->firstWhere('id', $parentId) : null;
                }
                return implode(' > ', $pathParts);
            })->filter()->unique()->sort()->values()->all();

            return [
                'name' => $dept->name,
                'sub_units' => $subUnits,
            ];
        })->values()->all();
    }
}
