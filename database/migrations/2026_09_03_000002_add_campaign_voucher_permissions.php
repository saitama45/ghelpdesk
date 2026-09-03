<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['stamps.approve', 'stamps.cancel', 'stamps.export'];

    private const ROLE_GRANTS = [
        'Admin' => self::PERMISSIONS,
        'Solutions Admin' => self::PERMISSIONS,
        'Dev' => self::PERMISSIONS,
        'Tech Support' => ['stamps.export'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) return;

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_GRANTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) continue;
            foreach ($permissions as $permission) {
                if (! $role->hasPermissionTo($permission)) $role->givePermissionTo($permission);
            }
        }
        $this->flushCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) return;
        Permission::whereIn('name', self::PERMISSIONS)->get()->each->delete();
        $this->flushCaches();
    }

    private function flushCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
