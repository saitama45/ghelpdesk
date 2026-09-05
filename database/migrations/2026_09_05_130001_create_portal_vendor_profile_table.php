<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the vendor portal's company-profile table for the same reason the
 * document tables are mirrored: linkportal creates it on the shared database,
 * but a standalone ghelpdesk database (and every test run) still has to satisfy
 * `App\Models\VendorProfile`. Guarded, so it is a no-op where the portal ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portal_vendor_profiles')) {
            return;
        }

        Schema::create('portal_vendor_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('rdo_code', 10)->nullable();
            $table->string('business_type', 50)->nullable();
            $table->string('vat_type', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('zip_code', 10)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('website')->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->string('currency', 3)->nullable();
            // Staged edits awaiting an approver — the maker-checker half.
            $table->text('pending_changes')->nullable();
            $table->string('approval_status', 20)->default('draft');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: on the shared database this table belongs
        // to the vendor portal and holds vendors' own profile data.
        throw new RuntimeException('create_portal_vendor_profile_table cannot be rolled back — the portal owns this table.');
    }
};
