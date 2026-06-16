# Project Instructions (SQL Server Compatibility)

This project is deployed on SQL Server (sqlsrv). Adherence to the following patterns is mandatory for all migrations and model updates.

## 1. Foreign Key Constraints (Multiple Cascade Paths)
SQL Server prohibits multiple cascade paths (cycles). If a table has multiple foreign keys that eventually trace back to the same parent, use `ON DELETE NO ACTION` for the secondary paths.

**Pattern:**
```php
// If project_id is already cascaded via another path:
$table->foreignId('project_id')->nullable()->constrained()->onDelete('no action');
```

## 2. Model Casting (ID Type Safety)
SQL Server drivers often return primary and foreign keys as strings (e.g., `'1'`) instead of integers. This breaks strict comparisons and automated tests (e.g., `assertJsonPath`).

**Mandate:** Always add numeric IDs to the `$casts` array in models.
```php
protected $casts = [
    'user_id' => 'integer',
    'project_id' => 'integer',
    // ... other foreign keys
];
```

## 3. Robust Seeding
Seeders must be idempotent and environment-aware. Always use `firstOrCreate` and check for column existence if the column was added in a late-stage migration.

**Pattern:**
```php
if (Schema::hasColumn('users', 'created_by')) {
    $user->forceFill(['created_by' => 1])->save();
}
```

## 5. Module Creation Workflow
When creating a new module, ensure the following checklist is followed:

### 5.1 Permissions (Spatie)
1. **Seeder:** Add the module permissions in `database/seeders/RolesAndPermissionSeeder.php` under the permissions array (e.g., `'module_name.view' => 'View Module Name'`).
2. **Migration:** Create a migration that inserts the permissions via `Permission::firstOrCreate(...)` and grants them to relevant roles (Admin, Solutions Admin) — see `2026_06_15_000004_create_cctv_monitoring_permissions.php` as a template.
3. **Cache bump:** At the end of the migration (and seeder), call `Cache::forever('permissions_version', now()->timestamp)` and `app(PermissionRegistrar::class)->forgetCachedPermissions()` so the Inertia per-user permission cache invalidates.

### 5.2 Role Management — Permission Display
The role form modal groups permissions into visual sections. To make new permissions appear:
1. **Backend display name:** In `app/Http/Services/RoleService.php`, within `getPermissionsByCategory()`, add an explicit `elseif` mapping for the category prefix to a display name (e.g., `} elseif ($category === 'cctv_monitoring') { $categoryDisplay = 'CCTV Monitoring'; }`). Without this, the display name falls through to `ucfirst(str_replace('_', ' ', $category))` which may look wrong.
2. **Backend preferred order:** Add the lowercase display name to the `$preferredOrder` array in the same file (e.g., `'cctv monitoring'`) so the category sorts correctly.
3. **Frontend permission group:** In `resources/js/Components/Roles/RoleFormModal.vue`, add the display name to the appropriate group in the `permissionGroups` computed (e.g., add `'CCTV Monitoring'` to the `Monitoring` group's `categories` array). The matching is done by normalizing both the group category name and the backend key (lowercase, non-alphanumeric stripped), so the exact spelling matters.

### 5.3 Role Management — Landing Page Dropdown
In `resources/js/Components/Roles/roleLandingPageOptions.js`, add the module to the appropriate group's `options` array with `{ label: 'Module Name', value: 'route.name.index' }`. The value must match a registered route name.

### 5.4 Sidebar
In `resources/js/Components/Sidebar.vue`:
1. Add a child link under the appropriate expandable section, gated by `hasPermission('module_name.view')`.
2. Add the section to `canSeeMonitoring` (or the relevant computed) if it's a new section.
3. Update `resources/js/Composables/useSidebarOrder.js` with the child order and display label.

### 5.5 Settings UI
Ensure the module is discoverable in the Sidebar Layout management under `resources/js/Pages/Settings/Index.vue`.

### 5.6 Implementation
Create the Migration, Model, Controller, and Vue Index page following the established patterns (e.g., grouping by location, audit fields).

### 5.7 Dynamic Forms (if applicable)
If the module uses dynamic forms via the `/forms/{slug}` route:
- Create a dedicated service in `app/Services/DynamicForms/` implementing `FormServiceContract`.
- Register the service in `app/Services/DynamicForms/FormServiceFactory.php` using the form's slug.

### 5.8 Global Search (optional)
If the module should appear in global search, add it to the `$menus` array in `app/Http/Controllers/GlobalSearchController.php`.

### 5.9 Consistency
Always ask for clarification on specific business requirements (e.g., stock handling, SOH logic) before implementation.
