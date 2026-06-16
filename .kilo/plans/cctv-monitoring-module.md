# CCTV Monitoring Module

A new **Monitoring** child module to track CCTV system health per store, with recurring (multi-visit) inspections, an annual store×month status matrix, an Excel importer, **mandatory ticket linkage** (every inspection creates a ticket # that surfaces in Tickets/Index.vue), and **inventory linkage** (each CCTV system reads/links the real physical DVR/NVR & camera units deployed at the store via the StockIn/inventory ledger).

Design follows the existing **NPC Status** monitoring module as the structural template (permissions, sidebar child, controller middleware, importer, per-entity periodic records). Inventory integration reuses the existing **StockIn / `InventoryTransaction` ledger** and the **`LocatesInventoryUnits`** trait + `InventoryReportController` patterns.

---

## Confirmed decisions

| Decision | Choice |
|---|---|
| Inspection cadence | **Flexible** — multiple visit logs per store per month; one flagged "latest" drives the matrix cell |
| Captured fields | Core health + Storage/power + LGU compliance |
| Importer | **Yes** — Excel/CSV importer + downloadable template |
| Matrix scope | Current year, all 12 months; year selector to switch |
| Ticket linkage | **Mandatory** — every inspection must have a ticket # |
| Ticket origin | **Created from CCTV module** — pre-filled ticket form submitted via existing ticket flow, then ticket_id stored on the inspection |
| Inventory linkage depth | **Full unit-level** — each inspection can link specific defective `StockIn` physical units (by serial); CCTV tickets auto-tag the linked units via `TicketAsset` |
| CCTV equipment identity | **item_code keyword match** — assets/units recognized as CCTV by keyword (DVR / NVR / Camera / CCTV) on item_code, brand, model, description (NOT by category) |

---

## 1. Data model (new migrations)

### `cctv_systems` — 1 row per store (static config)
- `id`, `store_id` (FK → stores, unique), `is_active` (bool, default true)
- `cctv_type` enum: `DVR`, `NVR`, `Hybrid` (nullable)
- `has_qr_code` bool, `setup_completed` bool
- `dpo_seal_checking` enum: `Pending`, `Done`, `N/A`
- `dvr_nvr_count` int (nullable), `expected_cameras` int (nullable)
- `created_by`, timestamps

### `cctv_inspections` — recurring visits (the monthly records)
- `id`, `cctv_system_id` (FK → cctv_systems, cascade), `inspection_date` (date)
- `overall_status` enum: `Working`, `Not Working`, `For Schedule`, `On-going`, `Pending`
- Core: `working_cameras` int null, `not_working_cameras` int null, `total_cameras` int null, `technician` string null
- Storage/power: `data_retention` string null (e.g. "40"), `storage` string null (e.g. "5TB"), `ups_status` string null
- LGU: `lgu_memo` string null, `lgu_status` enum: `Compliant`, `Non-Compliant`, `Pending`, `N/A`
- `next_step` text null, `remarks` text null
- `ticket_id` (FK → tickets, nullable but **enforced mandatory at app/validation layer**) — the linked ticket #
- `is_latest` bool (denormalized flag — true for the most recent visit in a given year-month for that system; maintained on save)
- `created_by`, timestamps
- Indexes: `(cctv_system_id, inspection_date desc)`, `(cctv_system_id, year, month)` via generated columns or composite

### `cctv_inspection_units` — unit-level linkage to physical StockIn units (pivot)
- `id`, `cctv_inspection_id` (FK → cctv_inspections, cascade), `stock_in_id` (FK → stock_ins, restrict)
- `condition` enum: `Working`, `Defective`, `N/A` (the unit's status during this visit)
- `notes` string null, timestamps
- Unique on `(cctv_inspection_id, stock_in_id)`
- Purpose: records which specific deployed camera/DVR-NVR unit (serial) was checked and its condition; powers auto-tagging the defective units onto the CCTV ticket.

> **Why a separate `cctv_systems` table** rather than columns on `stores`: keeps the stores table clean and matches the normalization pattern used elsewhere (e.g. npc_statuses, store_options). Store identity (code/brand/area/sector) is reused from `stores`.

### Ticket relationship
- `cctv_inspections.ticket_id` → `tickets.id`. Ticket already has `store_id`, `category_id`, `item_id` so CCTV tickets appear natively in Tickets/Index.vue.
- Ticket model gets a reverse accessor `cctvInspection()` (hasOne) for traceability.

---

## 2. Permissions

New permission group `cctv_monitoring`:
- `cctv_monitoring.view` / `.create` / `.edit` / `.delete`

Registration points:
- `database/seeders/RolesAndPermissionSeeder.php` — add to `$permissions` list; grant all to `Admin` and `Solutions Admin` (matching the existing npc_status pattern at lines ~166-169 and ~259-260).
- New migration `2026_06_xx_create_cctv_monitoring_permissions.php` to add the 4 permissions and grant to Admin + Solutions Admin for existing installs (mirrors `2026_05_08_000002_add_department_reference_permissions.php`).
- `app/Http/Services/RoleService.php` — add `'cctv_monitoring'` to `$preferredOrder` (after `'npc status'`) so the permission category appears in the right place in Roles & Permissions.

---

## 3. Backend

### Models
- `app/Models/CctvSystem.php` — relations: `store()`, `inspections()`, `latestInspection()`.
- `app/Models/CctvInspection.php` — relations: `cctvSystem()` (→ store), `ticket()`, `reporter()`, `linkedUnits()` (belongsToMany `StockIn` via `cctv_inspection_units`). Constants: `STATUSES`, `LguStatuses`. A `maintainLatestFlag()` helper runs on save/delete.
- `app/Models/CctvInspectionUnit.php` — pivot model (condition, notes) for `cctv_inspection_units`; relation to `stockIn`.
- `app/Models/StockIn.php` — add `cctvInspectionUnits()` hasMany (reverse) for traceability.
- `app/Models/Ticket.php` — `cctvInspection()` hasOne (reverse accessor) added earlier.

### CCTV equipment matcher (keyword-based)
- `app/Services/CctvEquipmentMatcher.php` (or static helper) classifies inventory as CCTV by keyword match on `assets.item_code`, `assets.brand`, `assets.model`, `assets.description` against `/\b(dvr|nvr|camera|cctv)\b/i`. Returns a role per match: `dvr_nvr` (dvr/nvr/cctv recorder) or `camera`.
- Used by both the controller (inventory context) and the importer.

### Controller: `app/Http/Controllers/CctvMonitoringController.php`
Template on `NpcStatusController` (middleware `can:` blocks, Inertia render, LengthAwarePaginator, attachment handling). **Uses the `LocatesInventoryUnits` trait** (same as `InventoryReportController`) to resolve store→inventory locations.

Actions:
- `index()` — renders `CctvMonitoring/Index`. Builds the matrix: for the selected year, list cctv_systems (with store) and, for each, the latest inspection per month (1-12). Includes status counts summary, filters (year, sector, brand, status, search), stores list, technician options. **For each cctv_system, attaches an `inventory_context` payload** (see §3a).
- `storeSystem()` / `updateSystem()` — create/edit a cctv_system (create only if none exists for the store; otherwise update).
- `storeInspection(CctvSystem)` — validate + create inspection. **Always creates a ticket first** (see §4) and stores its id. Accepts `linked_units[]` (stock_in_id + condition + notes) → syncs `cctv_inspection_units` pivot; any `Defective` units are auto-tagged onto the ticket via `TicketAsset`. Then `maintainLatestFlag()`.
- `updateInspection(CctvInspection)` — edit. If ticket_id missing, require/create one. Re-syncs linked units and ticket asset tags.
- `destroyInspection(CctvInspection)` — delete inspection (and its `cctv_inspection_units` cascade); ticket is NOT auto-deleted (left for Tickets module to own) but link is cleared.
- `unitsSearch(Request, Store)` — returns CCTV physical units currently at the store for the unit picker (mirrors `InventoryReportController::assetsSearch`): uses `fixedUnitsCurrentlyAt(locationVariants(store->code))` filtered through `CctvEquipmentMatcher`, returning serial_no/barcode/brand/model/asset_id/stock_in_id/equipment_role. Powers the "link defective unit" UI.
- `import(Request)` — parse uploaded xlsx/csv into cctv_systems + inspections (see §6).
- `importTemplate()` — download xlsx template.
- `export()` — optional: export current matrix to xlsx.

Middleware:
```php
new Middleware('can:cctv_monitoring.view', only: ['index','importTemplate','export','unitsSearch']),
new Middleware('can:cctv_monitoring.create', only: ['storeSystem','storeInspection','import']),
new Middleware('can:cctv_monitoring.edit', only: ['updateSystem','updateInspection']),
new Middleware('can:cctv_monitoring.delete', only: ['destroyInspection']),
```

### Routes (`routes/web.php`)
Add near the npc-statuses block:
```php
Route::get('cctv-monitoring/import-template', [..., 'importTemplate'])->name('cctv-monitoring.import-template');
Route::post('cctv-monitoring/import', [..., 'import'])->name('cctv-monitoring.import');
Route::get('stores/{store}/cctv-units', [..., 'unitsSearch'])->name('cctv-monitoring.units.search');
Route::post('cctv-systems/{cctvSystem}/inspections', [..., 'storeInspection'])->name('cctv-monitoring.inspections.store');
Route::put('cctv-inspections/{cctvInspection}', [..., 'updateInspection'])->name('cctv-monitoring.inspections.update');
Route::delete('cctv-inspections/{cctvInspection}', [..., 'destroyInspection'])->name('cctv-monitoring.inspections.destroy');
Route::resource('cctv-monitoring', CctvMonitoringController::class)
    ->parameters(['cctv-monitoring' => 'cctvSystem'])
    ->except(['show','create','edit']);
```

---

## 3a. Inventory & Stock-In linkage (full unit-level)

This is the integration with the **Stores inventory** set up in `StockIn` + `InventoryReportController`. Locations ≡ store codes/names (via `LocatesInventoryUnits::locationVariants()`).

**Per-store inventory context** (`inventory_context`, computed read-only, attached in `index()`):
- `camera_units` and `dvr_nvr_units`: physical `StockIn` units currently at the store, obtained via `fixedUnitsCurrentlyAt(locationVariants($store->code))`, filtered through `CctvEquipmentMatcher` (keyword match), each row = `{ stock_in_id, serial_no, barcode, qrcode, asset_id, item_code, brand, model }`.
- `camera_count`: count of camera units (used to prefill `total_cameras` on a new inspection).
- `dvr_nvr_count`: count of DVR/NVR units (prefill for the system's manual field if empty).
- Shown as a read-only "Deployed Equipment" panel on each store row / inspection modal.

**Unit-level linking on inspections** (the "full" depth):
- In the inspection modal, a **"Units Inspected / Defective"** section lets the user add specific physical units via the `unitsSearch` picker (search CCTV units at this store) and mark each `Working` / `Defective` / `N/A` + notes.
- Saved to `cctv_inspection_units` pivot (`linkedUnits()` sync on store/update).
- `not_working_cameras` auto-suggests = count of units marked `Defective` with camera role (editable).

**Ticket asset auto-tagging** (ties into §4):
- When the CCTV ticket is created/updated from an inspection, any `Defective` linked units are auto-tagged onto the ticket by creating `TicketAsset` rows (reusing the exact pattern the ticket module already uses): `{ ticket_id, asset_id, stock_in_id, serial_no, barcode, transaction_type: 'Tagged', quantity: 1, notes }`.
- Result: the defective camera/DVR unit's serial appears in the ticket's tagged-assets list and in `InventoryReportController::ticketActivity`, giving full traceability from CCTV inspection → ticket → physical unit.

> Keyword match (not category) was chosen for equipment identity, so this works regardless of whether CCTV assets are categorized. The separately-seeded CCTV `Category`/`Item` are still used **only** for ticket item classification (so CCTV tickets are filterable/recognizable).

---

## 4. Ticket linkage (mandatory)

Every `storeInspection`/`updateInspection` flow guarantees a `ticket_id`:

1. The inspection modal in the UI always shows a "Ticket" section. If a ticket already exists, it displays the `ticket_key` as a link. If not, a **Create Ticket** form is shown (title/description/priority, with store + CCTV category/item pre-filled and read-only).
2. On submit, the controller:
   - If `ticket_id` provided → validate it exists & belongs to the same store (or warn).
   - Else → build a `StoreTicketRequest`-equivalent payload and call the same creation logic used by `TicketController::store` (reuse `Ticket::create([...])` + `ticket_key` generation + optional attachment handling) so the ticket is a real, full ticket that shows in Tickets/Index.vue.
   - Subject auto-format: `CCTV Inspection – {Store code/name} – {Month Year}`.
   - Sets the CCTV `category_id`/`item_id` (created via seeder if missing — see §8) so the ticket is filterable/recognizable as CCTV.
   - **Auto-tags any `Defective` linked units** onto the ticket via `TicketAsset` (see §3a).
3. The created ticket's `ticket_key` is returned to the UI and rendered as a clickable badge on the inspection row/cell.
4. **Tickets/Index.vue changes (minimal):**
   - CCTV tickets already appear normally (they're real tickets with store/category). 
   - Add a small "CCTV" tag in the ticket card when `category` is CCTV, and add a "CCTV" quick-filter chip (mirrors existing category/store filters) so users can isolate CCTV-origin tickets.
   - Optional reverse link: in the ticket detail/edit page, if `ticket.cctvInspection` exists, show a "View CCTV inspection" link.

---

## 5. Frontend (`resources/js/Pages/CctvMonitoring/Index.vue`)

Layout mirrors `NpcStatus/Index.vue` + `Stores/Index.vue` patterns (AppLayout, DataTable, filters, modals, usePagination, useToast, useConfirm, useErrorHandler, usePermission).

Top section — **status summary cards**: counts of Working / Not Working / For Schedule / Pending stores (latest status, current year) + compliance %.

Filters row: year selector, sector, brand, status, search; plus **Import** and (permission-gated) create buttons.

**Main matrix** — store × month grid:
- Rows = cctv_systems (store code + brand + area + sector); sortable/filterable.
- Columns = Jan…Dec for the selected year.
- Each cell = color-coded status chip of the latest inspection for that month (Working=green, Not Working=red, For Schedule=amber, On-going=blue, Pending=gray, none=blank). Clicking a cell opens the inspection detail modal listing all visits for that store+month and a ticket badge.
- A final "Ticket" column shows the latest inspection's linked ticket_key as a clickable link.

**Modals**:
- Inspection modal (create/edit): date, status, camera counts (working/not-working/total with auto-calc total; `total_cameras` prefilled from `inventory_context.camera_count`), technician, data retention, storage, UPS status, LGU memo, LGU status, next step, remarks, the **"Units Inspected / Defective"** section (unit picker via `unitsSearch`, per-unit condition + notes), and the mandatory **Ticket** section (create or show existing).
- **Deployed Equipment panel** (read-only): shown inside the inspection modal + as an expandable drawer on each matrix row; lists the store's CCTV physical units (serial, brand/model, role) from `inventory_context`.
- CCTV System config modal: cctv_type, has_qr_code, setup_completed, dpo_seal_checking, dvr_nvr_count (prefilled from inventory count if empty), expected_cameras.
- Import modal (reuses Stores importer styling): file input + results/errors display + template download link.

Reused composables/components: `DataTable`, `usePagination`, `useToast`, `useConfirm`, `useErrorHandler`, `usePermission`, `MultiAutocomplete` (technician/unit pickers), `Autocomplete` (unit search).

---

## 6. Importer

Mirrors the Stores importer (`StoreController` import + `stores.template` route).
- Template columns map the user's spreadsheet:
  - Static: STORE CODE, BRAND, AREA, Sector, Store type, Sector No, CCTV TYPE, QR CODE, Completed Setup, DPO Seal Checking, Total DVR/NVR No.
  - Monthly (×12): Date, Status, Working Camera, Not Working Camera, Total Camera, Data Retention, Storage, UPS Status, LGU Memo, LGU Status, Tech Eng, Next Step, Remarks.
- Match rows to stores by `store_code`. If a store doesn't exist, report an error (do not auto-create stores).
- For each month with a Status value → create a cctv_inspection.
- After import, the controller runs the `CctvEquipmentMatcher` against the store's inventory and, for camera-asset SOH, can prefill `total_cameras`; unit-level linkage is **not** auto-created on import (serials aren't in the sheet) — that stays a manual UI step per inspection.
- **Ticket handling on import**: because ticket linkage is mandatory, the importer creates a CCTV ticket per imported inspection row (subject `CCTV Inspection – {code} – {Month Year}`), OR — if that is too heavy for a bulk import — creates the inspections with a clearly flagged "ticket pending" state and offers a bulk "Generate tickets" action. **Decision needed during implementation** (see Open question).

---

## 7. Sidebar wiring

- `resources/js/Composables/useSidebarOrder.js`:
  - `DEFAULT_CHILD_ORDER.monitoring`: add `'cctv-monitoring'` (after `'npc-status'`).
  - `CHILD_LABELS.monitoring`: `'cctv-monitoring': 'CCTV Monitoring'`.
- `resources/js/Components/Sidebar.vue`:
  - `canSeeMonitoring` computed: add `|| hasPermission('cctv_monitoring.view')`.
  - Add a `<Link>` child under the Monitoring section (both collapsed-flyout and expanded variants), gated on `hasPermission('cctv_monitoring.view')`, route `cctv-monitoring.index`, using `co('monitoring','cctv-monitoring')` and `getChildLabel(...)`.
  - Extend the `route().current(...)` active-state check to include `cctv-monitoring.*` so the menu auto-opens.

---

## 8. Seeder additions

- New CCTV category + item (`Categories`/`Items`) so CCTV tickets have a home: name "CCTV", and item(s) like "CCTV – General", "CCTV – DVR/NVR", "CCTV – Camera Defective". Created via migration/seeder idempotently. (Used for **ticket item classification only**; inventory equipment is matched by keyword — see §3a.)
- Grant the 4 `cctv_monitoring.*` permissions to Admin + Solutions Admin.

---

## 9. File inventory (new/edited)

**New**
- `app/Models/CctvSystem.php`
- `app/Models/CctvInspection.php`
- `app/Models/CctvInspectionUnit.php` (pivot)
- `app/Services/CctvEquipmentMatcher.php` (keyword classifier)
- `app/Http/Controllers/CctvMonitoringController.php` (uses `LocatesInventoryUnits`)
- `database/migrations/2026_06_15_000001_create_cctv_systems_table.php`
- `database/migrations/2026_06_15_000002_create_cctv_inspections_table.php`
- `database/migrations/2026_06_15_000003_create_cctv_inspection_units_table.php`
- `database/migrations/2026_06_15_000004_create_cctv_monitoring_permissions.php`
- `database/migrations/2026_06_15_000005_seed_cctv_category_and_items.php`
- `resources/js/Pages/CctvMonitoring/Index.vue`
- `resources/js/Components/CctvMonitoring/CctvInspectionModal.vue` (optional split)

**Edited**
- `routes/web.php` — CCTV routes (+ `stores/{store}/cctv-units`)
- `resources/js/Components/Sidebar.vue` — child link + canSeeMonitoring
- `resources/js/Composables/useSidebarOrder.js` — child order + label
- `database/seeders/RolesAndPermissionSeeder.php` — permissions + role grants
- `app/Http/Services/RoleService.php` — preferredOrder
- `app/Models/Ticket.php` — `cctvInspection()` accessor
- `app/Models/StockIn.php` — `cctvInspectionUnits()` reverse relation
- `resources/js/Pages/Tickets/Index.vue` — CCTV badge + quick filter (minimal)

> Note: `2026_06_15_000001`/`_000002`/`CctvSystem.php` were already created during the (paused) first implementation pass; they match this plan and will be reused. `_000003` pivot + renumbering of later migrations to be applied on resume.

---

## 10. Implementation order

1. Migrations (systems → inspections → **inspection_units pivot** → permissions → category/items) + run.
2. Models + relations + `maintainLatestFlag()`; `CctvEquipmentMatcher` service.
3. Controller (matrix builder + `inventory_context` + `unitsSearch`), routes, middleware, `LocatesInventoryUnits`.
4. Index.vue: matrix + filters + summary cards + Deployed Equipment panel.
5. Inspection modal incl. mandatory ticket creation + unit-level linking (defective-unit picker) + `TicketAsset` auto-tagging.
6. System config modal.
7. Sidebar + sidebar-order wiring.
8. Importer + template.
9. Tickets/Index.vue CCTV badge + filter.
10. Seeder grants; manual smoke test per role + verify defective-unit appears in ticket's tagged assets.

---

## 11. Validation / lint

- Run project lint/typecheck (npm) and `php artisan`/Pint if configured. Follow existing code style (no comments unless asked).
- Manually verify per role: create an inspection → ticket created → appears in Tickets/Index.vue → badge links back → linked defective serial shows in ticket assets / `ticketActivity`.

---

## Open question (resolve before/during implementation)

**Import + mandatory ticket**: Bulk import may create dozens of inspections. Auto-generating a ticket per row could create ticket noise. Preferred approach: import inspections with status, then run a one-click **"Generate missing CCTV tickets"** action per the current filter (creates a ticket for each inspection lacking one, with a CCTV-category subject). Confirm this is acceptable; otherwise auto-generate per row during import.

---

## Risks / notes

- The flat spreadsheet contains many empty/legacy columns (e.g. duplicate "Remarks5", 1900 dates). Importer must ignore/normalize these; the normalized schema is intentionally simpler than the sheet.
- `is_latest` denormalization must be maintained atomically (DB transaction on save/delete) to keep the matrix correct.
- Ticket creation reuses existing ticket logic; ensure CCTV tickets don't trigger unwanted notifications/surveys unless desired (confirm during implementation).
- **Inventory location matching depends on consistent store codes**: `fixedUnitsCurrentlyAt` resolves a store to its code+name variants; if a store's CCTV units were posted under a different location string, they won't appear. The Deployed Equipment panel is informational; manual counts remain editable.
- Keyword matching may over/under-match (e.g. a "Camera" item that isn't a CCTV camera). The matcher's keyword list is configurable; refining it is a quick post-launch tweak.
