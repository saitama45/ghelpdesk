<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Company;
use App\Http\Services\RoleService;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $query = Role::with('permissions:id,name', 'companies:id,name');
        
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        
        $roles = $query->paginate($request->get('per_page', 10))->withQueryString();
        $permissions = RoleService::getPermissionsByCategory();
        $companies = Company::where('is_active', true)->select('id', 'name')->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'companies' => $companies,
            'dynamicTables' => \App\Models\TableDefinition::where('is_active', true)->get(['name', 'slug']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles',
            'landing_page' => 'nullable|string|max:255',
            'permissions' => 'array',
            'companies' => 'required|array|min:1',
            'is_assignable' => 'boolean',
            'notify_on_ticket_create' => 'boolean',
            'notify_on_ticket_assign' => 'boolean',
            'notify_on_urgent_ticket' => 'boolean',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'landing_page' => $request->landing_page,
            'is_assignable' => $request->boolean('is_assignable'),
            'notify_on_ticket_create' => $request->boolean('notify_on_ticket_create'),
            'notify_on_ticket_assign' => $request->boolean('notify_on_ticket_assign'),
            'notify_on_urgent_ticket' => $request->boolean('notify_on_urgent_ticket'),
        ]);
        
        if ($request->permissions) {
            RoleService::updateRolePermissions($role->id, $request->permissions);
        }
        
        if ($request->companies) {
            $role->companies()->sync($request->companies);
        }

        // Bump the global permissions version so all cached user permission arrays are invalidated
        Cache::put('permissions_version', now()->timestamp);

        return redirect()->back()->with('success', 'Role created successfully');
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'landing_page' => 'nullable|string|max:255',
            'permissions' => 'array',
            'companies' => 'required|array|min:1',
            'is_assignable' => 'boolean',
            'notify_on_ticket_create' => 'boolean',
            'notify_on_ticket_assign' => 'boolean',
            'notify_on_urgent_ticket' => 'boolean',
        ]);

        $role->name = $request->name;
        $role->landing_page = $request->landing_page;
        $role->is_assignable = $request->boolean('is_assignable');
        $role->notify_on_ticket_create = $request->boolean('notify_on_ticket_create');
        $role->notify_on_ticket_assign = $request->boolean('notify_on_ticket_assign');
        $role->notify_on_urgent_ticket = $request->boolean('notify_on_urgent_ticket');
        $role->save();
        
        RoleService::updateRolePermissions($role->id, $request->permissions ?? []);
        
        $role->companies()->sync($request->companies ?? []);

        // Bump the global permissions version so all cached user permission arrays are invalidated
        Cache::put('permissions_version', now()->timestamp);

        return redirect()->back()->with('success', 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete role with assigned users');
        }

        $role->delete();
        Cache::put('permissions_version', now()->timestamp);
        return redirect()->back()->with('success', 'Role deleted successfully');
    }
}