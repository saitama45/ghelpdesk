<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename Tables (Removing dbo. as it might be default or causing quote issues)
        Schema::rename('table_definitions', 'form_definitions');
        Schema::rename('table_records', 'form_records');
        Schema::rename('table_record_approvals', 'form_record_approvals');

        // 2. Rename Foreign Key Columns
        Schema::table('form_records', function (Blueprint $table) {
            $table->renameColumn('table_definition_id', 'form_definition_id');
        });

        Schema::table('form_record_approvals', function (Blueprint $table) {
            $table->renameColumn('table_record_id', 'form_record_id');
        });

        // 3. Update Permissions in DB
        DB::table('permissions')->where('name', 'like', 'table_builder.%')->get()->each(function ($permission) {
            $newName = str_replace('table_builder.', 'form_builder.', $permission->name);
            DB::table('permissions')->where('id', $permission->id)->update(['name' => $newName]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Revert Permissions in DB
        DB::table('permissions')->where('name', 'like', 'form_builder.%')->get()->each(function ($permission) {
            $newName = str_replace('form_builder.', 'table_builder.', $permission->name);
            DB::table('permissions')->where('id', $permission->id)->update(['name' => $newName]);
        });

        // 2. Revert Foreign Key Columns
        Schema::table('form_record_approvals', function (Blueprint $table) {
            $table->renameColumn('form_record_id', 'table_record_id');
        });

        Schema::table('form_records', function (Blueprint $table) {
            $table->renameColumn('form_definition_id', 'table_definition_id');
        });

        // 3. Revert Tables
        Schema::rename('form_record_approvals', 'table_record_approvals');
        Schema::rename('form_records', 'table_records');
        Schema::rename('form_definitions', 'table_definitions');
    }
};
