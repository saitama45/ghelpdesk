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
        Schema::table('form_definitions', function (Blueprint $table) {
            $table->string('workflow_type')->default('approval')->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_definitions', function (Blueprint $table) {
            $table->dropColumn('workflow_type');
        });
    }
};
