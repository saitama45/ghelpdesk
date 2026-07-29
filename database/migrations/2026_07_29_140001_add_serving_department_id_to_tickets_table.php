<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the RECEIVER half of the department axis.
 *
 * `tickets.department_id` is the CUSTOMER side — the department the requester
 * belongs to. It was promoted from the free-text `tickets.department`, which
 * TicketController::store() fills from `auth()->user()->department`, so it has
 * always described who ASKED, never who SERVES.
 *
 * Until now the serving department was never stored at all: it was inferred at
 * query time from the assignee's department (Ticket::scopeOwnedByDepartment),
 * which makes ownership a side effect of the auto-assignee round-robin and
 * leaves every unassigned ticket in a shared pool with no owner. This column
 * makes the provider side explicit so inbound mail routing, form ownership
 * (form_definitions.department_id) and the Hub provider/customer split all have
 * somewhere to write to.
 *
 * The backfill uses the only signal available for historical rows — the
 * assignee's department — and is idempotent so cloud can re-run it on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'serving_department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->unsignedBigInteger('serving_department_id')->nullable()->after('department_id');
                // INDEX ONLY, NO FOREIGN KEY — deliberate, and verified against the
                // real SQL Server schema. departments->tickets already has one
                // SET NULL path (department_id); a second one makes SQL Server
                // reject the constraint with "may cause cycles or multiple cascade
                // paths". Same reason task_board_columns carries no FK.
                //
                // Referential cleanup happens in the app instead: Department's
                // `deleting` hook clears this column before the row goes.
                $table->index('serving_department_id');
            });
        }

        // Backfill from the assignee's department: the historical de-facto owner,
        // and exactly what scopeOwnedByDepartment resolved before this column
        // existed, so no reporting figure moves purely because of this migration.
        // Guarded on NULL, so re-running never overwrites a routed value.
        //
        // Looped per department rather than an UPDATE..FROM join: that syntax is
        // SQL Server only and the test suite migrates against sqlite. The id
        // chunking keeps each IN() under SQL Server's 2100-parameter ceiling.
        foreach (DB::table('departments')->pluck('id') as $departmentId) {
            DB::table('users')
                ->where('department_id', $departmentId)
                ->pluck('id')
                ->chunk(1000)
                ->each(function ($userIds) use ($departmentId) {
                    DB::table('tickets')
                        ->whereNull('serving_department_id')
                        ->whereIn('assignee_id', $userIds->all())
                        ->update(['serving_department_id' => $departmentId]);
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'serving_department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex(['serving_department_id']);
                $table->dropColumn('serving_department_id');
            });
        }
    }
};
