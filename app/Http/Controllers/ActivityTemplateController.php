<?php

namespace App\Http\Controllers;

use App\Models\ActivityTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
        $query = ActivityTemplate::query();

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('category', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('store_class')) {
            $query->whereIn('store_class', [$request->store_class, 'Both']);
        }

        $templates = $query->orderBy('store_class')
            ->orderBy('order')
            ->paginate($request->get('per_page', 15))
            ->withQueryString();

        return Inertia::render('ActivityTemplates/Index', [
            'templates' => $templates,
            'filters' => $request->only(['search', 'store_class']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_class' => 'required|in:Regular,Kitchen,Both',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'default_duration_days' => 'required|integer|min:1',
            'order' => 'required|integer|min:0',
        ]);

        ActivityTemplate::create($validated);

        return redirect()->back()->with('success', 'Activity template created successfully');
    }

    public function update(Request $request, ActivityTemplate $activity_template)
    {
        $validated = $request->validate([
            'store_class' => 'required|in:Regular,Kitchen,Both',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'default_duration_days' => 'required|integer|min:1',
            'order' => 'required|integer|min:0',
        ]);

        $activity_template->update($validated);

        return redirect()->back()->with('success', 'Activity template updated successfully');
    }

    public function destroy(ActivityTemplate $activity_template)
    {
        $activity_template->delete();
        return redirect()->back()->with('success', 'Activity template deleted successfully');
    }
}
