<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the vendor portal's contacts and bank accounts, for the same reason
 * as the profile and document tables: the portal creates them on the shared
 * database, but a standalone ghelpdesk database (and every test run) still has
 * to satisfy the models. Both creates are guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portal_vendor_contacts')) {
            Schema::create('portal_vendor_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->string('name');
                $table->string('position')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('portal_vendor_bank_accounts')) {
            return;
        }

        Schema::create('portal_vendor_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->string('account_name');
            $table->string('account_number');
            $table->string('currency', 3)->nullable();
            $table->boolean('is_default')->default(false);
            // Payments are released against these, so they are verified first.
            $table->string('approval_status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: on the shared database these belong to
        // the vendor portal and hold vendors' own banking details.
        throw new RuntimeException('create_portal_vendor_contact_and_bank_tables cannot be rolled back — the portal owns these tables.');
    }
};
