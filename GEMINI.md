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

## 4. Maintenance & Repair
If a remote environment is out of sync (e.g., `migrate` says "Nothing to migrate" but columns are missing), use a high-numbered "repair" migration to force-apply the missing schema changes using `Schema::hasColumn` checks.
