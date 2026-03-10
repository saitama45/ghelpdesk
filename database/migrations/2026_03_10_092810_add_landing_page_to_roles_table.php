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
        Schema::table('roles', function (Blueprint $schema) {
            // Using Blueprint $schema instead of default name if needed, but standard is fine
        });
        
        // Sometime spatie roles table might have different schema, but usually it's just 'roles'
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'landing_page')) {
                $table->string('landing_page')->nullable()->after('name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'landing_page')) {
                $table->dropColumn('landing_page');
            }
        });
    }
};
