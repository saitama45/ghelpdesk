<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add ticket_id to schedule_stores
        Schema::table('schedule_stores', function (Blueprint $table) {
            $table->foreignUuid('ticket_id')->nullable()->after('store_id')->constrained('tickets')->noActionOnDelete();
        });

        // 2. Migrate existing data if any
        $schedules = DB::table('schedules')->whereNotNull('ticket_id')->get();
        foreach ($schedules as $schedule) {
            DB::table('schedule_stores')
                ->where('schedule_id', $schedule->id)
                ->limit(1)
                ->update(['ticket_id' => $schedule->ticket_id]);
        }

        // 3. Remove ticket_id from schedules
        Schema::table('schedules', function (Blueprint $table) {
            // Check if foreign key exists before dropping
            try {
                $table->dropForeign(['ticket_id']);
            } catch (\Exception $e) {
                // Ignore if it doesn't exist
            }
            $table->dropColumn('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
        });

        // Migrate back
        $stores = DB::table('schedule_stores')->whereNotNull('ticket_id')->get();
        foreach ($stores as $store) {
            DB::table('schedules')
                ->where('id', $store->schedule_id)
                ->update(['ticket_id' => $store->ticket_id]);
        }

        Schema::table('schedule_stores', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropColumn('ticket_id');
        });
    }
};
