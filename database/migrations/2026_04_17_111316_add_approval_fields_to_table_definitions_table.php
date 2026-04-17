<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function run(): void
    {
        Schema::table('table_definitions', function (Blueprint $blueprint) {
            $blueprint->integer('approval_levels')->default(0);
            $blueprint->json('approver_matrix')->nullable();
            $blueprint->text('cc_emails')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_definitions', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['approval_levels', 'approver_matrix', 'cc_emails']);
        });
    }
};
