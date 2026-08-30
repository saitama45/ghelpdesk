<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_brand', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_company_id')->index();
            $table->unsignedBigInteger('brand_company_id')->index();
            $table->timestamps();
            $table->unique(['entity_company_id', 'brand_company_id']);
        });

        Schema::table('project_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_company_id')->nullable()->index();
            $table->unsignedBigInteger('brand_company_id')->nullable()->index();
            $table->string('project_name')->nullable();
        });

        Schema::table('activity_templates', function (Blueprint $table) {
            $table->string('activity_mode', 30)->default('standard');
            $table->decimal('milestone_weight', 7, 2)->nullable();
            $table->decimal('activity_weight', 7, 2)->nullable();
            $table->decimal('sub_task_weight', 7, 2)->nullable();
            $table->text('acceptance_criteria')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_company_id')->nullable()->index();
            $table->unsignedInteger('target_store_count')->nullable();
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->string('activity_mode', 30)->default('standard');
            $table->decimal('milestone_weight', 7, 2)->nullable();
            $table->decimal('activity_weight', 7, 2)->nullable();
            $table->decimal('sub_task_weight', 7, 2)->nullable();
            $table->text('acceptance_criteria')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'activity_mode', 'milestone_weight', 'activity_weight',
                'sub_task_weight', 'acceptance_criteria',
            ]);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['brand_company_id']);
            $table->dropColumn(['brand_company_id', 'target_store_count']);
        });

        Schema::table('activity_templates', function (Blueprint $table) {
            $table->dropColumn([
                'activity_mode', 'milestone_weight', 'activity_weight',
                'sub_task_weight', 'acceptance_criteria',
            ]);
        });

        Schema::table('project_templates', function (Blueprint $table) {
            $table->dropIndex(['entity_company_id']);
            $table->dropIndex(['brand_company_id']);
            $table->dropColumn(['entity_company_id', 'brand_company_id', 'project_name']);
        });

        Schema::dropIfExists('entity_brand');
    }
};
