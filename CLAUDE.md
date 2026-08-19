# CLAUDE.md — ghelpdesk Project Map

> **Read this file and the Claude auto memory (`MEMORY.md` + `memory/*.md`) at the start of every session, before touching any code.**
> This is an index, not a manual. Detailed knowledge lives in `docs/knowledge/` — open only the note the task needs.

## How to work in this repository (permanent instructions)

1. **Start every new session by reading `CLAUDE.md` and the Claude auto memory first.**
2. **Do not start a task by broadly or recursively scanning the repository.** No repo-wide `find`/`grep` sweeps as an opening move.
3. **Use this map to identify the relevant subsystem, then read only the files that task needs.**
4. **Explore more broadly only when** the documentation is missing, stale, or contradicted by the source.
5. **Source code is authoritative** whenever it conflicts with documentation.
6. **After a verified architectural or workflow change, update `CLAUDE.md` and the relevant `docs/knowledge/` note** in the same session.
7. **Save reusable discoveries** — conventions, debugging insights, architectural knowledge — to auto memory.
8. **Never save temporary progress, speculative conclusions, or task status** as memory.
9. **Keep `CLAUDE.md` concise** (< 200 lines) to minimize startup tokens; put depth in `docs/knowledge/` topic files.
10. **Before ending each significant task, check whether any documentation or memory has gone stale and update it.**

## Knowledge base index

| File | Read it when |
|---|---|
| `docs/knowledge/Architecture.md` | stack, entry points, directory responsibilities, service inventory, two-axis scoping model |
| `docs/knowledge/Data-Flows.md` | ticket lifecycle, email intake, notifications, projects↔boards, approvals, dashboard, public pages, API |
| `docs/knowledge/Database.md` | connections, safety rules, migrations, table map, SQL Server gotchas |
| `docs/knowledge/Authentication.md` | login paths, Sanctum, Spatie permissions, entity + department authorization, new-module checklist |
| `docs/knowledge/Integrations.md` | IMAP/SMTP, Google OAuth, linkportal, Capacitor, scheduler, Azure deploy |
| `docs/knowledge/Decisions.md` | architectural decisions, pitfalls, UI conventions, working agreements |
| `GEMINI.md` | SQL Server migration/model patterns (mandatory) |

## What this system is

**ghelpdesk** — an internal service-management platform for a multi-entity retail/restaurant group. It began as a helpdesk (email + web ticketing with SLA) and now also covers project delivery, task boards, inventory and assets, purchase/approval workflows, compliance monitoring, scheduling/attendance, UAT, and executive reporting.

**Main modules** (sidebar sections, defined in `resources/js/Composables/useModuleRegistry.js`): Dashboard · Project Tracker · **Services** (Tickets, Queue Monitor, Task Board, POS Requests, SAP Requests, Loyalty Stamps) · **Inventory** (Assets, Stock In/Transfer/Receiving, Inventory Report) · **Monitoring** (NPC Status, CCTV, ALAGA, WIGS, Payments & SOA, Accounting Documents, Mall Hookup) · **Administrative** (DTR, Attendance Logs, Scheduling, Service Vehicle Trips, Presence, KB Articles, Holidays, UAT Tracker) · **References** (Companies, Departments, Clusters, Stores, Vendors, Activity Templates, Categories, Sub-Categories, Items, Request Types, Form Builder) · **Reports** · **User Management** · **Settings**.

## Stack in one line

Laravel 12 + Inertia v2 + Vue 3 + Tailwind v4 (Vite) on SQL Server, with Spatie permissions, Sanctum for the Capacitor mobile app, and IMAP/SMTP email ticketing. Deployed to Azure App Service.

## Entry points

| Purpose | Path |
|---|---|
| HTTP front controller | `public/index.php` |
| Bootstrap, middleware, exceptions | `bootstrap/app.php` |
| Observers, global scopes, DB-driven mail config | `app/Providers/AppServiceProvider.php` |
| Web routes | `routes/web.php` · Auth `routes/auth.php` · API `routes/api.php` · Scheduler `routes/console.php` |
| Frontend entry | `resources/js/app.js` → `resources/js/Pages/**/*.vue` |
| Azure container startup (runs migrations) | `startup.sh` |

## Core files worth knowing by heart

| Concern | File |
|---|---|
| Entity (company) axis | `app/Support/CompanyContext.php` |
| Department axis | `app/Support/DepartmentContext.php` |
| Per-ticket provider/customer access | `app/Support/TicketAccess.php` |
| Global entity listing filter | `app/Models/Scopes/ActiveEntityScope.php` |
| Inertia shared props (auth, permissions, entity, department, flash) | `app/Http/Middleware/HandleInertiaRequests.php` |
| Ticket model + scopes | `app/Models/Ticket.php` |
| Ticket key / company backfill / SLA creation | `app/Observers/TicketObserver.php` |
| Ticket controller (largest, ~3k lines) | `app/Http/Controllers/TicketController.php` |
| Inbound email → ticket/comment | `app/Services/EmailTicketService.php` |
| Department mail addresses | `app/Services/DepartmentMailRouter.php` |
| SLA business-hours math | `app/Services/SlaService.php` |
| Bell notifications | `app/Services/NotificationService.php` + `app/Notifications/ActivityNotification.php` |
| Permission catalogue / grouping | `app/Http/Services/RoleService.php` |
| Gantt scheduling chain | `app/Services/ProjectScheduler.php`, `ScheduleChain.php`, `HolidayCalendar.php` |
| Project ↔ board sync | `app/Services/ProjectTaskBoardSyncService.php` |
| Module tree (sidebar + hub + layout settings) | `resources/js/Composables/useModuleRegistry.js` |
| Frontend permission checks | `resources/js/Composables/usePermission.js` |

## Architecture in five sentences

1. Inertia pages are rendered by ~78 controllers; real business logic lives in ~29 services under `app/Services/`.
2. Everything is scoped on **two axes**: an *entity* (company) stamped onto new records and filtered on transactional models, and a *department* (home vs viewed) that derives provider/customer access.
3. Tickets are the hub: created from web, email, dynamic forms, POS approvals or the queue kiosk, then normalized by `TicketObserver` and measured by `ticket_sla_metrics`.
4. Permissions are `{module}.{action}` strings gated in the UI *and* on the route group; the `Admin` role bypasses via `Gate::before`.
5. Reporting (dashboard, store/brand health, partner performance, asset health) is derived live from tickets and project tasks — nothing is precomputed.

## Commands

```bash
# Dev (server + queue + vite + scheduler together) — use this, not `artisan serve` alone
composer run dev   # queue:listen + schedule:work are required for email intake
composer run dev:logs   # `artisan pail` — POSIX only, needs ext-pcntl (NOT available on Windows)
php artisan serve --port=8010     # port 8000 is a DIFFERENT app on this machine
npm run dev                       # Vite dev server
npm run build                     # production assets — run before finishing a change

# Tests (SQLite :memory: — verify the connection first, see safety below)
php artisan test
php artisan test --filter=SomeTest
vendor/bin/phpunit

# Lint / format
vendor/bin/pint            # check + fix
vendor/bin/pint --test     # check only
php -l path/to/File.php    # syntax check

# Routine checks
php artisan route:list
php artisan config:clear && php artisan cache:clear
php artisan tickets:diagnose-email
```
Mobile: `npm run mobile:sync`, `npm run mobile:open:android|ios`.

## Local dev performance

`php artisan serve` is PHP's built-in server: on Windows it handles **one request at a time**
(`PHP_CLI_SERVER_WORKERS` is POSIX-only and does nothing here). Every slow request therefore
blocks everything queued behind it, so a single blocking endpoint stalls a whole page load.

- **Slow work a page triggers must go to the QUEUE, not just be deferred.** The dashboard's
  `tickets/sync` POST ran IMAP inline (~30s) and made the first load after login 47s. Deferring it
  was NOT enough — it only moved the stall onto the next click (`/tickets` still took 10.04s
  mid-fetch). It now hits `tickets/sync-background` → `App\Jobs\FetchEmailsJob` → **202 in 0.1s**;
  same navigation 1.02s. Verify such fixes with the trigger LIVE: stubbing it in Playwright hid
  this stall completely.
- **Guard any endpoint a page can trigger with `Cache::lock`, and make the job `ShouldBeUnique`.**
  Production runs `pm.max_children = 20`; twenty simultaneous dashboard opens each holding a 30s
  IMAP fetch could starve every worker. `POST tickets/sync` returns 409 in 0.07s instead.
- **`pail` cannot run on Windows** (needs `ext-pcntl`, POSIX-only). It used to be in the `dev`
  script with `--kill-others`, so it threw on startup and tore down server, queue, vite and
  schedule with it. Moved to `composer run dev:logs`; tail `storage/logs/laravel.log` instead.
- **A queue worker must be running.** `composer run dev` includes `queue:listen`; `startup.sh`
  now starts `queue:work`. Before this, nothing consumed the `database` queue in production, so
  dispatched jobs (e.g. `SendDecisionCallbackJob`) sat in the table forever.
- **OPcache must be enabled**, including `opcache.enable_cli=1` — `artisan serve` runs under the
  CLI SAPI. Without it PHP recompiles all of Laravel per request: measured 0.575s → 0.044s
  (13x) on `/login`. Configured in `C:\php\php.ini` locally, and in `startup.sh` (conf.d) for
  Azure. A one-shot `php -r` benchmark will NOT show this — OPcache only pays off inside a
  long-lived process, so A/B two `artisan serve` instances instead.
- Production also rebuilds `route:cache`/`view:cache` in `startup.sh`; `config:cache` is still
  skipped deliberately (verified safe — no `env()` outside `config/` — but only ~15% on top of
  OPcache, not worth a deploy risk).
- Vite dev is not usually the bottleneck; verify before blaming the frontend. Measure which host
  the time belongs to (app vs Vite) before optimising — see `docs/knowledge/Decisions.md`.

## Database safety — mandatory

- **This project's local database is `tashelpdeskdb`** (`sqlsrv` @ 127.0.0.1, from `.env`). Treat it as protected developer data: never run destructive operations against it.
- **`daviddb` is a different app's database on this machine** (the one served on port 8000) and is protected machine-wide by `~/.claude/rules/database-safety.md`. Nothing in this repository should ever touch it.
- **Never** run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, destructive seeders, `DROP`, `TRUNCATE`, bulk row deletion, or restore-with-replace against either database — or any other developer database — from Claude Code.
- **Laravel tests must always use an isolated test database.** `phpunit.xml` and `.env.testing` force `sqlite` `:memory:`; never run tests using `RefreshDatabase` against a live connection.
- **Verify the active connection before running any test, migration, seeder or database-modifying command.** `APP_ENV=testing` alone is not proof:
  ```bash
  php artisan tinker --execute='$c=config("database.default"); echo $c." -> ".config("database.connections.$c.database");'
  ```
  Proceed only if it resolves to `:memory:` or a name ending in `_test`/`_testing`. Otherwise stop and report the exact unsafe connection.
- Restores, drops, truncations and bulk deletions are run **by the user, manually, outside Claude Code**.
- A global `PreToolUse` hook (`~/.claude/hooks/database_safety_guard.py`) enforces this. Never bypass it or use `--dangerously-skip-permissions`.
- The local `.env` database (`tashelpdeskdb` @ 127.0.0.1) is a **snapshot**; the user's app runs against the Azure cloud database, so record counts will differ. Cloud schema changes ship as migrations (auto-run by `startup.sh`), never as hand-written SQL.

## Non-negotiable habits

- **Never run `git commit` or `git push`, and never ask to.** Provide one short commit subject line only.
- **Run the regression checklist after every change** (`/regression-test`): PHP syntax → `route:list` → RoleService key sanity → SQL Server compatibility → eager-load safety → `npm run build` → **browser QA**.
- **Browser QA is mandatory and automatic**, never offered as optional, always with **two contrasting user profiles** (elevated + restricted) and a 403 probe on any permission-gated URL. PHP tests pass while pages are broken.
- Adding a module means touching all five places listed in `docs/knowledge/Authentication.md` § "Adding a module".
