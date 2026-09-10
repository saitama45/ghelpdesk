<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a user (/users) or a loyalty customer (/stamps → Customers) now
 * archives the row instead of destroying it, so both are recoverable from
 * Settings → Account Archive.
 *
 * `deleted_by` is a plain nullable column, not a constrained foreign key:
 * `users` already carries created_by/updated_by pointing back at itself, and
 * SQL Server refuses another cascade path into the same parent table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('users', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable();
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('customers', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }
            if (Schema::hasColumn('customers', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
