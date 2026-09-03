<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real execution dates, so the Gantt can draw an Actual bar that is independent
 * of the planned bar.
 *
 * Until now the hatched "actual" layer was a progress fill painted inside the
 * planned bar, so it always began on the planned start date and could never
 * show work that started early or ran past the plan. These two columns record
 * when a row actually began and finished: ProjectTask stamps actual_start_date
 * the first time the row moves off 0% (or into an in-progress status) and
 * actual_end_date when it reaches 100%, and either can be corrected by hand in
 * the task panel.
 *
 * Nullable and left empty for existing rows: a row nobody has reported on draws
 * no actual bar, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('project_tasks', 'actual_start_date')) {
                $table->date('actual_start_date')->nullable()->after('original_end_date');
            }

            if (! Schema::hasColumn('project_tasks', 'actual_end_date')) {
                $table->date('actual_end_date')->nullable()->after('actual_start_date');
            }
        });
    }

    public function down(): void
    {
        // Forward-only: dropping these would discard reported execution dates.
    }
};
