<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks who owns / last touched a project and its tasks.
 *
 * `created_by` on a project is the person who created it — they get full edit
 * access to every milestone / activity / sub-task (see Project::isManagedBy).
 * Everyone else may only edit the rows assigned to them. `updated_by` records
 * the last editor for auditing. Legacy rows have NULL created_by until the first
 * editor claims ownership (Project::claimOwnershipIfUnowned).
 *
 * No FK constraints: SQL Server rejects the multiple cascade paths these would
 * create against the users table (same pattern used elsewhere in this app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('remarks');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->index('created_by');
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('comments');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn(['created_by', 'updated_by']);
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
