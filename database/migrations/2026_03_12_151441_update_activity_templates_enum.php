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
        // SQL Server enum implementation is tricky to change.
        // We convert it to a standard string column for better flexibility.
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->string('store_class')->default('Regular')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            // No easy way to revert string back to enum/check constraint in SQL Server 
            // via Laravel change() if it originally failed. 
            // Keeping it as string is safer.
        });
    }
};
