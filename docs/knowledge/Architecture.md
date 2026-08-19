# Architecture

## Stack
- **Backend**: Laravel 12, PHP 8.2+, Inertia.js v2 server adapter (`inertiajs/inertia-laravel`).
- **Frontend**: Vue 3 SPA-over-Inertia, Tailwind CSS v4 (Vite plugin), Heroicons, vuedraggable, vue-quill, Ziggy route helper.
- **Build**: Vite 7 (`vite.config.js`, entry `resources/js/app.js`).
- **DB**: SQL Server (`sqlsrv`) in dev/prod; SQLite `:memory:` in tests.
- **Auth/ACL**: Laravel Breeze scaffolding + Sanctum (API/mobile) + `spatie/laravel-permission`.
- **Mobile**: Capacitor 7 wrapper (`android/`, `ios/`, `capacitor.config.ts`) around the same web app; DTR/attendance uses the `/api` routes.
- **Hosting**: Azure App Service (Linux, nginx + PHP-FPM). `startup.sh` is the container entry script.

## Request lifecycle
1. `public/index.php` → `bootstrap/app.php` (routing, middleware, exception handling).
2. Web middleware stack appends `HandleInertiaRequests` and `UpdateUserPresence`.
3. Controller returns `Inertia::render('Page/Name', props)`.
4. `resources/js/app.js` resolves `resources/js/Pages/**/*.vue`, mounts Vue, installs Ziggy, toast plugin, theme, navigation history.

## Entry points
| Layer | Path |
|---|---|
| HTTP front controller | `public/index.php` |
| App bootstrap / middleware / exceptions | `bootstrap/app.php` |
| Service provider (observers, scopes, DB-driven mail config) | `app/Providers/AppServiceProvider.php` |
| Web routes (~640 lines) | `routes/web.php` |
| Auth routes | `routes/auth.php` |
| API routes (Sanctum, mobile DTR, integrations) | `routes/api.php` |
| Scheduler | `routes/console.php` |
| Artisan CLI | `artisan` |
| Frontend entry | `resources/js/app.js` |
| Root Blade view | `resources/views/app.blade.php` |
| Azure container startup | `startup.sh` |

## Directory responsibilities
| Path | Responsibility |
|---|---|
| `app/Http/Controllers/` | ~78 controllers, one per module (thin-ish; heavy logic lives in services) |
| `app/Http/Controllers/Api/` | `AuthController`, `AttendanceController` (mobile DTR), `AccountingDocumentReviewController` (linkportal inbound) |
| `app/Http/Controllers/Auth/` | Breeze auth + `SocialAuthController` (Google OAuth) |
| `app/Http/Middleware/` | `HandleInertiaRequests` (shared props), `UpdateUserPresence`, `EnsureUatCycleInDepartment` |
| `app/Http/Services/` | `RoleService` (permission catalogue/grouping), `WigsService` |
| `app/Services/` | 29 domain services — the real business logic |
| `app/Services/DynamicForms/` | `FormServiceFactory` → per-slug service, default `DefaultFormService` |
| `app/Support/` | `CompanyContext` (entity axis), `DepartmentContext` (department axis), `TicketAccess`, `CfeTicketStore`, `PhilippineHolidays` |
| `app/Models/` | 126 Eloquent models |
| `app/Models/Scopes/ActiveEntityScope.php` | Global entity filter on transactional models |
| `app/Observers/` | `TicketObserver` (ticket key, company backfill, SLA), `ProjectTaskObserver` |
| `app/Mail/` | 18 mailables + `DepartmentAddressDirectory` |
| `app/Console/Commands/` | 11 commands (email fetch, auto-close, SLA recalc, reminders, presence) |
| `app/Jobs/` | `SendDecisionCallbackJob` (linkportal callback) |
| `app/Notifications/` | `ActivityNotification` (DB notification bell) |
| `database/migrations/` | 294 migrations, auto-run on deploy |
| `resources/js/Pages/` | 52 module page folders (Inertia pages) |
| `resources/js/Layouts/` | `AppLayout`, `AuthenticatedLayout`, `GuestLayout`, `PublicLayout` |
| `resources/js/Composables/` | `useModuleRegistry` (sidebar/hub single source), `usePermission`, `useSidebarOrder`, `useDepartmentContext`, `useImageCompressor`, `useToast`, `useTheme`, … |
| `tests/Feature`, `tests/Unit` | 61 test files, SQLite `:memory:` |
| `docs/knowledge/` | This knowledge base |

## Two-axis scoping model (the core architectural idea)
Every record lives inside **an entity (company)** and, for work items, is served by **a department**.

- **Entity axis** — `app/Support/CompanyContext.php`. Session key `active_company_id`, default entity code `TGI`. `MODULE_TABLES` lists tables auto-stamped with `company_id` at creation (via a global `eloquent.creating: *` listener in `AppServiceProvider`). `SCOPED_MODELS` lists models that also get `ActiveEntityScope` applied as a global **listing** filter.
- **Department axis** — `app/Support/DepartmentContext.php`. Session keys `viewed_department_id` and `home_department_override`. HOME department = "I belong to"; VIEWED = whose workspace you are looking at. Access is *derived*: provider of your own department, customer of everyone else's. `HOME_SWITCH_ROLES = ['Dev','Admin','Solutions Admin']` may switch home; also `EXECUTIVE` sentinel.
- **Per-ticket side** — `app/Support/TicketAccess.php`: a ticket served by another department renders **read-only** (customer view); only `tickets.resolve` may close the loop.

## Domain services (`app/Services/`)
Tickets & email: `EmailTicketService`, `DepartmentMailRouter`, `SlaService`, `AutoAssigneeService`, `TicketKnowledgeBaseService`, `QueueService`, `NotificationService`, `LeadershipPointService`.
Projects: `ProjectScheduler`, `ScheduleChain`, `ScheduleCalculator`, `HolidayCalendar`, `ProjectOverviewService`, `ProjectProgressChartService`, `ProjectWorkspaceService`, `ProjectTaskBoardSyncService`.
Reporting: `StoreReportService`, `BrandHealthService`, `PartnerPerformanceService`, `AssetOperationalHealthService`.
Requests/ops: `PosRequestService`, `SapRequestService`, `UatService`, `UatWorkbook`, `RecurringSchedulePlannerService`, `ServiceVehicleTripService`, `CctvEquipmentMatcher`, `OrganizationReferenceService`.

## Frontend conventions
- Sidebar, hub pages and layout settings all read `resources/js/Composables/useModuleRegistry.js` — adding a module means adding one entry there (user ordering overrides live in `useSidebarOrder.js`).
- Permission gating in the UI: `usePermission()` (`Admin` role short-circuits to true) — mirrors `Gate::before` on the backend.
- Page shells use `AppLayout` with `content-class="w-full max-w-none px-2 sm:px-4 lg:px-6"`.
