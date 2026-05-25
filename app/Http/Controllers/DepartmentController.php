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
            new Middleware('can:departments.edit', only: ['update', 'updateNode', 'updateUserPlacement', 'destroyUserPlacement', 'updateVacant', 'destroyVacant', 'reorderStructure', 'reorderUsers']),
            new Middleware('can:departments.delete', only: ['destroy', 'destroyNode']),
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
            'authUserDepartmentId' => auth()->user()->department_id
                ?? optional(auth()->user()->load('departmentNode')->departmentNode)->department_id,
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
            'replace_user_id' => ['nullable', 'integer', 'exists:users,id'],
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

        $replaceUser = null;
        if ($request->filled('replace_user_id')) {
            $replaceUser = User::find($request->input('replace_user_id'));
        }

        DB::transaction(function () use ($user, $validated, $managerIds, $request, $replaceUser) {
            if ($replaceUser) {
                // Get all subordinates of the inactive user
                $subordinateIds = $replaceUser->subordinates()->pluck('users.id')->all();

                $replaceUser->forceFill([
                    ...$this->organizationReferences->clearPayload(),
                    'org_sort_order' => 0,
                    'updated_by' => auth()->id(),
                ])->save();

                // Detach managers and subordinates from the inactive user
                $replaceUser->managers()->detach();
                $replaceUser->subordinates()->detach();

                // Re-route those subordinates to the newly hired active user
                if (!empty($subordinateIds)) {
                    $user->subordinates()->syncWithoutDetaching($subordinateIds);
                }
            }

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

        DB::transaction(function () use ($user) {
            // Get subordinates and managers
            $subordinateIds = $user->subordinates()->pluck('users.id')->all();
            $managerIds = $user->managers()->pluck('users.id')->all();

            $user->managers()->detach();
            $user->subordinates()->detach();

            // Re-route subordinates to report to the vacant position's manager(s)
            if (!empty($subordinateIds) && !empty($managerIds)) {
                foreach ($subordinateIds as $subId) {
                    $subordinate = User::find($subId);
                    if ($subordinate) {
                        $subordinate->managers()->syncWithoutDetaching($managerIds);
                    }
                }
            }

            // --- COMPREHENSIVE DB CLEANUP (mirroring UserController@destroy to prevent foreign key errors) ---
            
            // Null out references in tickets
            DB::table('tickets')->where('reporter_id', $user->id)->update(['reporter_id' => null]);
            DB::table('tickets')->where('assignee_id', $user->id)->update(['assignee_id' => null]);

            // Null out references in ticket comments and history
            DB::table('ticket_comments')->where('user_id', $user->id)->update(['user_id' => null]);
            DB::table('ticket_histories')->where('user_id', $user->id)->update(['user_id' => null]);

            // Null out references in project tasks
            DB::table('project_tasks')->where('assigned_to', $user->id)->update(['assigned_to' => null]);
            DB::table('project_tasks')->where('support_by', $user->id)->update(['support_by' => null]);

            // Null out references in inventory transactions
            if (\Illuminate\Support\Facades\Schema::hasTable('inventory_transactions')) {
                DB::table('inventory_transactions')->where('created_by', $user->id)->update(['created_by' => null]);
                DB::table('inventory_transactions')->where('updated_by', $user->id)->update(['updated_by' => null]);
            }

            // Cleanup SAP and POS requests
            if (\Illuminate\Support\Facades\Schema::hasTable('sap_requests')) {
                DB::table('sap_requests')->where('user_id', $user->id)->update(['user_id' => null]);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('sap_request_approvals')) {
                DB::table('sap_request_approvals')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('pos_requests')) {
                DB::table('pos_requests')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('pos_request_approvals')) {
                DB::table('pos_request_approvals')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('schedule_change_requests')) {
                DB::table('schedule_change_requests')->where('requester_id', $user->id)->delete();
                DB::table('schedule_change_requests')->where('approved_by', $user->id)->update(['approved_by' => null]);
                DB::table('schedule_change_requests')->where('rejected_by', $user->id)->update(['rejected_by' => null]);
            }

            // Cleanup attendance and schedules
            if (\Illuminate\Support\Facades\Schema::hasTable('attendance_logs')) {
                DB::table('attendance_logs')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('schedules')) {
                DB::table('schedules')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('user_presence_logs')) {
                DB::table('user_presence_logs')->where('user_id', $user->id)->delete();
            }

            // Cleanup task board memberships and assignments
            if (\Illuminate\Support\Facades\Schema::hasTable('task_board_members')) {
                DB::table('task_board_members')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('task_board_watchers')) {
                DB::table('task_board_watchers')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('task_card_assignees')) {
                DB::table('task_card_assignees')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('task_card_watchers')) {
                DB::table('task_card_watchers')->where('user_id', $user->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('task_card_comments')) {
                DB::table('task_card_comments')->where('user_id', $user->id)->delete();
            }

            // Remove manager associations
            DB::table('manager_user')->where('manager_id', $user->id)->delete();
            DB::table('manager_user')->where('user_id', $user->id)->delete();

            // Null out audit columns in users table
            DB::table('users')->where('created_by', $user->id)->update(['created_by' => null]);
            DB::table('users')->where('updated_by', $user->id)->update(['updated_by' => null]);

            $user->delete();
        });

        return redirect()->back()->with('success', 'Vacant position removed from org chart.');
    }

    public function destroyUserPlacement(User $user)
    {
        abort_if($user->is_vacant, 403);

        DB::transaction(function () use ($user) {
            // Get all subordinates of the user being removed
            $subordinateIds = $user->subordinates()->pluck('users.id')->all();
            
            // Get all managers of the user being removed (so we can re-route subordinates upwards)
            $managerIds = $user->managers()->pluck('users.id')->all();

            // Clear the user's organizational placement (remove from org chart)
            $user->forceFill([
                ...$this->organizationReferences->clearPayload(),
                'org_sort_order' => 0,
                'updated_by' => auth()->id(),
            ])->save();

            // Detach managers and subordinates from the user
            $user->managers()->detach();
            $user->subordinates()->detach();

            // Re-route subordinates to report to the user's manager(s)
            if (!empty($subordinateIds) && !empty($managerIds)) {
                foreach ($subordinateIds as $subId) {
                    $subordinate = User::find($subId);
                    if ($subordinate) {
                        $subordinate->managers()->syncWithoutDetaching($managerIds);
                    }
                }
            }
        });

        return redirect()->back()->with('success', 'User removed from organisation chart.');
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
