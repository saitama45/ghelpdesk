# Authentication & Authorization

## Authentication
| Path | Mechanism | Files |
|---|---|---|
| Web session login | Laravel Breeze (Inertia), `guest`/`auth` route groups | `routes/auth.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `resources/js/Pages/Auth/` |
| Google SSO | `laravel/socialite` | `app/Http/Controllers/Auth/SocialAuthController.php` |
| API / mobile | Sanctum personal access tokens | `POST /api/login` → `app/Http/Controllers/Api/AuthController.php`; `auth:sanctum` group in `routes/api.php` |
| App-to-app | Sanctum service account | `php artisan integration:issue-token linkportal` (`app/Console/Commands/IssueIntegrationToken.php`) |
| Public pages | Opaque per-record tokens, no login | `PublicTicketController`, `PublicQueueController`, `PublicUatController` (all `throttle:*`) |

**Google sign-up is gated**: a first-time Google user is created with `is_active = false` and admins get `GoogleRegistrationPending` mail; access starts only after activation (`GoogleRegistrationApproved`). An email already linked to a different `google_id` is rejected. Config lives under `services.google.*` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`).

Session config: `SESSION_DRIVER=database`, 120-minute lifetime. `bootstrap/app.php` rewrites Inertia 302s on PUT/PATCH/DELETE to 303 so an expired session redirects as a GET instead of throwing `MethodNotAllowedHttpException`.

## Authorization — three stacked layers

### 1. Permissions (spatie/laravel-permission)
- Naming convention `{module}.{action}`; actions ordered by `RoleService::ACTION_ORDER` (`view, operate, show, create, edit, execute, assign, resolve, close, post, delete, archive, restore, signoff, approve, export, import, canned_messages, internal_notes`).
- Catalogue and grouping: `app/Http/Services/RoleService.php` (also synthesizes per-form permissions `{form-slug}.{action}` for every `FormDefinition`).
- Seeded in `database/seeders/RolesAndPermissionSeeder.php`; managed in the UI at `/roles` (`resources/js/Pages/Roles/`).
- `Gate::before` in `AppServiceProvider` grants the **`Admin` role** everything. `Solutions Admin` is deliberately excluded — its access is purely permission-driven. `usePermission()` mirrors this on the frontend.
- Per-user permission lists are cached for 1h in `HandleInertiaRequests`, keyed by `user.updated_at` + a global `permissions_version` counter that must be bumped whenever a role's permissions change.

**Route gating is mandatory.** Hiding a sidebar link is not access control: every permission-gated module also puts its route group behind `->middleware('permission:{module}.view')` (alias registered in `bootstrap/app.php`). Every QA run must probe the bare URL without the permission and expect **403**.
User Management itself was open until 2026-08-20 — `/users` and `/roles` (index **and** every write verb) carried only `auth`, so any logged-in user could reach the permission catalogue. Both are now `permission:users.view` / `permission:roles.view` groups with per-verb `create/edit/delete` gates via `middlewareFor()`.

### 2. Entity (company) scope — `app/Support/CompanyContext.php`
- Accessible entities = union of the companies attached to the user's **roles** (`role_company`) plus `users.company_id`, restricted to active companies.
- The active entity lives in the session (`active_company_id`), defaults to code `TGI`, and is shared to the frontend as `activeCompany` / `availableCompanies`.
- `ActiveEntityScope` filters listings of `SCOPED_MODELS`; new records in `MODULE_TABLES` are stamped with the active entity.
- **It is not an authorization boundary** — see `docs/knowledge/Database.md` gotcha 6.
- The `tickets.filter_entity` / `dashboard.filter_entity` permissions unlock a multi-entity override filter (including an "All Entities" sentinel).

### 3. Department axis — `app/Support/DepartmentContext.php` + `app/Support/TicketAccess.php`
- HOME department (`users.department_id`, session-overridable) vs VIEWED department (`viewed_department_id`).
- Access is derived, never assigned: **provider** of your own department's work, **customer** of every other department's.
- `TicketAccess::isCustomerOf()` renders a ticket read-only for internal customers; only `tickets.resolve` lets the requester close the loop. Bypass roles: `Dev`, `Admin`, `Solutions Admin` (same list as `DepartmentContext::HOME_SWITCH_ROLES`) plus Executive mode.
- Department-scoped lists must hide other departments' rows via `DepartmentContext::homeDepartmentId()` **and** block direct URL access with route middleware. NULL department = shared; Executive sees all.
- UAT/QAT cycles are the one department-scoped list with a role-wide override: `App\Support\TestCycleAccess::seesAllDepartments()` (Executive **or** the `Dev` role) is consumed by `UatController::index`, `EnsureUatCycleInDepartment` and `QatCycle::scopeVisibleTo()`/`isVisibleTo()` — all four must agree, or a row lists and then 403s.
- Ownership overrides exist per module, e.g. `/projects/{id}` structure edits require creator (`created_by`) or the Admin/Solutions Admin **roles** — not `projects.delete`, which is broadly granted.
- **Project plan (Gantt) access is per branch**, one rule in `app/Support/ProjectPlanAccess.php`:
  project manager (creator / Admin / Solutions Admin) → everything; **milestone owner**
  (`project_milestones.assigned_to`, keyed on `project_id` + `category`) → add/edit/delete every
  activity and sub-task in THAT milestone, rename or delete it, and start a milestone they then own;
  **activity assignee** → edit/delete that activity and add/edit/delete its sub-tasks, never a
  sibling; **sub-task assignee** → edit/delete that sub-task. Nothing is ever added under a sub-task.
  Mirrored (not re-derived) in `ProjectGantt.vue` and `ProjectWeeklyTimeline.vue` from the
  `milestones` / `canAddMilestone` Inertia props.

### 4. Reporting line — `app/Support/AttendanceVisibility.php`
- `/attendance/logs` (and its mobile twin `Api\AttendanceController::logs`) is scoped by the **org chart**, not by role or department: `users.is_manager` opens the manager's own `manager_user` subtree (`User::transitiveSubordinateIds()`), everyone else sees only themselves.
- The Admin / Dev / Solutions Admin roles used to reveal every employee here and no longer do. Attendance carries a selfie, a GPS fix and a work pattern, so the reporting line drawn on `/departments` is the boundary.
- `attendance.logs_department` is the one override: it opens the holder's whole `users.department_id`, and is granted **per account** (migration `2026_08_28_100001…`), never to a role. It is read with `hasPermissionTo()` rather than `can()` on purpose — `Gate::before` would otherwise hand it back to the Admin role.
- Four enforcement points must agree: the web listing, its `buildWorkHoursSummary`, and the same pair on the API. All four call `AttendanceVisibility::visibleUserIds()`.

## Adding a module — required touch points
1. Permission entries in `RolesAndPermissionSeeder`.
2. Category placement in `RoleService::getPermissionsByCategory()`.
3. Route group behind `permission:{slug}.view`.
4. Entry in `resources/js/Composables/useModuleRegistry.js` (drives sidebar, hub pages and layout settings).
5. Role-edit UI + `useSidebarOrder.js` defaults follow automatically from (2) and (4).
