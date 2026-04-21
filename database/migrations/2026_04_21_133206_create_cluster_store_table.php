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
        // 1. Create the pivot table
        Schema::create('cluster_store', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['cluster_id', 'store_id']);
        });

        // 2. Migrate existing data
        $existingLinks = DB::table('stores')
            ->whereNotNull('cluster_id')
            ->select('id as store_id', 'cluster_id')
            ->get();

        foreach ($existingLinks as $link) {
            DB::table('cluster_store')->insert([
                'cluster_id' => $link->cluster_id,
                'store_id' => $link->store_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Drop the old column
        Schema::table('stores', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['cluster_id']);
            $table->dropColumn('cluster_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->after('class')->constrained('clusters')->noActionOnDelete();
        });

        $links = DB::table('cluster_store')->get();
        foreach ($links as $link) {
            DB::table('stores')
                ->where('id', $link->store_id)
                ->update(['cluster_id' => $link->cluster_id]);
        }

        Schema::dropIfExists('cluster_store');
    }
};
