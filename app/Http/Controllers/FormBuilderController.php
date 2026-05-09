<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\RequestType;
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
        $query = FormDefinition::query()->with('requestTypes');
        
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
            'requestTypes' => RequestType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'approval_levels', 'approver_matrix', 'form_schema']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'request_type_ids' => 'nullable|array',
            'request_type_ids.*' => 'exists:request_types,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'workflow_type' => 'required|string|in:approval,checklist',
            'icon' => 'nullable|string|max:50',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'cc_emails' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $formDefinition = FormDefinition::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'workflow_type' => $request->workflow_type,
            'icon' => $request->icon ?? 'DocumentTextIcon',
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $request->approver_matrix,
            'cc_emails' => $request->cc_emails,
            'is_active' => $request->boolean('is_active', true),
            'form_schema' => [
                'fields' => [],
                'approver_fields' => [],
                'items_columns' => [],
                'items_template_source' => null,
                'items_templates' => [],
                'has_items' => false
            ],
        ]);

        if ($request->has('request_type_ids')) {
            $formDefinition->requestTypes()->sync($request->request_type_ids);
        }

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition created successfully');
    }

    public function update(Request $request, FormDefinition $form_builder)
    {
        $request->validate([
            'request_type_ids' => 'nullable|array',
            'request_type_ids.*' => 'exists:request_types,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'workflow_type' => 'required|string|in:approval,checklist',
            'icon' => 'nullable|string|max:50',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'cc_emails' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $form_builder->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'workflow_type' => $request->workflow_type,
            'icon' => $request->icon ?? 'DocumentTextIcon',
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $request->approver_matrix,
            'cc_emails' => $request->cc_emails,
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($request->has('request_type_ids')) {
            $form_builder->requestTypes()->sync($request->request_type_ids);
        }

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition updated successfully');
    }

    public function updateSchema(Request $request, FormDefinition $form_builder)
    {
        $request->validate([
            'form_schema' => 'required|array',
        ]);

        $form_builder->update([
            'form_schema' => $request->form_schema,
        ]);

        Cache::forget('active_form_definitions');

        return redirect()->back()->with('success', 'Form Schema updated successfully');
    }

    public function destroy(FormDefinition $form_builder)
    {
        $form_builder->delete();

        Cache::forget('active_form_definitions');
        Cache::increment('permissions_version');

        return redirect()->back()->with('success', 'Form Definition deleted successfully');
    }
}
