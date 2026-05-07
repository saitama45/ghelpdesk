<?php

namespace App\Http\Controllers;

use App\Mail\GoogleRegistrationApproved;
use App\Models\User;
use App\Http\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Throwable;

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

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false)
                    ->where(fn ($q) => $q->whereNull('google_id')->orWhereHas('roles')),
                'pending_approval' => $query->whereNotNull('google_id')
                    ->where('is_active', false)
                    ->whereDoesntHave('roles'),
                default => null,
            };
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
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
            ],
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
            'notify_user_approval' => 'boolean',
        ]);

        $wasPendingGoogleRegistration = $this->isPendingGoogleRegistration($user);

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

        if (
            $wasPendingGoogleRegistration
            && $this->isApprovedGoogleRegistration($user)
            && $request->boolean('notify_user_approval')
        ) {
            $this->notifyGoogleRegistrationApproved($user);
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        DB::transaction(function () use ($user) {
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
            if (Schema::hasTable('inventory_transactions')) {
                DB::table('inventory_transactions')->where('created_by', $user->id)->update(['created_by' => null]);
                DB::table('inventory_transactions')->where('updated_by', $user->id)->update(['updated_by' => null]);
            }

            // Cleanup SAP and POS requests
            if (Schema::hasTable('sap_requests')) {
                DB::table('sap_requests')->where('user_id', $user->id)->update(['user_id' => null]);
            }
            if (Schema::hasTable('sap_request_approvals')) {
                DB::table('sap_request_approvals')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('pos_requests')) {
                DB::table('pos_requests')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('pos_request_approvals')) {
                DB::table('pos_request_approvals')->where('user_id', $user->id)->delete();
            }

            // Cleanup attendance and schedules (Required fields, manual delete for safety)
            if (Schema::hasTable('attendance_logs')) {
                DB::table('attendance_logs')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('schedules')) {
                DB::table('schedules')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('user_presence_logs')) {
                DB::table('user_presence_logs')->where('user_id', $user->id)->delete();
            }

            // Cleanup task board memberships and assignments
            if (Schema::hasTable('task_board_members')) {
                DB::table('task_board_members')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('task_board_watchers')) {
                DB::table('task_board_watchers')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('task_card_assignees')) {
                DB::table('task_card_assignees')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('task_card_watchers')) {
                DB::table('task_card_watchers')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('task_card_comments')) {
                DB::table('task_card_comments')->where('user_id', $user->id)->delete();
            }

            // Remove manager associations
            DB::table('manager_user')->where('manager_id', $user->id)->delete();

            // Null out audit columns in users table
            DB::table('users')->where('created_by', $user->id)->update(['created_by' => null]);
            DB::table('users')->where('updated_by', $user->id)->update(['updated_by' => null]);

            // Finally delete the user
            $user->delete();
        });

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

    private function isPendingGoogleRegistration(User $user): bool
    {
        return filled($user->google_id) && ! $user->roles()->exists();
    }

    private function isApprovedGoogleRegistration(User $user): bool
    {
        return filled($user->google_id) && (bool) $user->is_active && $user->roles()->exists();
    }

    private function notifyGoogleRegistrationApproved(User $user): void
    {
        try {
            Mail::to($user->email)->send(new GoogleRegistrationApproved($user));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
