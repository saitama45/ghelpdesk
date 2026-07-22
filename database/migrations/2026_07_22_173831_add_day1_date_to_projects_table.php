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
        Schema::table('projects', function (Blueprint $table) {
            // Anchor date for auto-scheduling: when a template is applied, each
            // milestone/activity/sub-task's Start/End Date is computed from this
            // date plus the template's lead-time (default_duration_days) chain.
            $table->date('day1_date')->nullable()->after('target_go_live');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('day1_date');
        });
    }
};
