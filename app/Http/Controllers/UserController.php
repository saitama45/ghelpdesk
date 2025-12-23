<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles:id,name');
        
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('department', 'like', "%{$request->search}%")
                  ->orWhere('position', 'like', "%{$request->search}%");
            });
        }
        
        $users = $query->paginate($request->get('per_page', 10))->withQueryString();
        $roles = Role::select('id', 'name')->get();
        
        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
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
            'position' => 'nullable|string|max:255',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'department' => $request->department,
            'position' => $request->position,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($request->role);

        return redirect()->back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|exists:roles,name',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->department = $request->department;
        $user->position = $request->position;
        $user->save();

        $user->syncRoles([$request->role]);

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
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
        ]);

        return redirect()->back()->with('success', 'Password reset successfully.');
    }
}