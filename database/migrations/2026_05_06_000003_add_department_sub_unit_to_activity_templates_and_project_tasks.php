<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_templates', 'department')) {
                $table->string('department')->nullable()->after('responsible');
            }

            if (!Schema::hasColumn('activity_templates', 'sub_unit')) {
                $table->string('sub_unit')->nullable()->after('department');
            }
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('project_tasks', 'department')) {
                $table->string('department')->nullable()->after('responsible');
            }

            if (!Schema::hasColumn('project_tasks', 'sub_unit')) {
                $table->string('sub_unit')->nullable()->after('department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('project_tasks', 'sub_unit')) {
                $table->dropColumn('sub_unit');
            }

            if (Schema::hasColumn('project_tasks', 'department')) {
                $table->dropColumn('department');
            }
        });

        Schema::table('activity_templates', function (Blueprint $table) {
            if (Schema::hasColumn('activity_templates', 'sub_unit')) {
                $table->dropColumn('sub_unit');
            }

            if (Schema::hasColumn('activity_templates', 'department')) {
                $table->dropColumn('department');
            }
        });
    }
};
