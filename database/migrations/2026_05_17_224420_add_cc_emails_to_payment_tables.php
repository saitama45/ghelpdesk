<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_invoices', function (Blueprint $table) {
            $table->text('cc_emails')->nullable()->after('assignee_user_id');
        });

        Schema::table('payment_renewals', function (Blueprint $table) {
            $table->text('cc_emails')->nullable()->after('assignee_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_invoices', function (Blueprint $table) {
            $table->dropColumn('cc_emails');
        });

        Schema::table('payment_renewals', function (Blueprint $table) {
            $table->dropColumn('cc_emails');
        });
    }
};
