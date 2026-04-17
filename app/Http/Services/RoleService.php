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
        $permissions = Permission::all();
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
            'request_types',
            'table_builder',
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
        ];
        
        foreach ($permissions as $permission) {
            $category = explode('.', $permission->name)[0];
            $grouped[ucfirst($category)][] = $permission;
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
