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

            // Attendance
            'attendance.view' => 'View DTR',
            'attendance.logs' => 'View attendance logs',
            'attendance.create' => 'Can log attendance',
            
            // Tickets
            'tickets.view' => 'View tickets',
            'tickets.create' => 'Create tickets',
            'tickets.edit' => 'Edit tickets',
            'tickets.assign' => 'Assign tickets',
            'tickets.close' => 'Close tickets',
            
            // Users
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            
            // Roles & Permissions
            'roles.view' => 'View roles',
            'roles.create' => 'Create roles',
            'roles.edit' => 'Edit roles',
            
            // Reports
            'reports.view' => 'View reports',
            'reports.export' => 'Export reports',
            'reports.store_health' => 'View store health report',
            'reports.sla_performance' => 'View SLA performance report',

            // Companies
            'companies.view' => 'View companies',
            'companies.create' => 'Create companies',
            'companies.edit' => 'Edit companies',

            // Categories
            'categories.view' => 'View categories',
            'categories.create' => 'Create categories',
            'categories.edit' => 'Edit categories',

            // SubCategories
            'subcategories.view' => 'View sub-categories',
            'subcategories.create' => 'Create sub-categories',
            'subcategories.edit' => 'Edit sub-categories',

            // Items
            'items.view' => 'View items',
            'items.create' => 'Create items',
            'items.edit' => 'Edit items',

            // Request Types
            'request_types.view' => 'View request types',
            'request_types.create' => 'Create request types',
            'request_types.edit' => 'Edit request types',
            'request_types.delete' => 'Delete request types',

            // POS Requests
            'pos_requests.view' => 'View POS requests',
            'pos_requests.create' => 'Create POS requests',
            'pos_requests.edit' => 'Edit POS requests',
            'pos_requests.delete' => 'Delete POS requests',
            'pos_requests.approve' => 'Approve POS requests',

            // SAP Requests
            'sap_requests.view' => 'View SAP requests',
            'sap_requests.create' => 'Create SAP requests',
            'sap_requests.edit' => 'Edit SAP requests',
            'sap_requests.delete' => 'Delete SAP requests',
            'sap_requests.approve' => 'Approve SAP requests',

            // Stores
            'stores.view' => 'View stores',
            'stores.create' => 'Create stores',
            'stores.edit' => 'Edit stores',

            // Activity Templates
            'activity_templates.view' => 'View activity templates',
            'activity_templates.create' => 'Create activity templates',
            'activity_templates.edit' => 'Edit activity templates',
            'activity_templates.delete' => 'Delete activity templates',

            // Schedules
            'schedules.view' => 'View schedules',
            'schedules.create' => 'Create schedules',
            'schedules.edit' => 'Edit schedules',

            // Settings
            'settings.view' => 'View system settings',
            'settings.edit' => 'Edit system settings',

            // Canned Messages
            'canned_messages.view' => 'View canned messages',
            'canned_messages.create' => 'Create canned messages',
            'canned_messages.edit' => 'Edit canned messages',

            // Projects (NSO Tracker)
            'projects.view' => 'View project tracker',
            'projects.create' => 'Create new projects',
            'projects.edit' => 'Edit project details',
            'projects.delete' => 'Delete projects',
            'projects.manage_tasks' => 'Manage project tasks',
            'projects.manage_assets' => 'Manage project assets',

            // Presence
            'presence.view' => 'View online users and their status history',
        ];

        // Create permissions
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // Create roles
        $admin = Role::firstOrCreate(['name' => 'Admin'], ['landing_page' => 'dashboard']);
        $techSupport = Role::firstOrCreate(['name' => 'Tech Support'], ['landing_page' => 'tickets.index']);
        $user = Role::firstOrCreate(['name' => 'User'], ['landing_page' => 'dashboard']);

        // Update existing roles if they were already created without landing_page
        $admin->update(['landing_page' => 'dashboard']);
        $techSupport->update(['landing_page' => 'tickets.index']);
        $user->update(['landing_page' => 'dashboard']);

        // Assign permissions to roles
        $admin->givePermissionTo(Permission::all());
        
        $techSupport->givePermissionTo([
            'dashboard.view',
            'attendance.view', 'attendance.logs', 'attendance.create',
            'tickets.view', 'tickets.edit', 'tickets.assign', 'tickets.close',
            'users.view',
            'reports.view', 'reports.store_health', 'reports.sla_performance',
            'companies.view',
            'categories.view',
            'subcategories.view',
            'items.view',
            'request_types.view',
            'pos_requests.view',
            'pos_requests.create',
            'pos_requests.approve',
            'sap_requests.view',
            'sap_requests.create',
            'sap_requests.approve',
            'stores.view',
            'schedules.view',
            'canned_messages.view',
            'canned_messages.edit',
            'projects.view',
            'projects.manage_tasks',
            'projects.manage_assets',
            'presence.view',
        ]);
        
        $user->givePermissionTo([
            'dashboard.view',
            'attendance.view', 'attendance.create',
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
