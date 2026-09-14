<?php

use App\Models\ProjectMilestone;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Milestone owners now receive projects.manage_tasks automatically when they are
 * assigned (ProjectMilestone::booted). This grants it once to everyone who already
 * owned a milestone before that rule existed. Additive and idempotent: users who
 * already hold the permission (directly or through a role) are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate(ProjectMilestone::OWNER_PERMISSION, 'web');

        $ownerIds = ProjectMilestone::query()->whereNotNull('assigned_to')->distinct()->pluck('assigned_to');

        User::query()->whereIn('id', $ownerIds)->get()->each(function (User $owner): void {
            if (! $owner->hasPermissionTo(ProjectMilestone::OWNER_PERMISSION)) {
                $owner->givePermissionTo(ProjectMilestone::OWNER_PERMISSION);
            }
        });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Grants cannot be told apart from ones an admin made by hand, so nothing is revoked. */
    public function down(): void
    {
    }
};
