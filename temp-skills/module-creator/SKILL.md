---
name: module-creator
description: Standardized workflow for creating new modules in the gHelpDesk project. Use when the user requests a new feature module (e.g., Stock Transfer, CCTV Monitoring) to ensure all permission, role-management, and UI registrations are handled.
---

# Module Creator Skill

This skill guides the creation of new application modules, ensuring consistency in permissions, role management, and UI integration.

## Module Creation Workflow

### 1. Database & Models
- Create a migration with SQL Server compatibility (handle cascade paths carefully; use `cascadeOnDelete` or `no action` as appropriate).
- Define the Model with proper `$casts` for numeric IDs and audit timestamps.

### 2. Permissions (Spatie)
- **Seeder:** Add permissions to `database/seeders/RolesAndPermissionSeeder.php`.
- **Migration:** Create a migration that inserts permissions via `Permission::firstOrCreate(...)` and grants to Admin + Solutions Admin roles. See `2026_06_15_000004_create_cctv_monitoring_permissions.php` as a template.
- **Cache bump:** End the migration/seeder with `Cache::forever('permissions_version', now()->timestamp)` and `app(PermissionRegistrar::class)->forgetCachedPermissions()`.

### 3. Role Management — Permission Display
The role form modal groups permissions into visual sections. **All three steps below are required** or the new permissions won't appear in the role editor:
1. **Backend display name:** In `app/Http/Services/RoleService.php` → `getPermissionsByCategory()`, add an explicit `elseif` mapping (e.g., `cctv_monitoring` → `'CCTV Monitoring'`). Without this, it falls through to `ucfirst(str_replace('_', ' ', $category))`.
2. **Backend preferred order:** Add the lowercase display name to the `$preferredOrder` array in the same file.
3. **Frontend permission group:** In `resources/js/Components/Roles/RoleFormModal.vue`, add the display name to the appropriate group's `categories` array in the `permissionGroups` computed. Matching is by normalized name (lowercase, non-alphanumeric stripped).

### 4. Role Management — Landing Page Dropdown
In `resources/js/Components/Roles/roleLandingPageOptions.js`, add `{ label: 'Module Name', value: 'route.name.index' }` to the appropriate group's `options`. The value must match a registered route name.

### 5. Sidebar
In `resources/js/Components/Sidebar.vue`:
1. Add a child link under the appropriate expandable section, gated by `hasPermission('module_name.view')`.
2. If the section is new or shared, update the `canSee*` computed (e.g., `canSeeMonitoring`).
3. Update `resources/js/Composables/useSidebarOrder.js` with the child order entry and display label.

### 6. Settings UI
Ensure the module is discoverable in the Sidebar Layout management under `resources/js/Pages/Settings/Index.vue`.

### 7. Global Search (optional)
Add the module to the `$menus` array in `app/Http/Controllers/GlobalSearchController.php`.

### 8. Controller & Routes
- Implement the controller with `index`, `store`, `update`, `destroy` methods.
- Use grouping logic for modules involving physical assets or inventory.
- Register resource routes in `routes/web.php`.

## Architectural Patterns
- **Grouping:** Group rows by location/status in the Index Vue page for high-volume data.
- **Auditing:** Always log `created_by` and `updated_by`.
- **Validation:** Perform rigorous validation, especially for stock movements.

## Common Pitfalls
- **Permission cache stale:** The Inertia middleware caches per-user permissions keyed on `permissions_version`. Always bump the cache when adding permissions or run `php artisan optimize:clear`.
- **Permission display name mismatch:** The frontend `RoleFormModal.vue` matches category names by normalization. If the backend display name doesn't match what's listed in `permissionGroups`, the permissions silently fall into "Other" or don't appear at all.
- **Landing page dropdown:** Forgetting to add the route to `roleLandingPageOptions.js` means the module can't be set as a role's default landing page.
