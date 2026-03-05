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
        Schema::table('tickets', function (Blueprint $blueprint) {
            $blueprint->foreignId('category_id')->nullable()->after('company_id')->constrained('categories')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['category_id']);
            $blueprint->dropColumn('category_id');
        });
    }
};
