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
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['business_start_time', 'business_end_time', 'working_days']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->time('business_start_time')->default('08:00:00')->after('resolution_time_hours');
            $table->time('business_end_time')->default('17:00:00')->after('business_start_time');
            $table->json('working_days')->nullable()->after('business_end_time');
        });
    }
};
