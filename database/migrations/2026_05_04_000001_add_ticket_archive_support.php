<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tickets', 'is_deleted')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->boolean('is_deleted')->default(false);
            });

            Schema::table('tickets', function (Blueprint $table) {
                $table->index(['is_deleted', 'deleted_at'], 'tickets_archive_lookup_index');
            });
        }

        DB::table('tickets')
            ->whereNotNull('deleted_at')
            ->update(['is_deleted' => true]);

        DB::table('settings')->updateOrInsert(
            ['key' => 'ticket_retention_value'],
            ['value' => '6', 'group' => 'ticket_retention', 'updated_at' => now(), 'created_at' => now()]
        );

        DB::table('settings')->updateOrInsert(
            ['key' => 'ticket_retention_unit'],
            ['value' => 'months', 'group' => 'ticket_retention', 'updated_at' => now(), 'created_at' => now()]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'tickets.delete']);

        $admin = Role::where('name', 'Admin')->first();
        if ($admin) {
            $admin->givePermissionTo($permission);
        }

        Cache::forget('permissions_version');
        Cache::increment('permissions_version');
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', 'tickets.delete')->delete();

        DB::table('settings')
            ->whereIn('key', ['ticket_retention_value', 'ticket_retention_unit'])
            ->delete();

        if (Schema::hasColumn('tickets', 'is_deleted')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropIndex('tickets_archive_lookup_index');
                $table->dropColumn('is_deleted');
            });
        }

        Cache::forget('permissions_version');
        Cache::increment('permissions_version');
    }
};
