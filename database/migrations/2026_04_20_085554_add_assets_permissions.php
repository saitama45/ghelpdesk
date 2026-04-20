<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            'assets.view',
            'assets.create',
            'assets.edit',
            'assets.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Give all permissions to Admin
        $adminRole = Role::where('name', 'Admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        // Give view permission to Tech Support
        $techSupportRole = Role::where('name', 'Tech Support')->first();
        if ($techSupportRole) {
            $techSupportRole->givePermissionTo('assets.view');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::whereIn('name', [
            'assets.view',
            'assets.create',
            'assets.edit',
            'assets.delete',
        ])->delete();
    }
};
