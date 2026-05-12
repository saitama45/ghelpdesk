<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\User;
use App\Services\OrganizationReferenceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DepartmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:departments.view', only: ['index']),
            new Middleware('can:departments.create', only: ['store', 'storeNode', 'storeVacant']),
            new Middleware('can:departments.edit', only: ['update', 'updateNode', 'updateUserPlacement', 'updateVacant', 'reorderStructure', 'reorderUsers']),
            new Middleware('can:departments.delete', only: ['destroy', 'destroyNode', 'destroyVacant']),
        ];
    }

    public function __construct(private OrganizationReferenceService $organizationReferences)
    {
    }

    public function index()
    {
        $users = User::with(['roles:id,name', 'managers:id,name'])
            ->orderBy('org_sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
                'position',
                'department',
                'department_id',
                'department_node_id',
                'org_path',
                'is_active',
                'is_manager',
                'is_vacant',
                'profile_photo',
                'org_sort_order',
            ]);

        return Inertia::render('Departments/Index', [
            'departments' => $this->organizationReferences->tree(),
            'activeDepartments' => $this->organizationReferences->tree(activeOnly: true),
            'users' => $users,
            'authUserDepartmentId' => auth()->user()->department_id,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        Department::create([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Department created successfully.');
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $department->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        if ($department->nodes()->exists() || User::where('department_id', $department->id)->exists()) {
            throw ValidationException::withMessages([
                'department' => 'Remove child nodes and assigned users before deleting this department.',
            ]);
        }

        $department->delete();

        return redirect()->back()->with('success', 'Department deleted successfully.');
    }

    public function storeNode(Request $request, Department $department)
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:department_nodes,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_nodes', 'name')
                    ->where(fn ($query) => $query->where('department_id', $department->id)->where('parent_id', $request->parent_id)),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $department->allNodes()->create([
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Hierarchy node created successfully.');
    }

    public function updateNode(Request $request, DepartmentNode $node)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_nodes', 'name')
                    ->where(fn ($query) => $query->where('department_id', $node->department_id)->where('parent_id', $node->parent_id))
                    ->ignore($node->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $node->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Hierarchy node updated successfully.');
    }

    public function destroyNode(DepartmentNode $node)
    {
        if ($node->children()->exists() || $node->users()->exists()) {
            throw ValidationException::withMessages([
                'node' => 'Remove child nodes and assigned users before deleting this node.',
            ]);
        }

        $node->delete();

        return redirect()->back()->with('success', 'Hierarchy node deleted successfully.');
    }

    public function updateUserPlacement(Request $request, User $user)
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_node_id' => ['nullable', 'integer', 'exists:department_nodes,id'],
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => ['integer', 'exists:users,id'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'org_sort_order' => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($managerIds->contains((int) $user->id)) {
            throw ValidationException::withMessages([
                'manager_ids' => 'A user cannot report to themselves.',
            ]);
        }

        DB::transaction(function () use ($user, $validated, $managerIds, $request) {
            if ($request->hasFile('profile_photo')) {
                if ($user->profile_photo) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_photo);
                }
                $path = $request->file('profile_photo')->store('profile-photos', 'public');
                $user->profile_photo = $path;
                $user->save();
            }

            if (!$validated['department_id'] && !$validated['department_node_id']) {
                $user->forceFill([
                    ...$this->organizationReferences->clearPayload(),
                    'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                    'updated_by' => auth()->id(),
                ])->save();
            } else {
                $payload = $this->organizationReferences->payloadFromNodeId(
                    $validated['department_node_id'] ? (int) $validated['department_node_id'] : null
                );

                // Fallback to department level if no node is selected but department is
                if (!$validated['department_node_id'] && $validated['department_id']) {
                    $dept = Department::find($validated['department_id']);
                    $payload['department'] = $dept->name;
                    $payload['department_id'] = $dept->id;
                }

                $user->forceFill([
                    ...$payload,
                    'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                    'updated_by' => auth()->id(),
                ])->save();
            }

            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'User placement updated successfully.');
    }

    public function storeVacant(Request $request)
    {
        $validated = $request->validate([
            'title'              => ['nullable', 'string', 'max:255'],
            'department_id'      => ['nullable', 'integer', 'exists:departments,id'],
            'department_node_id' => ['nullable', 'integer', 'exists:department_nodes,id'],
            'manager_ids'        => ['nullable', 'array'],
            'manager_ids.*'      => ['integer', 'exists:users,id'],
            'org_sort_order'     => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $payload = $this->organizationReferences->payloadFromNodeId(
            $validated['department_node_id'] ? (int) $validated['department_node_id'] : null
        );

        if (!$validated['department_node_id'] && $validated['department_id']) {
            $dept = Department::find($validated['department_id']);
            $payload['department'] = $dept->name;
            $payload['department_id'] = $dept->id;
        }

        DB::transaction(function () use ($validated, $payload, $managerIds) {
            $title = filled($validated['title'] ?? null) ? $validated['title'] : 'Vacant Position';
            $user = User::create([
                'name'       => $title,
                'email'      => 'vacant.' . uniqid() . '@placeholder.internal',
                'password'   => bcrypt(str()->random(32)),
                'is_vacant'  => true,
                'is_active'  => true,
                'is_manager' => false,
                'position'   => $title,
                ...$payload,
                'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                'created_by' => auth()->id(),
            ]);
            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'Vacant position added to org chart.');
    }

    public function updateVacant(Request $request, User $user)
    {
        abort_if(! $user->is_vacant, 403);

        $validated = $request->validate([
            'title'              => ['nullable', 'string', 'max:255'],
            'department_id'      => ['nullable', 'integer', 'exists:departments,id'],
            'department_node_id' => ['nullable', 'integer', 'exists:department_nodes,id'],
            'manager_ids'        => ['nullable', 'array'],
            'manager_ids.*'      => ['integer', 'exists:users,id'],
            'org_sort_order'     => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $payload = $this->organizationReferences->payloadFromNodeId(
            $validated['department_node_id'] ? (int) $validated['department_node_id'] : null
        );

        if (!$validated['department_node_id'] && $validated['department_id']) {
            $dept = Department::find($validated['department_id']);
            $payload['department'] = $dept->name;
            $payload['department_id'] = $dept->id;
        }

        DB::transaction(function () use ($user, $validated, $payload, $managerIds) {
            $title = filled($validated['title'] ?? null) ? $validated['title'] : 'Vacant Position';
            $user->forceFill([
                'name'     => $title,
                'position' => $title,
                ...$payload,
                'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                'updated_by' => auth()->id(),
            ])->save();
            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'Vacant position updated.');
    }

    public function destroyVacant(User $user)
    {
        abort_if(! $user->is_vacant, 403);
        $user->managers()->detach();
        $user->delete();
        return redirect()->back()->with('success', 'Vacant position removed from org chart.');
    }

    public function reorderUsers(Request $request)
    {
        $validated = $request->validate([
            'users' => ['required', 'array'],
            'users.*.id' => ['required', 'integer', 'exists:users,id'],
            'users.*.org_sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['users'] as $u) {
                User::where('id', $u['id'])->update(['org_sort_order' => $u['org_sort_order']]);
            }
        });

        return redirect()->back()->with('success', 'Organisation chart order updated.');
    }

    public function reorderStructure(Request $request)
    {
        $validated = $request->validate([
            'items'             => ['required', 'array'],
            'items.*.id'        => ['required', 'integer', 'exists:department_nodes,id'],
            'items.*.sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $item) {
                DepartmentNode::where('id', (int) $item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        return redirect()->back()->with('success', 'Structure order updated.');
    }
}
