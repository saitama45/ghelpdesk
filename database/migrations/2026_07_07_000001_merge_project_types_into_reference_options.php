<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unify the two separate "project type" lists into the single reference_options
 * table (type = project_type):
 *   - Projects used a hardcoded PHP constant (Store Opening, IT Deployment,
 *     Internal Initiative, Vendor Project, General).
 *   - Project Templates already read from reference_options (NSO, Store Closure,
 *     Store Renovation + any custom).
 *
 * Reconciliation: "NSO (New Store Opening)" is folded into "Store Opening" —
 * existing project_template rows are remapped — then the remaining Project types
 * are seeded so both pages read one shared list.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Fold NSO into Store Opening.
        $storeOpeningExists = DB::table('reference_options')
            ->where('type', 'project_type')->where('value', 'Store Opening')->exists();

        $nso = DB::table('reference_options')
            ->where('type', 'project_type')->where('value', 'NSO')->first();

        if ($nso) {
            if ($storeOpeningExists) {
                // A Store Opening option already exists — drop the duplicate NSO row.
                DB::table('reference_options')->where('id', $nso->id)->delete();
            } else {
                // Rename the NSO row in place so it becomes the canonical Store Opening.
                DB::table('reference_options')->where('id', $nso->id)->update([
                    'value'      => 'Store Opening',
                    'label'      => 'Store Opening',
                    'sort_order' => 1,
                    'updated_at' => $now,
                ]);
                $storeOpeningExists = true;
            }
        }

        // Remap any project templates that still reference the old NSO value.
        DB::table('project_templates')->where('project_type', 'NSO')
            ->update(['project_type' => 'Store Opening', 'updated_at' => $now]);

        // 2. Seed the remaining Project types (skip any that already exist).
        $seed = [
            'Store Opening'       => 1,
            'IT Deployment'       => 4,
            'Internal Initiative' => 5,
            'Vendor Project'      => 6,
            'General'             => 7,
        ];

        foreach ($seed as $value => $sortOrder) {
            $exists = DB::table('reference_options')
                ->where('type', 'project_type')->where('value', $value)->exists();

            if (! $exists) {
                DB::table('reference_options')->insert([
                    'type'       => 'project_type',
                    'value'      => $value,
                    'label'      => $value,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove only the Project-side types that were seeded here. Store Opening
        // is intentionally left in place because live projects depend on it.
        DB::table('reference_options')
            ->where('type', 'project_type')
            ->whereIn('value', ['IT Deployment', 'Internal Initiative', 'Vendor Project', 'General'])
            ->delete();
    }
};
