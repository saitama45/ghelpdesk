<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);

        // Define help desk permissions
        $permissions = [
            // Dashboard
            'dashboard.view' => 'View dashboard',
            'dashboard.filter_entity' => 'Filter the dashboard by entity/company',

            // Tickets
            'tickets.view' => 'View tickets',
            'tickets.filter_entity' => 'Filter tickets by entity/company',
            'tickets.create' => 'Create tickets',
            'tickets.edit' => 'Edit tickets',
            'tickets.assign' => 'Assign tickets',
            'tickets.resolve' => 'Resolve tickets',
            'tickets.close' => 'Close tickets',
            'tickets.delete' => 'Archive and purge tickets',
            'tickets.canned_messages' => 'Use canned messages',
            'tickets.internal_notes' => 'Use internal notes',

            // Queue Management
            'queue.view' => 'View the queue board',
            'queue.operate' => 'Call next / serve from the queue',

            // Task Board
            'task_boards.view' => 'View task boards',
            'task_boards.create' => 'Create task boards',
            'task_boards.edit' => 'Edit task cards and boards',
            'task_boards.delete' => 'Close and delete task boards/cards',
            'task_boards.manage_members' => 'Manage task board members',
            
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
            'reports.assignee_performance' => 'View assignee performance report',
            'reports.inventory' => 'View inventory report',

            // Companies
            'companies.view' => 'View companies',
            'companies.create' => 'Create companies',
            'companies.edit' => 'Edit companies',

            // Departments
            'departments.view' => 'View departments',
            'departments.create' => 'Create departments',
            'departments.edit' => 'Edit departments',
            'departments.delete' => 'Delete departments',

            // Clusters
            'clusters.view' => 'View clusters',
            'clusters.create' => 'Create clusters',
            'clusters.edit' => 'Edit clusters',
            'clusters.delete' => 'Delete clusters',

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

            // Assets
            'assets.view' => 'View assets',
            'assets.create' => 'Create assets',
            'assets.edit' => 'Edit assets',
            'assets.delete' => 'Delete assets',

            // Request Types
            'request_types.view' => 'View request types',
            'request_types.create' => 'Create request types',
            'request_types.edit' => 'Edit request types',
            'request_types.delete' => 'Delete request types',

            // Form Builder
            'form_builder.view' => 'View form builder',
            'form_builder.create' => 'Create custom forms',
            'form_builder.edit' => 'Edit custom forms',
            'form_builder.delete' => 'Delete custom forms',

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

            // Services - Loyalty Stamps
            'stamps.view' => 'View loyalty stamps, customers & cards',
            'stamps.create' => 'Create customers, programs, cards & add stamps',
            'stamps.edit' => 'Edit customers & stamp programs',
            'stamps.delete' => 'Delete customers, programs & cards',
            'stamps.redeem' => 'Redeem completed cards (deducts inventory)',

            // Stock In
            'stock_ins.view' => 'View Stock In',
            'stock_ins.create' => 'Create Stock In',
            'stock_ins.edit' => 'Edit Stock In',
            'stock_ins.post' => 'Post Stock In',
            'stock_ins.delete' => 'Delete Stock In',

            // Stock Transfer
            'stock_transfers.view' => 'View Stock Transfer',
            'stock_transfers.create' => 'Create Stock Transfer',
            'stock_transfers.edit' => 'Edit Stock Transfer',
            'stock_transfers.post' => 'Post Stock Transfer',
            'stock_transfers.delete' => 'Delete Stock Transfer',

            // Receiving Stock
            'stock_receivings.view' => 'View Receiving Stock',
            'stock_receivings.edit' => 'Edit Receiving Stock',
            'stock_receivings.post' => 'Post Receiving Stock',
            'stock_receivings.delete' => 'Delete Receiving Stock',

            // Service Vehicle Trips
            'service_vehicle_trips.view'    => 'View Service Vehicle Trips',
            'service_vehicle_trips.create'  => 'Book Service Vehicle Trip',
            'service_vehicle_trips.edit'    => 'Edit Service Vehicle Trip',
            'service_vehicle_trips.delete'  => 'Delete Service Vehicle Trip',
            'service_vehicle_trips.approve' => 'Approve / Reject Service Vehicle Trip',

            // Administrative - Attendance
            'attendance.view' => 'View DTR',
            'attendance.logs' => 'View attendance logs',
            'attendance.create' => 'Can log attendance',

            // Monitoring - NPC Status
            'npc_status.view' => 'View NPC Status',
            'npc_status.create' => 'Create NPC Status records',
            'npc_status.edit' => 'Edit NPC Status records',
            'npc_status.delete' => 'Delete NPC Status records',
            'npc_status.download' => 'Download assigned store seals (store users)',
            'npc_status.reveal_password' => 'Reveal registered account passwords',

            // CCTV Monitoring
            'cctv_monitoring.view' => 'View CCTV Monitoring',
            'cctv_monitoring.create' => 'Create CCTV inspections',
            'cctv_monitoring.edit' => 'Edit CCTV inspections',
            'cctv_monitoring.delete' => 'Delete CCTV inspections',

            // Monitoring - WIGS (Wildly Important Goals)
            'wigs.view' => 'View WIGS (records scoped to self + org subtree)',
            'wigs.create' => 'Create a PCF (Performance Commitment Form)',
            'wigs.edit' => 'Edit a PCF and enter PAF quarterly grades',
            'wigs.delete' => 'Delete a PCF',
            'wigs.manage_all' => 'View/manage all WIGS records (bypass hierarchy scope)',
            'wigs.manage_yardstick' => 'Edit the Yardstick reference configuration',

            // Administrative - Schedules
            'schedules.view' => 'View schedules',
            'schedules.create' => 'Create schedules',
            'schedules.edit' => 'Edit schedules',
            'schedules.approve' => 'Approve schedule change requests',
            'schedules.delete' => 'Delete schedules',

            // Administrative - Presence
            'presence.view' => 'View online users and their status history',

            // Administrative - KB Articles
            'kb_articles.view' => 'View KB articles',
            'kb_articles.create' => 'Create KB articles',
            'kb_articles.edit' => 'Edit KB articles',
            'kb_articles.delete' => 'Delete KB articles',

            // Stores
            'stores.view' => 'View stores',
            'stores.create' => 'Create stores',
            'stores.edit' => 'Edit stores',

            // Vendors
            'vendors.view'   => 'View vendors',
            'vendors.create' => 'Create vendors',
            'vendors.edit'   => 'Edit vendors',
            'vendors.delete' => 'Delete vendors',

            // Activity Templates
            'activity_templates.view' => 'View activity templates',
            'activity_templates.create' => 'Create activity templates',
            'activity_templates.edit' => 'Edit activity templates',
            'activity_templates.delete' => 'Delete activity templates',

            // Reference Options (Project Type & Store Class lookup values)
            'reference_options.create' => 'Add project type / store class options',
            'reference_options.edit' => 'Edit project type / store class options',
            'reference_options.delete' => 'Delete project type / store class options',

            // Settings
            'settings.view' => 'View system settings',
            'settings.edit' => 'Edit system settings',

            // Leadership Points Settings
            'leadership_points.view' => 'View leadership points settings',
            'leadership_points.edit' => 'Edit leadership points settings',

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

            // Monitoring - Payments & SOA
            'payments.view' => 'View payments & SOA records',
            'payments.create' => 'Create payment records',
            'payments.edit' => 'Edit payment records',
            'payments.delete' => 'Delete payment records',
            'payments.submit' => 'Submit payment record for approval',
            'payments.approve' => 'Approve payment record',
            'payments.mark_paid' => 'Mark payment record as paid',
            'payments.manage_vendors' => 'Manage payment vendor settings',
            'payments.manage_settings' => 'Manage payment reminder & approval settings',

            // Monitoring - Mall Hookup
            'mall_hookup.view' => 'View Mall Hookup monitoring',
            'mall_hookup.create' => 'Add stores & import status logs',
            'mall_hookup.edit' => 'Edit locations & record daily statuses',
            'mall_hookup.delete' => 'Remove stores from monitoring',

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
        Role::where('name', 'Solutions Admin')->first()?->givePermissionTo(Permission::all());
        
        $techSupport->givePermissionTo([
            'dashboard.view',
            'tickets.view', 'tickets.edit', 'tickets.assign', 'tickets.resolve', 'tickets.close', 'tickets.canned_messages', 'tickets.internal_notes',
            'queue.view', 'queue.operate',
            'task_boards.view', 'task_boards.create', 'task_boards.edit', 'task_boards.manage_members',
            'attendance.view', 'attendance.logs', 'attendance.create',
            'users.view',
            'reports.view', 'reports.store_health', 'reports.sla_performance', 'reports.assignee_performance', 'reports.inventory',
            'companies.view',
            'departments.view',
            'clusters.view',
            'categories.view',
            'subcategories.view',
            'items.view',
            'assets.view',
            'request_types.view',
            'pos_requests.view',
            'pos_requests.create',
            'pos_requests.approve',
            'sap_requests.view',
            'sap_requests.create',
            'sap_requests.approve',
            'stock_ins.view',
            'stock_ins.create',
            'stock_ins.edit',
            'stock_ins.post',
            'stock_ins.delete',
            'stock_transfers.view',
            'stock_transfers.create',
            'stock_transfers.edit',
            'stock_transfers.post',
            'stock_transfers.delete',
            'stock_receivings.view',
            'stock_receivings.edit',
            'stock_receivings.post',
            'stock_receivings.delete',
            'service_vehicle_trips.view',
            'service_vehicle_trips.create',
            'service_vehicle_trips.edit',
            'service_vehicle_trips.delete',
            'service_vehicle_trips.approve',
            'stores.view',
            'schedules.view',
            'canned_messages.view',
            'canned_messages.edit',
            'leadership_points.view',
            'leadership_points.edit',
            'projects.view',
            'projects.manage_tasks',
            'projects.manage_assets',
            'presence.view',
            'payments.view',
            'payments.create',
            'payments.edit',
            'payments.submit',
            'mall_hookup.view',
            'mall_hookup.create',
            'mall_hookup.edit',
            'stamps.view',
            'stamps.create',
            'stamps.edit',
            'stamps.redeem',
        ]);
        
        $user->givePermissionTo([
            'dashboard.view',
            'tickets.view', 'tickets.create',
            'task_boards.view', 'task_boards.edit',
            'attendance.view', 'attendance.create',
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

        // Check if audit columns exist before filling them
        if (Schema::hasColumns('users', ['created_by', 'updated_by'])) {
            $adminUser->forceFill([
                'created_by' => $adminUser->created_by ?: $adminUser->id,
                'updated_by' => $adminUser->updated_by ?: $adminUser->id,
            ])->save();
        }

        $techSupportUser = User::firstOrCreate(
            ['email' => 'support@gmail.com'],
            [
                'name' => 'Tech Support',
                'password' => Hash::make('support123'),
                'department' => 'IT Support',
                'position' => 'Support Engineer',
                'email_verified_at' => now(),
            ]
        );
        $techSupportUser->assignRole('Tech Support');

        if (Schema::hasColumns('users', ['created_by', 'updated_by'])) {
            $techSupportUser->forceFill([
                'created_by' => $techSupportUser->created_by ?: $adminUser->id,
                'updated_by' => $techSupportUser->updated_by ?: $adminUser->id,
            ])->save();
        }

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

        if (Schema::hasColumns('users', ['created_by', 'updated_by'])) {
            $regularUser->forceFill([
                'created_by' => $regularUser->created_by ?: $adminUser->id,
                'updated_by' => $regularUser->updated_by ?: $adminUser->id,
            ])->save();
        }

        $this->command->info('Roles and permissions created successfully!');
        $this->command->info('  - Admin: admin@gmail.com / admin123');
        $this->command->info('  - Tech Support: support@gmail.com / support123');
        $this->command->info('  - User: user@gmail.com / user123');
    }
}
