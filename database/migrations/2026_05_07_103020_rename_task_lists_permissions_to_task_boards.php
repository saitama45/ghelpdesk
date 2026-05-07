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
        // Rename permissions
        DB::table('permissions')
            ->where('name', 'like', 'task_lists.%')
            ->get()
            ->each(function ($permission) {
                DB::table('permissions')
                    ->where('id', $permission->id)
                    ->update(['name' => str_replace('task_lists.', 'task_boards.', $permission->name)]);
            });

        // Update landing_page in roles table if it uses task-lists.index
        DB::table('roles')
            ->where('landing_page', 'task-lists.index')
            ->update(['landing_page' => 'task-boards.index']);
            
        // Clean up any other potential task-lists landing pages
        DB::table('roles')
            ->where('landing_page', 'like', '%task-lists%')
            ->get()
            ->each(function ($role) {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['landing_page' => str_replace('task-lists', 'task-boards', $role->landing_page)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename permissions back
        DB::table('permissions')
            ->where('name', 'like', 'task_boards.%')
            ->get()
            ->each(function ($permission) {
                DB::table('permissions')
                    ->where('id', $permission->id)
                    ->update(['name' => str_replace('task_boards.', 'task_lists.', $permission->name)]);
            });

        // Update landing_page in roles table back
        DB::table('roles')
            ->where('landing_page', 'like', '%task-boards%')
            ->get()
            ->each(function ($role) {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['landing_page' => str_replace('task-boards', 'task-lists', $role->landing_page)]);
            });
    }
};
