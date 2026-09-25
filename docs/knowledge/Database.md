# Database

## Connections
| Context | Connection | Database |
|---|---|---|
| Local dev (`.env`) | `sqlsrv` @ `127.0.0.1` | `tashelpdeskdb` — a **local snapshot**, not live data |
| Production | `sqlsrv` on Azure (`tas-sea-server`) | live; counts will differ from local tinker |
| Tests | `sqlite` | `:memory:` (forced by `phpunit.xml` and `.env.testing`) |
| Another app on this machine | `sqlsrv` @ `127.0.0.1` | **`daviddb`** — belongs to the app on port 8000, not to ghelpdesk; protected machine-wide, never touched by any automated command |

Config: `config/database.php` (`sqlsrv` block sets `PDO::ATTR_STRINGIFY_FETCHES => false`, `encrypt`/`trust_server_certificate` from env).
Session, cache and queue all use the `database` driver in dev/prod (`sessions`, `cache`, `jobs` tables).

## Safety rules (non-negotiable)
- This project's local database is **`tashelpdeskdb`**; the machine also hosts **`daviddb`**, which belongs to a different app and is protected machine-wide. Never run destructive SQL, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, destructive seeders, `DROP`, `TRUNCATE`, or restore-with-replace against either.
- Before tests or potentially destructive commands, **verify the effective connection** (`php artisan db:show` / `php artisan tinker --execute="echo config('database.default').' '.config('database.connections.'.config('database.default').'.database');"`). `APP_ENV=testing` alone is not proof. Routine reads, inserts, updates, upserts, safe backfills/seeders and additive forward migrations on `tashelpdeskdb` are allowed; Claude should run them directly.
- Tests must resolve to SQLite `:memory:` or a database whose name ends in `_test`/`_testing`. If isolation cannot be proven, stop and report the exact unsafe connection.
- Never execute physical-delete actions, restores, drops, truncations, resets or destructive schema changes against protected development data; those require the isolated test database. Soft-delete must never be executed anywhere, including isolated tests—verify it without invoking the action.
- A global `PreToolUse` hook (`~/.claude/hooks/database_safety_guard.py`) blocks these commands; never bypass it or use `--dangerously-skip-permissions`.
- Direct connections to the cloud DB get blocked — hand the user reviewed SSMS SQL instead.

## Migrations
- 294 files in `database/migrations/`. Migrations **auto-run on Azure deploy** via `startup.sh` (`php artisan migrate --force`) — never instruct the user to run `migrate` manually and never hand out ad-hoc `ALTER TABLE` SQL for schema changes; write a migration.
- Historical migrations that query Eloquent models must suppress any current global scope whose required columns are added by later migrations. For example, use `User::withoutGlobalScope(SoftDeletingScope::class)` before `users.deleted_at` exists, or a fresh database migration will fail even though incremental production migrations succeed.
- Seeders: `DatabaseSeeder`, `RolesAndPermissionSeeder` (permissions catalogue), `PhilippineHolidaySeeder`, `StoreReferenceOptionSeeder`. Seeders must be idempotent (`firstOrCreate`, `Schema::hasColumn` guards).

## Table map (≈140 tables)
**Identity & ACL** — `users`, `manager_user` (reporting chain), `roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `role_company`, `store_user`, `personal_access_tokens`, `sessions`, `password_reset_tokens`, `user_presence_logs`.

**Org reference** — `companies`, `departments`, `department_nodes`, `department_units`, `department_sub_units`, `department_sections`, `department_services`, `clusters`, `cluster_store`, `stores`, `store_options`, `store_blueprints`, `vendors`, `categories`, `sub_categories`, `items`, `request_types`, `reference_options`, `holidays`, `settings`.
  `departments` name is unique **per entity** (`departments_company_id_name_unique`); code is unique **across all entities** (filtered `departments_code_unique` WHERE code IS NOT NULL). Mirrored in `DepartmentController` validation.

**Tickets** — `tickets` (UUID PK, soft deletes), `ticket_comments`, `ticket_attachments`, `ticket_ccs`, `ticket_histories`, `ticket_assets`, `ticket_sla_metrics`, `ticket_surveys`, `ticket_views`, `ticket_key_aliases`, `email_intake_logs`, `canned_messages`, `kb_articles`, `kb_categories`, `kb_article_views`.

**Projects & boards** — `projects`, `project_tasks`, `project_assets`, `project_team_members`, `project_templates`, `project_progress_logs`, `activity_templates`, `task_boards`, `task_board_columns`, `task_board_members`, `task_board_watchers`, `task_cards`, `task_card_*`, `task_checklists`, `task_checklist_items`, `task_labels`.

**Requests & forms** — `pos_requests(+_details,+_approvals)`, `sap_requests(+_items,+_approvals)`, `form_definitions`, `form_definition_request_type`, `table_definitions`, `table_records`, `table_record_approvals`, `acct_document_reviews(+_events)`.

**Inventory & assets** — `assets`, `stock_ins`, `stock_transfers`, `stock_receivings`, `stock_packs`, `inventory_transactions`.
Every stock row is **one piece** (`quantity = 1`, own barcode/QR), and the ledger counts pieces. An asset's
`base_uom` (default PC) is the piece unit. The optional `bulk_uom` + `units_per_bulk` (BOX of 12) describe a box.
Each box received at Stock In is a `stock_packs` row with its own label, and its pieces carry `stock_pack_id` on all three
stock tables. On a transfer the id is copied from the source unit, never taken from the client, so a split box stays
traceable and a receiving box scan verifies only that box's pieces present. UOM lists are `reference_options` types
`uom_base` / `uom_bulk`. `stock_packs` is entity-stamped but deliberately not in `SCOPED_MODELS`.

**Monitoring** — `npc_*` (statuses, documents, payments, registrations, seal receipts, proofs, workflow steps), `cctv_systems`, `cctv_inspections(+_units)`, `alaga_assessments`, `mall_hookups(+_costs,+_logs)`, `payment_*` (records, tenders, approvals, invoices, vendors, renewals, overpayments, weekly plans, settings, reminder log), `wigs_*`.

**Administrative** — `schedules`, `schedule_stores`, `schedule_change_requests`, `attendance_logs`, `service_vehicles`, `service_vehicle_trips(+_attachments)`, `uat_cycles`, `uat_sections`, `uat_cases`, `uat_case_results`, `uat_participants`, `uat_findings`, `uat_evidence`, `uat_signoffs`, `stamp_*`, `quests`, `agent_point_transactions`, `agent_quest_progress`, `customers`, `notifications`.

**Framework** — `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`.

## Cross-cutting columns
- `company_id` on every table in `CompanyContext::MODULE_TABLES` — auto-stamped at creation, filtered on the subset in `SCOPED_MODELS`. `users.company_id` is explicitly **excluded** from stamping.
- Work items carry both `department_id` (requester) and `serving_department_id` (owning desk).
- `users.org_path` replaced the removed `users.sub_unit`.
- `deleted_at` + `deleted_by` on `users` and `customers` (2026-09-10). Both models use `SoftDeletes`: the delete icon on `/users` and on `/stamps` → Customers **archives**, and `App\Services\AccountArchiveService` archives/restores/purges the `users.customer_id` pair together, whichever side was clicked. Restore and permanent purge live on `/settings/account-archive` (tabs: Deletion Requests from the public /account-deletion page, staff-only Users, Loyalty Customers incl. the member app login) behind the `account_retention_*` settings. Two consequences worth remembering: the SoftDeletes scope hides an archived user from every relation (an archived assignee reads as `null` — use `withTrashed()` where history must render), and `unique:users` still sees archived rows, so an archived value blocks reuse until it is restored or purged.

- **`users.email` is the exception** (2026-09-25, `users.archived_email`). Archiving now parks the real address in `archived_email` and writes `deleted-{id}@archived.invalid` into `email`, so the address is free to register again immediately — a deleted member being unable to sign up with their own email again was an App Store review risk. Restoring puts it back unless someone has since taken it, in which case the tombstone stays and `AccountArchiveService::restoreUser()` reports `email_reclaimed => false`. Read an archived login's address as `archived_email ?? email`.
- `employee_id_no` is **not** freed this way and still blocks reuse while archived.

## SQL Server gotchas (mandatory patterns — see also `GEMINI.md`)
1. **No multiple cascade paths.** Secondary FKs must use `->onDelete('no action')`, or the migration fails on SQL Server.
2. **FKs come back as strings.** `$child->parent_id === $parent->id` is false. Cast every numeric id in the model's `$casts` (`'user_id' => 'integer'`).
3. **A unique index rejects a second NULL.** Use a filtered index (`WHERE col IS NOT NULL`) when NULLs must repeat.
4. **`tickets.id` is a UUID**, not an integer — factories and joins must not assume ints.
5. **Never `SELECT *` on ticket-like tables.** `nvarchar(MAX)` columns (`description`, `form_data`, `remarks`) drag megabytes over the Azure link; pin explicit columns in every list/search query.
6. **`ActiveEntityScope` is a listing filter, not an auth boundary.** Any request-driven re-query by id (`find`, `findOrFail`, `whereIn`, `whereKey`, parent sync) must add `->withoutGlobalScope(ActiveEntityScope::class)` or it 404s/silently drops cross-entity rows.
7. No recursive CTEs in shared code — SQL Server and SQLite spell them differently (see `User::transitiveSubordinateIds()`, which walks in PHP).
