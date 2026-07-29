<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The live-Gantt counterpart of the template columns — see
     * 2026_07_29_000003. A task starts the day after depends_on_task_id
     * finishes; can_run_parallel decides whether it ALSO has to wait for the row
     * ahead of it in the plan.
     *
     * NULL dependency + can_run_parallel = false reproduces the old
     * straight-down-the-list chain exactly, so nothing already scheduled moves.
     *
     * The existing `dependencies` JSON column stays untouched — it is free-text
     * notes on the Gantt, never a scheduling input.
     */
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('project_tasks', 'depends_on_task_id')) {
                $table->foreignId('depends_on_task_id')
                    ->nullable()
                    ->after('parent_task_id')
                    ->constrained('project_tasks')
                    ->noActionOnDelete();
            }

            if (!Schema::hasColumn('project_tasks', 'can_run_parallel')) {
                $table->boolean('can_run_parallel')->default(false)->after('lead_time_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('project_tasks', 'depends_on_task_id')) {
                $table->dropForeign(['depends_on_task_id']);
                $table->dropColumn('depends_on_task_id');
            }

            if (Schema::hasColumn('project_tasks', 'can_run_parallel')) {
                $table->dropColumn('can_run_parallel');
            }
        });
    }
};
