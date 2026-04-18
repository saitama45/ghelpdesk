<?php

namespace App\Http\Controllers;

use App\Models\ActivityTemplate;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

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

        $subUnits = User::whereNotNull('sub_unit')
            ->where('sub_unit', '!=', '')
            ->distinct()
            ->pluck('sub_unit')
            ->sort()
            ->values();

        return Inertia::render('ActivityTemplates/Index', [
            'templates' => $templates,
            'subUnits' => $subUnits,
            'filters' => $request->only(['search', 'store_class']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'project_type' => 'required|string|in:NSO,Store Closure,Store Renovation',
            'store_class' => 'required|in:Regular,Kitchen,Both,Office',
            'activities' => 'required|array|min:1',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            $projectTemplate = ProjectTemplate::create([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            foreach ($validated['activities'] as $activityData) {
                $projectTemplate->activities()->create($activityData);
            }
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
            'project_type' => 'required|string|in:NSO,Store Closure,Store Renovation',
            'store_class' => 'required|in:Regular,Kitchen,Both,Office',
            'activities' => 'required|array|min:1',
            'activities.*.id' => 'nullable|exists:activity_templates,id',
            'activities.*.activity' => 'required|string|max:255',
            'activities.*.milestone' => 'nullable|string|max:255',
            'activities.*.asset_item' => 'nullable|string|max:255',
            'activities.*.model_specs' => 'nullable|string|max:255',
            'activities.*.qty' => 'required|integer|min:1',
            'activities.*.responsible' => 'nullable|string|max:255',
            'activities.*.default_duration_days' => 'required|integer|min:1',
            'activities.*.order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $activity_template) {
            $activity_template->update([
                'name' => $validated['name'],
                'project_type' => $validated['project_type'],
                'store_class' => $validated['store_class'],
            ]);

            $activityIds = collect($validated['activities'])->pluck('id')->filter()->all();
            $activity_template->activities()->whereNotIn('id', $activityIds)->delete();

            foreach ($validated['activities'] as $activityData) {
                if (isset($activityData['id'])) {
                    $id = $activityData['id'];
                    unset($activityData['id']);
                    ActivityTemplate::where('id', $id)->update($activityData);
                } else {
                    $activity_template->activities()->create($activityData);
                }
            }
        });

        return redirect()->back()->with('success', 'Project template updated successfully');
    }

    public function destroy(ProjectTemplate $activity_template)
    {
        $activity_template->delete();
        return redirect()->back()->with('success', 'Project template deleted successfully');
    }
}
