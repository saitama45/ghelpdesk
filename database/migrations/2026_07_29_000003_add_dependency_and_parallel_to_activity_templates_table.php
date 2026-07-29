<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Template rows become dependency-driven: a row starts the day after its
     * requisite finishes rather than simply after whatever sits above it.
     *
     *  - depends_on_template_id — the requisite row. NULL keeps the old
     *    behaviour (follow the previous row), so every existing template keeps
     *    the exact schedule it has today.
     *  - can_run_parallel — false: also wait for the preceding row, i.e. stay in
     *    the queue. true: honour the requisite ONLY, so the row may overlap work
     *    that is still running.
     *
     * The self-FK uses noActionOnDelete for the same reason
     * parent_activity_template_id does — SQL Server refuses cascade cycles on a
     * self-referencing table.
     */
    public function up(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_templates', 'depends_on_template_id')) {
                $table->foreignId('depends_on_template_id')
                    ->nullable()
                    ->after('parent_activity_template_id')
                    ->constrained('activity_templates')
                    ->noActionOnDelete();
            }

            if (!Schema::hasColumn('activity_templates', 'can_run_parallel')) {
                $table->boolean('can_run_parallel')->default(false)->after('default_duration_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            if (Schema::hasColumn('activity_templates', 'depends_on_template_id')) {
                $table->dropForeign(['depends_on_template_id']);
                $table->dropColumn('depends_on_template_id');
            }

            if (Schema::hasColumn('activity_templates', 'can_run_parallel')) {
                $table->dropColumn('can_run_parallel');
            }
        });
    }
};
