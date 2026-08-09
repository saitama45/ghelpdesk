<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asset Operational Health groups — the roll-up above the existing Category
 * taxonomy (slide 07 "Groups & Category Triggers").
 *
 * Categories are shared by tickets and assets, so the group cannot live on the
 * category name itself. It is a reference_options row (type = asset_group), the
 * same single-source pattern used by project_type and payment_mode, and each
 * Category points at one via categories.asset_group_id.
 */
return new class extends Migration
{
    private const GROUPS = [
        'POS Systems',
        'Peripherals',
        'Security',
        'Network & Connectivity',
        'Digital Experience',
        'Back Office',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GROUPS as $index => $group) {
            $exists = DB::table('reference_options')
                ->where('type', 'asset_group')
                ->where('value', $group)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('reference_options')->insert([
                'type' => 'asset_group',
                'value' => $group,
                'label' => $group,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('reference_options')
            ->where('type', 'asset_group')
            ->whereIn('value', self::GROUPS)
            ->delete();
    }
};
