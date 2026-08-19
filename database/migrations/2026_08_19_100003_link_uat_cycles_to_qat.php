<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UAT side of the QAT link.
 *
 * QAT and UAT are independent modules — either can exist on its own — but when a
 * signed-off QAT cycle is promoted, the UAT cycle it produced needs to point back
 * at its upstream quality pass so the client-facing cycle can display who signed
 * the internal one off, and when.
 *
 * Both columns are nullable and read by nothing that already exists, so this is
 * purely additive: no existing UAT behaviour changes.
 *
 * Neither is a foreign key, on purpose. A real constraint would couple the two
 * modules' lifecycles — deleting a QAT cycle would start failing because of a row
 * in uat_cycles, and QatCycle::cascadeDelete() would have to reach into a table
 * belonging to another module to clear it. The modules are independent by design,
 * so the link is a soft reference: a dangling id resolves to a null relation,
 * which is exactly what the banner already checks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uat_cycles') && ! Schema::hasColumn('uat_cycles', 'qat_cycle_id')) {
            Schema::table('uat_cycles', function (Blueprint $table) {
                $table->unsignedBigInteger('qat_cycle_id')->nullable()->after('dev_lead_id');
                $table->index('qat_cycle_id');
            });
        }

        if (Schema::hasTable('uat_cases') && ! Schema::hasColumn('uat_cases', 'source_qat_case_id')) {
            Schema::table('uat_cases', function (Blueprint $table) {
                // Copy provenance. Intentionally not a foreign key: the source case
                // may be deleted later, and losing the breadcrumb is preferable to
                // blocking that deletion.
                $table->unsignedBigInteger('source_qat_case_id')->nullable()->after('order');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('uat_cases') && Schema::hasColumn('uat_cases', 'source_qat_case_id')) {
            Schema::table('uat_cases', function (Blueprint $table) {
                $table->dropColumn('source_qat_case_id');
            });
        }

        if (Schema::hasTable('uat_cycles') && Schema::hasColumn('uat_cycles', 'qat_cycle_id')) {
            Schema::table('uat_cycles', function (Blueprint $table) {
                // Drop the index before the column — SQL Server will not drop a
                // column an index still references.
                $table->dropIndex(['qat_cycle_id']);
                $table->dropColumn('qat_cycle_id');
            });
        }
    }
};
