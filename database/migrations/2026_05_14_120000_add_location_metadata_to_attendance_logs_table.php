<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('location_accuracy', 8, 2)->nullable()->after('longitude');
            $table->string('location_client', 16)->nullable()->after('location_accuracy');
            $table->string('location_provider', 32)->nullable()->after('location_client');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['location_accuracy', 'location_client', 'location_provider']);
        });
    }
};
