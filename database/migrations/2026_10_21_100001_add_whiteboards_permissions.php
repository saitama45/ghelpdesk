<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Whiteboards module (standalone /whiteboards). Admin, Solutions Admin and Dev get every
 * whiteboards permission; other roles are granted them on /roles. Additive and idempotent.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['whiteboards.view', 'whiteboards.create', 'whiteboards.edit', 'whiteboards.delete'];

    public function up(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::whereIn('name', ['Admin', 'Solutions Admin', 'Dev'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::has('permissions_version')
            ? Cache::increment('permissions_version')
            : Cache::forever('permissions_version', 1);
    }

    /** Role grants made on /roles cannot be told apart from this one, so nothing is revoked. */
    public function down(): void {}
};
