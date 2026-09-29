<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * schedules.ticket_duty lets a user tag or untag a schedule for Ticket Duty.
 * Admin and Solutions Admin get it like the other schedule permissions; the
 * frontend reads permissions from the role, not Gate::before, so Admin needs
 * the row. Additive and idempotent; other roles are granted it on /roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('schedules.ticket_duty', 'web');

        Role::where('name', 'Admin')->first()?->givePermissionTo($permission);
        Role::where('name', 'Solutions Admin')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::has('permissions_version')
            ? Cache::increment('permissions_version')
            : Cache::forever('permissions_version', 1);
    }

    /** Role grants made on /roles cannot be told apart from this one, so nothing is revoked. */
    public function down(): void {}
};
