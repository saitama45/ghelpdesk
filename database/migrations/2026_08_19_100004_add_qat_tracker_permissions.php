<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the QAT Tracker permissions and grants them to the default roles.
 *
 * RolesAndPermissionSeeder carries the same list for fresh local installs, but
 * production only ever runs `artisan migrate --force` on deploy — it never runs
 * seeders, and that seeder additionally creates demo accounts with well-known
 * passwords, so it must not be run there. Without this migration the tables would
 * deploy with no permission rows behind them: `can:qat.view` would 403 for
 * everyone, and the "QAT Tracker" group would not even appear in the role editor
 * (it is built from Permission::all()), leaving nobody able to grant it.
 */
return new class extends Migration
{
    /** Everything the module gates on. */
    private const PERMISSIONS = [
        'qat.view',
        'qat.create',
        'qat.edit',
        'qat.execute',
        'qat.submit',
        'qat.approve',
        'qat.promote',
        'qat.import',
        'qat.export',
        'qat.delete',
    ];

    /**
     * Roles that already exist keep their shape: the admin roles get everything,
     * while Tech Support runs the tests without owning, approving or promoting the
     * cycle. Roles that are absent are skipped rather than created.
     *
     * Dev is included deliberately. Permission grants are explicit pivot rows, so
     * a role that already holds "everything" does NOT pick up permissions created
     * afterwards — without this entry the module deploys invisible to every Dev
     * user, which is exactly what a rollback/re-apply test demonstrated when the
     * UAT Tracker shipped.
     *
     * qat.approve is granted here only to the admin roles and Dev. It is the
     * manager gate, and it is expected to be granted by hand to the manager roles
     * each department actually uses — this migration cannot know their names.
     */
    private const ROLE_GRANTS = [
        'Admin' => self::PERMISSIONS,
        'Solutions Admin' => self::PERMISSIONS,
        'Dev' => self::PERMISSIONS,
        'Tech Support' => ['qat.view', 'qat.execute', 'qat.export'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLE_GRANTS as $roleName => $granted) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

            // givePermissionTo is additive, so re-running never revokes anything
            // an administrator granted by hand in the meantime.
            foreach ($granted as $name) {
                if (! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                }
            }
        }

        $this->flushPermissionCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::whereIn('name', self::PERMISSIONS)->get()
            ->each(fn (Permission $permission) => $permission->delete());

        $this->flushPermissionCaches();
    }

    /**
     * Two caches sit in front of permissions: Spatie's own, and the per-user list
     * memoised in HandleInertiaRequests keyed by `permissions_version`. Bumping
     * the version is what makes the new sidebar entry appear for users who are
     * already logged in, instead of after their session expires.
     */
    private function flushPermissionCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
    }
};
