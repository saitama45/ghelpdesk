<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rights over vendor-portal accounts (registered through linkportal), kept
 * separate from plain vendor record editing.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = ['vendors.approve', 'vendors.reset_password'];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Dev already holds every other vendors.* right on this deployment, so
        // it is granted alongside the two administrator roles.
        Role::whereIn('name', ['Admin', 'Solutions Admin', 'Dev'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // `vendors.approve` predates this migration (the portal created it), so
        // only the permission introduced here is removed.
        $permission = Permission::where('name', 'vendors.reset_password')->first();

        if ($permission) {
            Role::query()->each(function (Role $role) use ($permission) {
                if ($role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            });

            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
