<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Define help desk permissions
        $permissions = [
            // Dashboard
            'dashboard.view' => 'View dashboard',
            
            // Tickets
            'tickets.view' => 'View tickets',
            'tickets.create' => 'Create tickets',
            'tickets.edit' => 'Edit tickets',
            'tickets.delete' => 'Delete tickets',
            'tickets.assign' => 'Assign tickets',
            'tickets.close' => 'Close tickets',
            
            // Users
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            'users.delete' => 'Delete users',
            
            // Roles & Permissions
            'roles.view' => 'View roles',
            'roles.create' => 'Create roles',
            'roles.edit' => 'Edit roles',
            'roles.delete' => 'Delete roles',
            
            // Reports
            'reports.view' => 'View reports',
            'reports.export' => 'Export reports',

            // Companies
            'companies.view' => 'View companies',
            'companies.create' => 'Create companies',
            'companies.edit' => 'Edit companies',
            'companies.delete' => 'Delete companies',
        ];

        // Create permissions
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // Create roles
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $techSupport = Role::firstOrCreate(['name' => 'Tech Support']);
        $user = Role::firstOrCreate(['name' => 'User']);

        // Assign permissions to roles
        $admin->givePermissionTo(Permission::all());
        
        $techSupport->givePermissionTo([
            'dashboard.view',
            'tickets.view', 'tickets.edit', 'tickets.assign', 'tickets.close',
            'users.view',
            'companies.view',
        ]);
        
        $user->givePermissionTo([
            'dashboard.view',
            'tickets.view', 'tickets.create',
        ]);

        // Create default users
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('admin123'),
                'department' => 'IT',
                'position' => 'System Administrator',
                'email_verified_at' => now(),
            ]
        );
        $adminUser->assignRole('Admin');

        $techUser = User::firstOrCreate(
            ['email' => 'support@gmail.com'],
            [
                'name' => 'Tech Support',
                'password' => Hash::make('support123'),
                'department' => 'IT Support',
                'position' => 'Support Engineer',
                'email_verified_at' => now(),
            ]
        );
        $techUser->assignRole('Tech Support');

        $regularUser = User::firstOrCreate(
            ['email' => 'user@gmail.com'],
            [
                'name' => 'Regular User',
                'password' => Hash::make('user123'),
                'department' => 'Sales',
                'position' => 'Sales Associate',
                'email_verified_at' => now(),
            ]
        );
        $regularUser->assignRole('User');

        $this->command->info('✅ Roles and permissions created successfully!');
        $this->command->info('  - Admin: admin@gmail.com / admin123');
        $this->command->info('  - Tech Support: support@gmail.com / support123');
        $this->command->info('  - User: user@gmail.com / user123');
    }
}
