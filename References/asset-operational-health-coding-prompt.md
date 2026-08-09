# Asset Operational Health Dashboard — Coding Assistant Prompt

Source presentation: [Service Operations Asset Brand Health Dashboard](https://docs.google.com/presentation/d/15ram1QOtQco_L4WMuJVWG7KhSU6SGFoNGwALHYR_okc/edit?slide=id.g38b8c1d7a66_0_422#slide=id.g38b8c1d7a66_0_422)

## Ready-to-paste prompt

```text
Study the existing ghelpdesk Laravel codebase before changing anything. Implement a per-physical-unit Asset Operational Health dashboard based on the reference slides in:

References/service-operations-asset-health-assets/

Business objective
- A posted fixed asset/unit allocated to a store begins OPERATIONAL (GREEN).
- When that exact physical unit is tagged to any active support ticket, it becomes IMPACTED (RED).
- It returns to OPERATIONAL only when zero active linked tickets remain.
- Active means the linked ticket is not resolved or closed and is not deleted/archived.
- Multi-ticket rule: if two active tickets tag the same unit, resolving only one must leave it RED. Resolve/close all linked active tickets to return it GREEN.
- Reopening a resolved/closed linked ticket must turn the unit RED again.

Use the existing architecture; do not create a parallel asset or ticketing subsystem.

Current codebase facts to respect
1. `assets` is the asset/SKU catalog (`App\Models\Asset`), not a physical-unit table. Do not put per-unit operational health on `assets`.
2. Physical serialized units are `stock_ins` rows (`App\Models\StockIn`) and are identified by `stock_ins.id`, serial number, barcode, and QR code.
3. Ticket-to-unit linkage already exists through `ticket_assets.stock_in_id` (`App\Models\TicketAsset`, `TicketAssetController`, and `Ticket::taggedAssets()`). Fixed assets already require a specific `stock_in_id`.
4. Ticket status transitions already flow through `TicketObserver`. Terminal statuses are `resolved` and `closed`.
5. `Tickets/Edit.vue` already searches and tags store-specific fixed units through `InventoryReportController::assetsSearch()` and the `LocatesInventoryUnits` concern.
6. Current location must account for Stock In, Transfer, and Receiving history. Reuse/extract `LocatesInventoryUnits::fixedUnitsCurrentlyAt()` and `locationVariants()`; do not rely only on `stock_ins.destination_location`, because transferred units may have moved.
7. Existing Live Store Health and Live Brand Health are aggregate open-ticket-count dashboards. Preserve their semantics. Add a separate lazy dashboard tab/component for physical-unit operational health rather than silently changing their thresholds or counts.
8. Dashboard entity scoping is based on the store's owning `company_id` using `CompanyContext::effectiveEntityIds()`. Prevent cross-entity leakage.
9. Stack/conventions: Laravel 12, PHP 8.2+, Eloquent, Inertia 2, Vue 3, Tailwind 4, Spatie permissions, PHPUnit 11; production supports SQL Server while tests use SQLite in memory.

Recommended design
- Prefer derived operational health as the source of truth:
  - RED/IMPACTED when an EXISTS query finds at least one `ticket_assets` row for the physical `stock_in_id` joined to a non-deleted ticket whose status is not `resolved` or `closed`.
  - GREEN/OPERATIONAL otherwise.
- Do not persist a mutable `health` column unless profiling proves the derived query is too slow. Derived state naturally handles tagging, untagging, resolving, closing, reopening, deleting, and the multi-ticket edge case without synchronization drift.
- If the business requires a human-readable tag such as `ST001-PC01`, add a nullable unique `asset_tag` to `stock_ins`, not `assets`. Preserve serial/barcode/QR identities and do not repurpose `assets.item_code` (SKU). Define collision-safe generation and backfill rules before making it required.
- Treat fixed serialized units as the initial scope. Consumables do not have one physical `stock_in_id`; either show them in a separate aggregate section or explicitly leave them out of this phase.

Backend implementation shape
- Add an `AssetOperationalHealthService` (or equivalently focused query/service) that:
  - resolves each fixed unit's current store correctly;
  - restricts stores by effective entity/company IDs and optional store filter;
  - computes operational status from active linked tickets;
  - returns totals (all units, operational, impacted, impacted percentage);
  - groups units by store and by configurable existing Category/SubCategory taxonomy;
  - provides drill-down rows with asset tag/serial/barcode, SKU, brand/model, category, store, current status, active-ticket count, and active ticket links.
- Add a lazy Inertia prop and dashboard tab such as `assetHealth` / `assethealth`, following the existing `storeHealth` and `brandHealth` loading/caching conventions in `DashboardController` and `Dashboard.vue`.
- Add a focused Vue component such as `AssetOperationalHealthReport.vue` rather than growing `Dashboard.vue` with a large inline implementation.
- Add authorized JSON drill-down endpoints if necessary. Keep entity/store scope server-side even when IDs arrive from the client.
- Extend `InventoryReportController::assetsSearch()` and the Affected Assets UI to search/display `asset_tag` if that field is introduced.
- Use existing Category/SubCategory records for the slide groups (POS Systems, Peripherals, Security/CCTV, Network & Connectivity, Digital Experience, Back Office). Do not hardcode a competing taxonomy unless mappings are explicitly required.

Dashboard UX
- Summary cards: Total Deployed Units, Operational (GREEN), Impacted (RED), and Impacted %.
- Store-level table/cards showing unit counts and red/green status.
- Category/group filters and store/entity filters consistent with the dashboard.
- Unit drill-down that shows every active linked ticket; do not show only the newest ticket.
- GREEN means no active linked tickets. RED means one or more active linked tickets.
- Include accessible text labels/icons in addition to color.
- Preserve dark mode and existing compact dashboard visual conventions.

Data integrity and edge cases
- A fixed unit may be tagged only if it currently belongs to the ticket's selected store. Revalidate this server-side at tag time; do not trust search results cached in the client.
- Existing duplicate prevention is per ticket + physical unit. Keep it.
- A unit may legitimately be linked to multiple different active tickets.
- Exclude soft-deleted/archived tickets from active-impact calculations.
- Decide and document behavior when a unit is transferred while an active ticket remains linked; the recommended behavior is that health follows the physical unit to its current location while ticket history remains intact.
- Count linked child tickets if they are active; per-unit health is based on any active linked ticket, not only root-ticket dashboard counting rules.
- Avoid N+1 queries. Use grouped joins/subqueries or eager loading, and add indexes justified by the final query plan (likely `ticket_assets.stock_in_id`, `ticket_assets.ticket_id`, and ticket status/deletion filters; existing indexes must be checked before adding duplicates).
- Make migrations SQL Server and SQLite compatible. Do not use unsupported partial indexes or database-specific enum alterations without guarded branches.

Required tests (write tests first where practical)
1. Posted fixed unit with no linked active tickets is GREEN.
2. Tagging that exact unit to an open ticket makes it RED.
3. Two active tickets linked to one unit: resolving one keeps it RED.
4. Resolving/closing the final active linked ticket makes it GREEN.
5. Reopening a linked ticket makes it RED again.
6. Removing the only active ticket-asset link makes it GREEN.
7. Soft-deleted/archived linked tickets do not keep it RED.
8. Search/tagging rejects a unit that is no longer at the ticket's store.
9. A received transfer places the unit under its current destination store and preserves its health/history.
10. Entity and store filters cannot expose units outside the user's effective company scope.
11. Existing ticket asset tagging, procurement condition fields, Store Health, Brand Health, and inventory ledger behavior remain unchanged.
12. Add frontend coverage if this repository has an established Vue test pattern; otherwise verify with backend feature tests plus `npm run build`.

Execution requirements
- First report the concrete files/classes you intend to change and any schema decision (especially whether `asset_tag` is needed).
- Implement in small, reviewable steps with migrations, services, routes/controllers, Vue component, and tests.
- Run the focused tests, then the relevant broader Laravel tests, `npm run build`, and Laravel Pint on changed PHP files.
- Do not claim completion unless tests and build actually pass; report any pre-existing failures separately.
```

## Why this design fits the current codebase

- `Asset` represents a catalog item/SKU; operational health belongs to a deployed serialized `StockIn` unit.
- `TicketAssetController` already enforces physical-unit selection for fixed assets and snapshots serial/barcode identity.
- `InventoryReportController::assetsSearch()` already restricts search to units currently at the ticket's selected store.
- `LocatesInventoryUnits` already follows received transfers, so it is the best existing basis for current-location resolution.
- `TicketObserver` already handles terminal/reopened ticket transitions, but derived health avoids adding another fragile synchronization hook.
- `BrandHealthService` and `StoreReportService` intentionally measure aggregate store backlog; the requested feature is a different metric and should remain a separate dashboard tab.
- The existing dashboard already uses lazy Inertia props and dedicated report components, providing a clear extension pattern.

## Slide-derived requirements

1. Stock-in and encode equipment with serial numbers and assign each unit to a store.
2. New deployed units start GREEN/OPERATIONAL.
3. Ticket creation includes a store-filtered search for the specific unit.
4. Tagging a unit to an active ticket changes it to RED/IMPACTED.
5. Resolution/closure restores GREEN only when no active linked ticket remains.
6. Group/report units under existing operational categories such as POS, peripherals, CCTV/security, network/connectivity, digital experience, and back office.
7. Provide a manageable dashboard count by store, equipment type/category, and live operational state.

## Visual references

- Workflow overview: [slide-02.png](service-operations-asset-health-assets/slide-02.png)
- Stock-in process and baseline status: [slide-03.png](service-operations-asset-health-assets/slide-03.png)
- Ticket search/tagging workflow: [slide-04.png](service-operations-asset-health-assets/slide-04.png)
- Multi-ticket RED/GREEN state logic: [slide-06.png](service-operations-asset-health-assets/slide-06.png)
- Category/group examples: [slide-07.png](service-operations-asset-health-assets/slide-07.png)
- Current Google Sheet example: [slide-08.png](service-operations-asset-health-assets/slide-08.png)
- Sample health dashboard: [slide-09.png](service-operations-asset-health-assets/slide-09.png)

All ten rendered slide images and the page-by-page extracted text JSON are available in `References/service-operations-asset-health-assets/`.
