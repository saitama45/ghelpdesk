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
            $table->foreignId('project_template_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->renameColumn('name', 'activity');
            $table->renameColumn('category', 'milestone');
            $table->string('asset_item')->nullable()->after('milestone');
            $table->string('model_specs')->nullable()->after('asset_item');
            $table->integer('qty')->default(1)->after('model_specs');
            $table->string('responsible')->nullable()->after('qty');
            $table->dropColumn('store_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->string('store_class')->default('Regular')->after('id');
            $table->dropColumn(['project_template_id', 'asset_item', 'model_specs', 'qty', 'responsible']);
            $table->renameColumn('activity', 'name');
            $table->renameColumn('milestone', 'category');
        });
    }
};
