<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/**
 * npc_status.settings gates the NPC page's Settings tab (entities hidden from the
 * Monitoring list). Admin gets it like every other NPC permission; the frontend
 * reads permissions from the role, not Gate::before, so Admin needs the row.
 * Additive and idempotent; other roles are granted it on /roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('npc_status.settings', 'web');

        Role::where('name', 'Admin')->first()?->givePermissionTo($permission);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::has('permissions_version')
            ? Cache::increment('permissions_version')
            : Cache::forever('permissions_version', 1);
    }

    /** Role grants made on /roles cannot be told apart from this one, so nothing is revoked. */
    public function down(): void
    {
    }
};
