<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every form built in /form-builder an OWNING DEPARTMENT.
 *
 * This is what makes the Services hub department-aware: the department you are
 * viewing decides which forms you see, and whether you see them as that
 * department's service PROVIDER (your home department — you work the queue) or
 * as an internal CUSTOMER (another department — you request from its catalogue).
 * See {@see \App\Support\DepartmentContext} for the provider/customer rule.
 *
 * Existing forms are backfilled to TAS (the user's call): TAS built every form
 * currently in the system, and each one can be reassigned on /form-builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_definitions')) {
            return;
        }

        if (! Schema::hasColumn('form_definitions', 'department_id')) {
            Schema::table('form_definitions', function (Blueprint $table) {
                // Nullable + nullOnDelete: deleting a department must never delete
                // the forms it owned — they fall back to "unassigned" and are
                // flagged in the form-builder UI for reassignment.
                $table->foreignId('department_id')->nullable()->after('description')
                    ->constrained()->nullOnDelete();
            });
        }

        $this->backfillToTas();
    }

    /**
     * Point every existing form at TAS. Matched on code first (the reliable key),
     * then on name for installs where the code is blank. Only rows that are still
     * NULL are touched, so re-running this is safe.
     */
    private function backfillToTas(): void
    {
        $tasId = DB::table('departments')->where('code', 'TAS')->value('id')
            ?? DB::table('departments')->where('name', 'Technology and Solutions')->value('id');

        if (! $tasId) {
            return;
        }

        DB::table('form_definitions')->whereNull('department_id')->update([
            'department_id' => $tasId,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('form_definitions', 'department_id')) {
            return;
        }

        Schema::table('form_definitions', function (Blueprint $table) {
            // SQL Server refuses to drop a column while its FK constraint stands.
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
