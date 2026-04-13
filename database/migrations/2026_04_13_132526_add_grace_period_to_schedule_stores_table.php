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
        Schema::table('schedule_stores', function (Blueprint $table) {
            $table->smallInteger('grace_period_minutes')->default(30)->after('end_time');
        });

        // Also remove grace_period_minutes from schedules (it now lives on schedule_stores)
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('grace_period_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_stores', function (Blueprint $table) {
            $table->dropColumn('grace_period_minutes');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->smallInteger('grace_period_minutes')->default(30)->after('remarks');
        });
    }
};
