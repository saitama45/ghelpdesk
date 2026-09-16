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
| `app/Support/` | `CompanyContext` (entity axis), `DepartmentContext` (department axis), `TicketAccess`, `TestCycleAccess` (who sees every department's UAT/QAT cycles), `CfeTicketStore`, `PhilippineHolidays` |
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

- **Entity axis** — `app/Support/CompanyContext.php`. Session key `active_company_id`, default entity code `TGI`. `MODULE_TABLES` lists tables auto-stamped with `company_id` at creation (via a global `eloquent.creating: *` listener in `AppServiceProvider`). `SCOPED_MODELS` lists models that also get `ActiveEntityScope` applied as a global **listing** filter. Reference models stay out of `SCOPED_MODELS` so form dropdowns work across entities; reference *management pages* still follow the entity switcher in their controllers via `app/Support/EntityReferenceScope.php` — `/items`, `/stores`, `/clusters`, `/vendors`, `/categories`, `/sub-categories`. `visible()` lists the active company's rows + rows of every Entity it is tagged to on /companies (`entity_brand` → `Company::itemSourceIds`) + NULL-company rows (shared); `ensureOwned()` 404s edit/delete/approval/blueprint/review actions on inherited rows. Frontend: `useEntityOwnership()` (`isInherited`, from shared `activeCompany`) + `Components/InheritedEntityBadge.vue` ("From: TGI" badge / lock replacing row actions). An Entity does NOT inherit its brands' rows — e.g. TGI's /stores shows only TGI-owned stores (stores.company_id is the brand). Uniqueness stays global except items (per entity). References hub Stores/Vendors/Items KPIs match the lists. Ticket item pickers (Tickets create/Accept/Bulk, ticket Edit, Gantt sub-task ticket) offer only items the **selected store's company** may use — its own items plus those of every Entity it is tagged to on /companies (`entity_brand`, `Company::itemSourceIds`; `tickets.data.items` sends `usable_company_ids` per item) — via `resources/js/lib/entityItems.js` (fallback: the form's company; Edit keeps the saved item listed), enforced server-side by `TicketController::assertItemMatchesStoreEntity` on store/update(item change only)/accept/bulkUpdate. Items with NULL `company_id` fit every entity. **Partner Escalation** pickers (create, Edit, escalate-to-partner child) use the same rule on vendors (`usable_company_ids`, `assertVendorMatchesStoreEntity` on store/update(change only)/escalation). **Store** pickers list stores of the viewed companies (Index: the Entity-filter/active set; Edit: the ACTIVE ENTITY only — the ticket's own company is deliberately excluded, or a TGI ticket would list every TGI brand's stores whatever entity you switched to; the saved store is kept listed) plus their tagged entities AND the **brands tagged to them** (`storesForCompanies` → `EntityReferenceScope::storeCompanyIdsFor`). Stores roll **UP** the `entity_brand` tag, the opposite direction to items/vendors/categories: a brand inherits its entities' catalogue, but an entity operates its brands' locations — under TGI the picker offers the CBTL, NONO'S and DEMPSEY stores (8 → 258). Only the viewed companies are expanded to their brands, never the entities they inherit from, so CBTL never sees sibling NONO'S stores. Picker-only, NOT enforced on save, because Gantt rollout tickets target brand stores from an entity's project.
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
