<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `vendors` table is shared with the vendor portal (linkportal), which
 * authenticates against it and soft-deletes on it. Those columns are created by
 * the portal's own merge migration on the shared database, so this migration
 * exists to keep THIS app self-sufficient — a standalone ghelpdesk database (and
 * every test run) still needs them, because the Vendor model reads `password`
 * and `status` and uses SoftDeletes.
 *
 * Every column is guarded and nullable, so this is a no-op wherever the portal
 * migration already ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendors')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            foreach ([
                'password' => fn () => $table->string('password')->nullable(),
                'status' => fn () => $table->string('status', 20)->nullable(),
                'email_verified_at' => fn () => $table->timestamp('email_verified_at')->nullable(),
                'remember_token' => fn () => $table->rememberToken(),
                'approved_by' => fn () => $table->unsignedBigInteger('approved_by')->nullable(),
                'approved_at' => fn () => $table->timestamp('approved_at')->nullable(),
                'last_login_at' => fn () => $table->timestamp('last_login_at')->nullable(),
                'created_by' => fn () => $table->unsignedBigInteger('created_by')->nullable(),
                'updated_by' => fn () => $table->unsignedBigInteger('updated_by')->nullable(),
                'deleted_at' => fn () => $table->softDeletes(),
            ] as $column => $add) {
                if (! Schema::hasColumn('vendors', $column)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversible: dropping these would discard portal credentials that
        // the vendor portal authenticates against.
        throw new RuntimeException('add_portal_columns_to_vendors cannot be rolled back; restore a backup instead.');
    }
};
