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
        // For SQL Server, we must drop the check constraint first.
        // The error message told us the name: CK__request_t__reque__638F8109
        try {
            DB::statement('ALTER TABLE request_types DROP CONSTRAINT CK__request_t__reque__638F8109');
        } catch (\Exception $e) {
            // Constraint might have a different name in other environments or already be dropped
        }

        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('request_for');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->json('request_for')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('request_for');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->enum('request_for', ['SAP', 'POS'])->nullable();
        });
    }
};
