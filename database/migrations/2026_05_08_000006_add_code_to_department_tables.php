<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });

        Schema::table('department_sections', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });

        Schema::table('department_units', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });

        Schema::table('department_sub_units', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('department_sections', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('department_units', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('department_sub_units', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
