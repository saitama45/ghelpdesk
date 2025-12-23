<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Company;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $query = Role::with('permissions:id,name', 'companies:id,name');
        
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        
        $roles = $query->paginate($request->get('per_page', 10))->withQueryString();
        $permissions = Permission::select('id', 'name')->get()->groupBy(function($permission) {
            return explode('.', $permission->name)[0];
        });
        $companies = Company::where('is_active', true)->select('id', 'name')->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'companies' => $companies,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles',
            'permissions' => 'array',
            'companies' => 'array'
        ]);

        $role = Role::create(['name' => $request->name]);
        
        if ($request->permissions) {
            $role->syncPermissions($request->permissions);
        }
        
        if ($request->companies) {
            $role->companies()->sync($request->companies);
        }

        return redirect()->back()->with('success', 'Role created successfully');
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'array',
            'companies' => 'array'
        ]);

        $role->name = $request->name;
        $role->save();
        $role->syncPermissions($request->permissions ?? []);
        $role->companies()->sync($request->companies ?? []);

        return redirect()->back()->with('success', 'Role updated successfully');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete role with assigned users');
        }

        $role->delete();
        return redirect()->back()->with('success', 'Role deleted successfully');
    }
}