<?php

use App\Models\User;
use App\Support\AttendanceVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates `attendance.logs_department` and grants it to the accounts that
 * administer attendance for a whole department.
 *
 * /attendance/logs used to show every employee to anyone holding the Admin, Dev
 * or Solutions Admin role, or the `is_manager` flag. Visibility now follows the
 * reporting line drawn on /departments, so those roles see only themselves and
 * their own subtree. This permission is the deliberate exception, and it is
 * granted to NAMED ACCOUNTS rather than to a role — a role grant would put the
 * company-wide view back for everyone who happens to hold that role.
 *
 * Production only ever runs `artisan migrate --force`; it never runs seeders, so
 * the grant has to live here to reach the cloud database.
 */
return new class extends Migration
{
    /**
     * Matched by email so the grant lands on the right person regardless of the
     * differing user ids between the local snapshot and the cloud database.
     */
    private const GRANTEE_EMAILS = [
        'yssa.dysangco@tablegroup.com.ph',
        'gen.magbanua@tablegroup.com.ph',
        'jb.dedios@tablegroup.com.ph',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('users')) {
            return;
        }

        Permission::firstOrCreate([
            'name' => AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION,
            'guard_name' => 'web',
        ]);

        // Spatie resolves permissions against a cache built before this migration
        // created the row; without the flush the grant below cannot find it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTEE_EMAILS as $email) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                // A missing account is not a deployment failure — the grant can be
                // made by hand from the role editor once the account exists.
                continue;
            }

            // A direct user permission, not a role permission, and additive so a
            // re-run never revokes anything granted by hand in the meantime.
            if (! $user->hasDirectPermission(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION)) {
                $user->givePermissionTo(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION);
            }
        }

        $this->flushPermissionCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::where('name', AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION)
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Permission $permission) => $permission->delete());

        $this->flushPermissionCaches();
    }

    /**
     * Spatie's own cache, plus the per-user permission list memoised in
     * HandleInertiaRequests and keyed by `permissions_version` — bumping the
     * version is what makes the change reach users who are already logged in.
     */
    private function flushPermissionCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
