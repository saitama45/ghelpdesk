# Architectural Decisions & Known Pitfalls

## Decisions

**D1 — Two-axis scoping (entity × department).** Entity = `CompanyContext` (session `active_company_id`, default `TGI`); department = `DepartmentContext` (home vs viewed). Department access is *derived* from where you belong, never assigned as a role.

**D2 — `ActiveEntityScope` is a listing filter, not an auth boundary.** Route-model binding on `Ticket` deliberately drops it (`Ticket::resolveRouteBinding`). Any id-driven re-query must drop it too.

**D3 — Serving vs requesting department are separate columns.** `serving_department_id` = the desk delivering; `department_id` = the internal customer. Reading the wrong one is what once made Executive report requests raised as work delivered.

**D4 — One mailbox, per-department plus-addresses.** No per-department SMTP account. `DepartmentMailRouter` is the single source of truth for "our" addresses.

**D5 — Mail/IMAP config lives in the DB, not `.env`.** Hence no `config:cache` on deploy.

**D6 — Migrations auto-run on deploy.** Schema changes ship as migrations; hand-written ALTER SQL for the cloud is not the workflow.

**D7 — `useModuleRegistry.js` is the single source of the module tree.** Sidebar, hub pages and layout settings all derive from it.

**D8 — Reference data has exactly one source.** Departments come from `/departments`; project types live only in `reference_options` (`type=project_type`); company `type` (Entity/Brand) is a `reference_options` field. Never hardcode a list or accept free text.

**D9 — Route-level permission middleware is mandatory.** A hidden sidebar link proves nothing; `/projects` was once open to anyone who typed the URL.

**D10 — Gantt arithmetic is fixed.** Finish = Start + LeadTime − 1 (inclusive); parallel activities share the dependency's start day; milestone lead time = sum of sub-tasks; weekends and `holidays` rows are skipped. Once a numeric rule is confirmed, a later cosmetic request never redefines it.

**D11 — Board ↔ Project is a two-way structural sync.** Checklist=Milestone, Item=Activity, Subtask=Subtask; a card is never an activity.

**D12 — Dashboard tabs are lazily loaded** (`Inertia::optional` + `router.reload({only})`), cached until a filter changes.

**D13 — Derived health is never stored.** Asset RED/GREEN and store/brand health are computed live from linked tickets on each request.

**D14 — Ticket identity is stable across renumbering.** `ticket_key_aliases` preserves old keys so old email subjects still thread; retired numbers are never reissued.

**D15 — Dashboard has no department filter** (removed 2026-07-14). Don't re-add without confirming.

## Pitfalls (non-obvious behavior)

**P1 — SQL Server returns FKs as strings.** `$child->parent_id === $parent->id` is false. Cast every id in `$casts`.

**P2 — SQL Server rejects multiple cascade paths.** Secondary FKs need `->onDelete('no action')`.

**P3 — A SQL Server unique index rejects a second NULL.** Use a filtered index.

**P4 — `tickets.id` is a UUID.**

**P5 — `SELECT *` on ticket-like tables is a performance bug.** `nvarchar(MAX)` columns cross the Azure link; global search went 45 KB → 8.7 KB by pinning columns.

**P6 — A ticket with `company_id = NULL` is invisible to everyone** (the entity-gated index uses `whereIn`, which can never match NULL). `TicketObserver` backfills it and blocks nulling.

**P7 — Local DB ≠ live DB.** `.env` points at a local snapshot (`tashelpdeskdb` @ 127.0.0.1); the user's app runs against the Azure server. Counts from `tinker` will not match what the user sees.

**P8 — The app sends real mail synchronously.** See `docs/knowledge/Integrations.md`.

**P9 — Saving must never bounce to the first tab.** Keep tab state in the URL query and use `preserveState: true` on every mutating Inertia call (check inline `{ preserveScroll: true }` objects too).

**P10 — `AppLayout` already toasts `flash.success`.** Don't also call `showSuccess` in `onSuccess` — you get a duplicate toast.

**P11 — Oversized uploads die at PHP's limit before validation runs.** Every upload component must shrink images client-side via `useImageCompressor.js` instead of rejecting them.

**P12 — `position: fixed` dropdowns break under `backdrop-blur`/transform ancestors.** Teleport the panel to `body`, keep a `dropdownRef` click-outside handler and reposition on scroll.

**P13 — Email thread hijacking.** A reply with a changed subject used to be filed as a comment on the old ticket; a subject-similarity guard plus `email_intake_logs` now prevents and diagnoses it.

**P14 — PHP tests pass while pages are broken.** Browser QA is not optional.

## UI conventions (enforced)
- Row actions are always **round icon buttons**, never text labels.
- Every dropdown uses `Autocomplete.vue` / `MultiAutocomplete.vue`; native `<select>` is not acceptable (where one survives, it needs `pl-2 pr-7`, not `px-2`).
- Every module page uses `AppLayout` with `content-class="w-full max-w-none px-2 sm:px-4 lg:px-6"` — no inner `max-w` wrapper.

## Working agreements
- **Never run `git commit` / `git push`,** and never ask to. Print one short subject line only (e.g. `feat: export/import roles`) — no body, no trailers.
- Run the regression checklist after every session (see `CLAUDE.md`), including browser QA with **two contrasting user profiles** (elevated + restricted), never a single admin.
