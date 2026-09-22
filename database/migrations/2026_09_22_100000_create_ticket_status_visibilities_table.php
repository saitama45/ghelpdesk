<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-department ticket status visibility (References → Ticket Statuses), plus
 * the permissions behind it and a dedicated `tickets.create_child` permission.
 *
 * Production only runs `artisan migrate --force`, never seeders, so the
 * permission rows and grants live here as well as in RolesAndPermissionSeeder.
 */
return new class extends Migration
{
    private const MODULE_PERMISSIONS = ['ticket_statuses.view', 'ticket_statuses.edit'];

    private const MODULE_ROLES = ['Admin', 'Solutions Admin', 'Dev'];

    public function up(): void
    {
        if (! Schema::hasTable('ticket_status_visibilities')) {
            Schema::create('ticket_status_visibilities', function (Blueprint $table) {
                $table->id();
                // A department belongs to one entity, so this is per entity per department.
                $table->foreignId('department_id')->constrained()->onDelete('no action');
                $table->string('status', 50);
                $table->boolean('is_visible')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['department_id', 'status']);
            });
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach ([...self::MODULE_PERMISSIONS, 'tickets.create_child'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::MODULE_ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            foreach (self::MODULE_PERMISSIONS as $name) {
                if ($role && ! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                }
            }
        }

        // Child tickets used to ride on tickets.edit. Every role and user holding it
        // keeps the ability, so nobody loses it on deploy; it can then be revoked
        // per role in the role editor. Additive only.
        $edit = Permission::where('name', 'tickets.edit')->where('guard_name', 'web')->first();
        if ($edit) {
            Role::whereHas('permissions', fn ($q) => $q->whereKey($edit->id))->get()
                ->each(fn (Role $role) => $role->hasPermissionTo('tickets.create_child') || $role->givePermissionTo('tickets.create_child'));
            \App\Models\User::withTrashed()->whereHas('permissions', fn ($q) => $q->whereKey($edit->id))->get()
                ->each(fn ($user) => $user->hasDirectPermission('tickets.create_child') || $user->givePermissionTo('tickets.create_child'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }

    public function down(): void
    {
        // Keep the configuration and grants on rollback; removing them is a manual decision.
    }
};
