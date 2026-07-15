<?php

use App\Models\Project;
use App\Models\ProjectTeamMember;
use Illuminate\Database\Migrations\Migration;

/**
 * Projects created before ownership tracking (created_by NULL) would otherwise be
 * editable by every projects.edit holder — the exact opposite of the intended
 * "only the creator can manage the plan" rule. There is no historical record of
 * who created them, so we assign the most sensible available owner: the project's
 * team lead (a "Lead Partner", else a "Leader", else the earliest team member
 * that maps to a real user).
 *
 * Idempotent: only touches rows where created_by is still NULL. Projects with no
 * team member mapped to a user are left ownerless (admin-only) and can be
 * assigned an owner later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Project::whereNull('created_by')->each(function (Project $project) {
            $members = ProjectTeamMember::where('project_id', $project->id)
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->get(['user_id', 'role_type']);

            if ($members->isEmpty()) {
                return;
            }

            $owner = $members->firstWhere('role_type', 'Lead Partner')
                ?? $members->firstWhere('role_type', 'Leader')
                ?? $members->first();

            $project->forceFill(['created_by' => $owner->user_id])->saveQuietly();
        });
    }

    public function down(): void
    {
        // Ownership backfill is not reversible — we cannot tell which owners were
        // inferred here versus set explicitly afterwards. No-op.
    }
};
