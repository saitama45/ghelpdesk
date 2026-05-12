---
name: module-creator
description: Standardized workflow for creating new modules in the gHelpDesk project. Use when the user requests a new feature module (e.g., Stock Transfer, Receiving) to ensure all permission and UI registrations are handled.
---

# Module Creator Skill

This skill guides the creation of new application modules, ensuring consistency in permissions, role management, and UI integration.

## Module Creation Workflow

### 1. Database & Models
- Create a migration with SQL Server compatibility (handle cascade paths carefully).
- Define the Model with proper `$casts` for numeric IDs and audit timestamps.

### 2. Permissions & Roles
- **Seeder:** Add permissions to `database/seeders/RolesAndPermissionSeeder.php`.
- **Service:** Register the new permission category in `app/Http/Services/RoleService.php` within `$preferredOrder`.

### 3. UI Registration
- **Roles Page:** Add the module's index route to `landingPageOptions` in `resources/js/Pages/Roles/Index.vue`.
- **Permission Tabs:** Assign the module's permissions to the correct tab in `permissionGroups` within `resources/js/Pages/Roles/Index.vue`.
- **Sidebar Layout:** Verify visibility in `resources/js/Pages/Settings/Index.vue` Sidebar Layout management.

### 4. Controller & Routes
- Implement the controller with `index`, `store`, `update`, `destroy` methods.
- Use grouping logic for modules involving physical assets or inventory.
- Register resource routes in `routes/web.php`.

## Architectural Patterns
- **Grouping:** Group rows by location/status in the Index Vue page for high-volume data.
- **Auditing:** Always log `created_by` and `updated_by`.
- **Validation:** Perform rigorous validation, especially for stock movements.
