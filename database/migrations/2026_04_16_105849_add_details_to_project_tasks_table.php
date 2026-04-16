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
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->string('asset_item')->nullable()->after('category');
            $table->string('model_specs')->nullable()->after('asset_item');
            $table->integer('qty')->default(1)->after('model_specs');
            $table->string('responsible')->nullable()->after('qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn(['asset_item', 'model_specs', 'qty', 'responsible']);
        });
    }
};
