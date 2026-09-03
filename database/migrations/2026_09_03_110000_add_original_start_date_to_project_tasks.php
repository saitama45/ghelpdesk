<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline (planned) dates for the Gantt's planned-vs-actual bars.
 *
 * project_tasks already carried original_end_date but nothing ever wrote it.
 * The pair is now captured the first time a row gets dates (see ProjectTask's
 * booted hooks) and never rewritten by rescheduling, so the chart can draw the
 * plan as it was first scheduled underneath where the row actually sits today.
 *
 * Existing rows are baselined against their current schedule: no drift is shown
 * for work planned before this migration, and any slip from here on is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tasks', 'original_start_date')) {
            Schema::table('project_tasks', function (Blueprint $table) {
                $table->date('original_start_date')->nullable()->after('start_anchor_date');
            });
        }

        DB::table('project_tasks')
            ->whereNull('original_start_date')
            ->whereNotNull('start_date')
            ->update(['original_start_date' => DB::raw('start_date')]);

        DB::table('project_tasks')
            ->whereNull('original_end_date')
            ->whereNotNull('end_date')
            ->update(['original_end_date' => DB::raw('end_date')]);
    }

    public function down(): void
    {
        // Forward-only: dropping the column would discard captured baselines.
    }
};
