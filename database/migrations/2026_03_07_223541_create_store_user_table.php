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
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // Migrate existing user_id from stores table to pivot table
        $existingAssignments = DB::table('stores')->whereNotNull('user_id')->get();
        foreach ($existingAssignments as $assignment) {
            DB::table('store_user')->insert([
                'store_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Remove the old column
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
        });

        // Optional: migrate back data from pivot to stores (if multiple users, pick first)
        $assignments = DB::table('store_user')->get();
        foreach ($assignments as $assignment) {
            DB::table('stores')->where('id', $assignment->store_id)->update(['user_id' => $assignment->user_id]);
        }

        Schema::dropIfExists('store_user');
    }
};
