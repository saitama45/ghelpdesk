<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Health thresholds are now managed only as a single Global Default (the per-department
 * hierarchy dropdown was removed from Settings). Any previously saved per-scope override
 * rows (keys like threshold_green_min_dg, ..._node_5, ..._technology__service_operations)
 * would otherwise still win over the global value on department-scoped dashboards, so we
 * drop them here. The 11 canonical global keys are preserved.
 */
return new class extends Migration
{
    private const GLOBAL_KEYS = [
        'threshold_green_min', 'threshold_green_max', 'threshold_green_label',
        'threshold_yellow_min', 'threshold_yellow_max', 'threshold_yellow_label',
        'threshold_orange_min', 'threshold_orange_max', 'threshold_orange_label',
        'threshold_red_min', 'threshold_red_label',
    ];

    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'thresholds')
            ->where('key', 'like', 'threshold_%')
            ->whereNotIn('key', self::GLOBAL_KEYS)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible: the removed per-scope overrides duplicated the global values
        // and are no longer editable, so there is nothing meaningful to restore.
    }
};
