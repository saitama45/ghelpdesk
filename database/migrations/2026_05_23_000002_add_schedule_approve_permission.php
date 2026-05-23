<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private string $permission = 'schedules.approve';

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => $this->permission,
            'guard_name' => 'web',
        ]);

        Role::whereIn('name', ['Admin', 'Solutions Admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', $this->permission)->first();

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
