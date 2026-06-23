<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_type')->default('Store Opening')->after('name');
            $table->string('subject_type')->nullable()->after('store_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id'], 'projects_subject_index');
        });

        // Make store_id nullable for non-store project types
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_subject_index');
            $table->dropColumn(['project_type', 'subject_type', 'subject_id']);
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });
    }
};
