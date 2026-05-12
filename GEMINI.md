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
1. **Permissions:** Add the module permissions in `database/seeders/RolesAndPermissionSeeder.php` and update `app/Http/Services/RoleService.php` to include the new permissions in the appropriate group and order.
2. **Roles UI:** Update `resources/js/Pages/Roles/Index.vue` to include the new module in the `landingPageOptions` and ensure it appears in the permission management tabs.
3. **Settings UI:** Ensure the module is discoverable in the Sidebar Layout management under `resources/js/Pages/Settings/Index.vue`.
4. **Implementation:** Create the Migration, Model, Controller, and Vue Index page following the established patterns (e.g., grouping by location, audit fields).
5. **Consistency:** Always ask for clarification on specific business requirements (e.g., stock handling, SOH logic) before implementation.
