<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['roles:id,name', 'stores:id,name,code', 'managers:id,name', 'creator:id,name,email', 'updater:id,name,email']);
        
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('department', 'like', "%{$request->search}%")
                  ->orWhere('unit', 'like', "%{$request->search}%")
                  ->orWhere('sub_unit', 'like', "%{$request->search}%")
                  ->orWhere('position', 'like', "%{$request->search}%");
            });
        }
        
        $users = $query->paginate($request->get('per_page', 10))->withQueryString();
        $roles = Role::select('id', 'name')->get();
        $stores = \App\Models\Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $managers = User::where('is_manager', true)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        
        $departments = User::whereNotNull('department')->where('department', '!=', '')->distinct()->pluck('department');
        $units = User::whereNotNull('unit')->where('unit', '!=', '')->distinct()->pluck('unit');
        $subUnits = User::whereNotNull('sub_unit')->where('sub_unit', '!=', '')->distinct()->pluck('sub_unit');

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'stores' => $stores,
            'managers' => $managers,
            'departments' => $departments,
            'units' => $units,
            'subUnits' => $subUnits,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
            'department' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:255',
            'sub_unit' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_manager' => 'boolean',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'manager_ids' => 'nullable|array',
            'manager_ids.*' => 'exists:users,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'department' => $request->department,
            'unit' => $request->unit,
            'sub_unit' => $request->sub_unit,
            'position' => $request->position,
            'is_active' => $request->input('is_active', true),
            'is_manager' => $request->input('is_manager', false),
            'email_verified_at' => now(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $user->assignRole($request->role);

        if ($request->has('store_ids')) {
            $user->stores()->sync($request->store_ids);
        }

        if ($request->has('manager_ids')) {
            $user->managers()->sync($request->manager_ids);
        }

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|exists:roles,name',
            'department' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:255',
            'sub_unit' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_manager' => 'boolean',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'manager_ids' => 'nullable|array',
            'manager_ids.*' => 'exists:users,id',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->department = $request->department;
        $user->unit = $request->unit;
        $user->sub_unit = $request->sub_unit;
        $user->position = $request->position;
        $user->is_active = $request->boolean('is_active');
        $user->is_manager = $request->boolean('is_manager');
        $user->updated_by = auth()->id();
        $user->save();

        $user->syncRoles([$request->role]);
        Cache::forget('user_permissions_' . $user->id . '_' . ($user->updated_at?->timestamp ?? 0));

        // Update stores assignment
        if ($request->has('store_ids')) {
            $user->stores()->sync($request->store_ids);
        } else {
            $user->stores()->detach();
        }

        // Update managers assignment
        if ($request->has('manager_ids')) {
            $user->managers()->sync($request->manager_ids);
        } else {
            $user->managers()->detach();
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        DB::table('users')->where('created_by', $user->id)->update(['created_by' => null]);
        DB::table('users')->where('updated_by', $user->id)->update(['updated_by' => null]);

        $user->delete();
        return redirect()->back()->with('success', 'User deleted successfully.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
            'updated_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Password reset successfully.');
    }
}
