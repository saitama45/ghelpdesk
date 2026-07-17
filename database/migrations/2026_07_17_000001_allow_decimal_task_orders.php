<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->decimal('order', 10, 2)->default(0)->change();
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->decimal('order', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            $table->integer('order')->default(0)->change();
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->integer('order')->default(0)->change();
        });
    }
};
