<?php

namespace App\Http\Services;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleService
{
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
        $preferredOrder = [
            'dashboard',
            'attendance',
            'tickets',
            'users',
            'roles',
            'reports',
            'companies',
            'clusters',
            'categories',
            'subcategories',
            'items',
            'assets',
            'request_types',
            'form_builder',
            'pos_requests',
            'sap_requests',
            'stores',
            'vendors',
            'activity_templates',
            'schedules',
            'settings',
            'canned_messages',
            'projects',
            'presence',
            'kb articles',
        ];

        foreach ($permissions as $permissionName) {
            $category = explode('.', $permissionName)[0];

            // Check if this is a dynamic form slug
            $form = $dynamicForms->firstWhere('slug', $category);
            
            // If it's a dynamic form, use its name. Otherwise, capitalize the category.
            // We use the raw category for keys that are special like 'Pos_requests'
            if ($category === 'kb_articles') {
                $categoryDisplay = 'KB Articles';
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
