<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project team roles used to be a hard-coded <select> on /projects/{id}.
 * They now live in reference_options (type = project_role) so the Manage Team
 * modal can add, rename and delete them like /activity-templates does for
 * project types. This seeds the previous hard-coded list, plus any role already
 * stored on an existing team member, without touching rows that exist already.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'Lead Partner',
        'Leader',
        'SO Rep',
        'SMITS',
        'Marketing',
        'Training',
        'SCM',
        'Contractor',
        'Franchisee',
        'Other',
    ];

    public function up(): void
    {
        $existing = DB::table('reference_options')
            ->where('type', 'project_role')
            ->pluck('value')
            ->all();

        $inUse = DB::table('project_team_members')
            ->whereNotNull('role_type')
            ->where('role_type', '<>', '')
            ->distinct()
            ->pluck('role_type')
            ->all();

        $sort = (int) DB::table('reference_options')->where('type', 'project_role')->max('sort_order');
        $now = now();
        $rows = [];

        foreach ([...self::DEFAULTS, ...$inUse] as $role) {
            $role = trim((string) $role);

            if ($role === '' || in_array($role, $existing, true)) {
                continue;
            }

            $existing[] = $role;
            $rows[] = [
                'type' => 'project_role',
                'value' => $role,
                'label' => $role,
                'sort_order' => ++$sort,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table('reference_options')->insert($rows);
        }
    }

    public function down(): void
    {
        // Deliberately not removing rows: they may have been edited or are in use.
    }
};
