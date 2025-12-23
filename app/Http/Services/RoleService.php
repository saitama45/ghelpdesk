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
        
        foreach ($permissions as $permission) {
            $category = explode('.', $permission->name)[0];
            $grouped[ucfirst($category)][] = $permission;
        }
        
        return $grouped;
    }

    /**
     * Create a new role with permissions
     */
    public static function createRole($name, $permissions = [])
    {
        $role = Role::create(['name' => $name]);
        
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
     * Delete a role
     */
    public static function deleteRole($roleId)
    {
        $role = Role::findById($roleId);
        return $role->delete();
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