<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a self-registered mobile-app member's `users` row back to their
 * `customers` CRM record (Loyalty Stamps module) — see
 * `Api\RegisterController`. A member has no role assigned (zero rows in
 * Spatie's `model_has_roles`), so they're invisible in the staff
 * User Management screen's normal listings without needing a separate flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
