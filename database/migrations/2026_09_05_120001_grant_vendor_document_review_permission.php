<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reviewing a vendor's accreditation documents is the vendor portal's own
 * permission (`vendor-documents.approve`), and the permissions table is shared
 * with it — so the back office reuses that name rather than inventing a second,
 * parallel right for the same decision.
 *
 * The permission already exists wherever the portal has run; firstOrCreate keeps
 * a standalone ghelpdesk database working. Granting it to the same roles that
 * already decide on vendor ACCOUNTS keeps the two decisions with one audience.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = ['vendor-documents.view', 'vendor-documents.approve'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::whereIn('name', ['Admin', 'Solutions Admin', 'Dev'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The permissions themselves belong to the portal — only the grants made
        // here are withdrawn.
        Role::whereIn('name', ['Admin', 'Solutions Admin', 'Dev'])->get()
            ->each(function (Role $role) {
                foreach ($this->permissions as $permission) {
                    if ($role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
