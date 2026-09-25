<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds a member's real email address while their account is archived.
 *
 * `users.email` carries a UNIQUE index, and archiving is a soft delete — the
 * row keeps the address, so the address stays taken forever. A member who
 * deleted their account could never sign up again with the same email, which
 * is exactly what an App Store reviewer does after testing account deletion
 * ("The email has already been taken").
 *
 * `AccountArchiveService` now parks the address here and writes a tombstone
 * into `email` instead, freeing it for a fresh registration and putting it
 * back if the account is ever restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'archived_email')) {
            Schema::table('users', function (Blueprint $table) {
                // Deliberately NOT unique: several archived accounts may have
                // held the same address over time, one after another.
                $table->string('archived_email')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'archived_email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('archived_email');
            });
        }
    }
};
