<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes the free-text tickets.department to a real FK on departments, so the
 * department axis (provider/customer access, per-department work queues) can join
 * reliably. The original string column is kept as a fallback for the rows that do
 * not map cleanly — see the Stage-2 match report: exact name/code matching covers
 * ~619 rows, ~608 are null, and ~70 carry noise values (store names, free text)
 * that intentionally stay on the string only.
 *
 * The backfill runs here (idempotent) so cloud picks it up on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->nullable()->after('department');
                // Set null if the department row is ever removed. Single reference
                // path (departments → tickets), so no SQL Server cascade conflict.
                $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
                $table->index('department_id');
            });
        }

        // Backfill by exact, case-insensitive name/code match. Deterministic:
        // only rows whose department string equals a department name or code get
        // an id; everything else remains null and falls back to the string.
        foreach (DB::table('departments')->get() as $dept) {
            $needles = array_values(array_filter([
                $dept->name ? strtolower(trim($dept->name)) : null,
                isset($dept->code) && $dept->code ? strtolower(trim($dept->code)) : null,
            ]));

            if (empty($needles)) {
                continue;
            }

            DB::table('tickets')
                ->whereNull('department_id')
                ->whereNotNull('department')
                ->whereIn(DB::raw('LOWER(LTRIM(RTRIM(department)))'), $needles)
                ->update(['department_id' => $dept->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropForeign(['department_id']);
                $table->dropIndex(['department_id']);
                $table->dropColumn('department_id');
            });
        }
    }
};
