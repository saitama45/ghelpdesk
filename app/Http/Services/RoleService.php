<?php

namespace App\Http\Services;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Cache;

class RoleService
{
    protected const ACTION_ORDER = ['view', 'show', 'create', 'edit', 'assign', 'resolve', 'close', 'post', 'delete', 'approve', 'canned_messages', 'internal_notes'];

    /**
     * Get all roles with their permissions
     */
    public static function getAllRoles()
    {
        return Role::with('permissions')->get();
    }

    /**
     * Get all permissions
     */
    public static function getAllPermissions()
    {
        return Permission::all();
    }

    /**
     * Get permissions grouped by category
     */
    public static function getPermissionsByCategory()
    {
        $permissions = Permission::all()->pluck('name')->toArray();

        foreach (['tickets.resolve'] as $permissionName) {
            if (!in_array($permissionName, $permissions, true)) {
                $permissions[] = $permissionName;
            }
        }

        // Add dynamic form permissions if they don't exist in DB yet
        $dynamicForms = \App\Models\FormDefinition::all();
        foreach ($dynamicForms as $form) {
            foreach (['view', 'show', 'create', 'edit', 'delete', 'approve'] as $action) {
                $permName = "{$form->slug}.{$action}";
                if (!in_array($permName, $permissions)) {
                    $permissions[] = $permName;
                }
            }
        }

        $grouped = [];
        // Keep permission categories aligned with sidebar and role-management groups.
        $preferredOrder = [
            'dashboard',
            'projects',
            'tickets',
            'task board',
            'pos_requests',
            'sap_requests',
            'stock in',
            'stock transfer',
            'receiving stock',
            'assets',
            'reports',
            'npc status',
            'payments',
            'loyalty stamps',
            'service vehicle trips',
            'attendance',
            'schedules',
            'presence',
            'kb articles',
            'users',
            'roles',
            'companies',
            'departments',
            'clusters',
            'categories',
            'subcategories',
            'items',
            'request_types',
            'form_builder',
            'stores',
            'vendors',
            'activity_templates',
            'project type & store class',
            'settings',
            'canned_messages',
            'leadership_points',
        ];

        foreach ($permissions as $permissionName) {
            $category = explode('.', $permissionName)[0];

            // Check if this is a dynamic form slug
            $form = $dynamicForms->firstWhere('slug', $category);
            
            // If it's a dynamic form, use its name. Otherwise, capitalize the category.
            // We use the raw category for keys that are special like 'Pos_requests'
            if ($category === 'kb_articles') {
                $categoryDisplay = 'KB Articles';
            } elseif ($category === 'npc_status') {
                $categoryDisplay = 'NPC Status';
            } elseif ($category === 'task_boards') {
                $categoryDisplay = 'Task Board';
            } elseif ($category === 'stock_ins') {
                $categoryDisplay = 'Stock In';
            } elseif ($category === 'stock_transfers') {
                $categoryDisplay = 'Stock Transfer';
            } elseif ($category === 'stock_receivings') {
                $categoryDisplay = 'Receiving Stock';
            } elseif ($category === 'service_vehicle_trips') {
                $categoryDisplay = 'Service Vehicle Trips';
            } elseif ($category === 'payments') {
                $categoryDisplay = 'Payments & SOA';
            } elseif ($category === 'stamps') {
                $categoryDisplay = 'Loyalty Stamps';
            } elseif ($category === 'leadership_points') {
                $categoryDisplay = 'Leadership Points';
            } elseif ($category === 'reference_options') {
                $categoryDisplay = 'Project Type & Store Class';
            } else {
                $categoryDisplay = $form ? $form->name : (
                    in_array(strtolower($category), ['pos_requests', 'sap_requests', 'request_types', 'activity_templates', 'canned_messages', 'form_builder']) 
                    ? ucfirst($category) 
                    : ucfirst(str_replace('_', ' ', $category))
                );
            }

            $grouped[$categoryDisplay][] = (object)[
                'id' => $permissionName,
                'name' => $permissionName
            ];
        }

        foreach ($grouped as &$categoryPermissions) {
            usort($categoryPermissions, function ($a, $b) {
                $aAction = explode('.', $a->name)[1] ?? '';
                $bAction = explode('.', $b->name)[1] ?? '';
                $aIndex = array_search($aAction, self::ACTION_ORDER, true);
                $bIndex = array_search($bAction, self::ACTION_ORDER, true);

                $aIndex = $aIndex === false ? PHP_INT_MAX : $aIndex;
                $bIndex = $bIndex === false ? PHP_INT_MAX : $bIndex;

                return $aIndex <=> $bIndex ?: strcmp($a->name, $b->name);
            });
        }
        unset($categoryPermissions);

        uksort($grouped, function ($a, $b) use ($preferredOrder) {
            $aIndex = array_search(strtolower($a), $preferredOrder, true);
            $bIndex = array_search(strtolower($b), $preferredOrder, true);

            $aIndex = $aIndex === false ? PHP_INT_MAX : $aIndex;
            $bIndex = $bIndex === false ? PHP_INT_MAX : $bIndex;

            return $aIndex <=> $bIndex ?: strcasecmp($a, $b);
        });

        return $grouped;
    }

    /**
     * Create a new role with permissions
     */
    public static function createRole($name, $permissions = [], $landingPage = 'dashboard')
    {
        $role = Role::create([
            'name' => $name,
            'landing_page' => $landingPage
        ]);
        
        if (!empty($permissions)) {
            // Ensure all permissions exist in DB first
            foreach ($permissions as $permName) {
                Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
            }
            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
        
        return $role;
    }

    /**
     * Update role permissions
     */
    public static function updateRolePermissions($roleId, $permissions)
    {
        $role = Role::findById($roleId);
        
        // Ensure all permissions exist in DB first
        foreach ($permissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
        }
        
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forever('permissions_version', now()->timestamp);
        
        return $role;
    }

    /**
     * Check if user has permission
     */
    public static function userHasPermission($user, $permission)
    {
        return $user->can($permission);
    }

    /**
     * Get user roles
     */
    public static function getUserRoles($user)
    {
        return $user->roles;
    }

    /**
     * Assign role to user
     */
    public static function assignRoleToUser($user, $roleName)
    {
        return $user->assignRole($roleName);
    }

    /**
     * Remove role from user
     */
    public static function removeRoleFromUser($user, $roleName)
    {
        return $user->removeRole($roleName);
    }
}
