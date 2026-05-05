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
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->foreignId('parent_activity_template_id')
                ->nullable()
                ->after('project_template_id')
                ->constrained('activity_templates')
                ->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->dropForeign(['parent_activity_template_id']);
            $table->dropColumn('parent_activity_template_id');
        });
    }
};
