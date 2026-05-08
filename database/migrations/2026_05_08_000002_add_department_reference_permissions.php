<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'departments.view',
        'departments.create',
        'departments.edit',
        'departments.delete',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        Role::where('name', 'Admin')->first()?->givePermissionTo($this->permissions);
        Role::where('name', 'Solutions Admin')->first()?->givePermissionTo($this->permissions);
        Role::where('name', 'Tech Support')->first()?->givePermissionTo(['departments.view']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            $permissionModel = Permission::where('name', $permission)->first();
            if ($permissionModel) {
                Role::query()->each(function (Role $role) use ($permissionModel) {
                    if ($role->hasPermissionTo($permissionModel)) {
                        $role->revokePermissionTo($permissionModel);
                    }
                });
                $permissionModel->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
