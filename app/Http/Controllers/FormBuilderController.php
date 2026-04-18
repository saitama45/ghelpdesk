<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;

class FormBuilderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:form_builder.view', only: ['index']),
            new Middleware('can:form_builder.create', only: ['store']),
            new Middleware('can:form_builder.edit', only: ['update', 'updateSchema']),
            new Middleware('can:form_builder.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = FormDefinition::query();
        
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }
        
        $forms = $query->paginate($request->get('per_page', 10))->withQueryString();
        
        return Inertia::render('FormBuilder/Index', [
            'forms' => $forms,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:50',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'cc_emails' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        FormDefinition::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'icon' => $request->icon ?? 'DocumentTextIcon',
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $request->approver_matrix,
            'cc_emails' => $request->cc_emails,
            'is_active' => $request->boolean('is_active', true),
            'form_schema' => [
                'fields' => [],
                'approver_fields' => [],
                'items_columns' => [],
                'has_items' => false
            ],
        ]);

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition created successfully');
    }

    public function update(Request $request, FormDefinition $formBuilder)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:50',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'cc_emails' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $formBuilder->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'icon' => $request->icon ?? 'DocumentTextIcon',
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $request->approver_matrix,
            'cc_emails' => $request->cc_emails,
            'is_active' => $request->boolean('is_active'),
        ]);

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition updated successfully');
    }

    public function updateSchema(Request $request, FormDefinition $formBuilder)
    {
        $request->validate([
            'form_schema' => 'required|array',
        ]);

        $formBuilder->update(['form_schema' => $request->form_schema]);

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form schema saved successfully');
    }

    public function destroy(FormDefinition $formBuilder)
    {
        $formBuilder->delete();

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition deleted successfully');
    }
}
