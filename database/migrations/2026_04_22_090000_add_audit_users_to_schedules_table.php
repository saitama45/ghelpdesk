<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('user_id');
            $table->foreignId('updated_by')->nullable()->after('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
        });

        // Best-effort backfill for legacy rows created before audit users were stored.
        DB::table('schedules')
            ->whereNull('created_by')
            ->update(['created_by' => DB::raw('user_id')]);

        DB::table('schedules')
            ->whereNull('updated_by')
            ->update(['updated_by' => DB::raw('user_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
