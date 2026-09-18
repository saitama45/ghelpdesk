# Ticket references by service-provider department

Assessment date: 2026-09-18. This document records the pre-implementation findings and proposal. Implementation and activation instructions are now in [Department reference setup](department-reference-setup.md). The application database has not been migrated during this work.

The user confirmed that Stores and Vendors should remain shared records with department-specific allowed lists and agreed with the recommended approach for all six references.

## Confirmed scope after follow-up

The user clarified that department email routing is already configured in `/settings` > Mail Configuration. The requested change is department tagging, visibility, and validation of reference fields. Preserve existing mailbox routing, ticket receiving behavior, shared-intake settings, and provider/customer access rules. The broader routing/authorization observations below are assessment context, not additional implementation requirements.

The Settings implementation has now also been traced directly:

- `resources/js/Pages/Settings/Index.vue` renders department mailbox fields and the require-department-address setting.
- `SettingsController::departmentMailboxes` reads each department's `mail_address` and `mail_from_name`; `saveDepartmentMailboxes` saves them, checks duplicate submitted addresses, and flushes the routing cache.
- `DepartmentMailRouter::resolve` maps a matching recipient address to the department ID, including when a department owns the base support address.
- `EmailTicketService` persists that ID in `tickets.serving_department_id`. Replies preserve an existing route. The require-department-address setting handles new unrouted mail before ticket creation.
- `Ticket::scopeOwnedByDepartment` prioritizes that explicit serving department over the assignee fallback.

Use the department already recorded on the ticket as the reference-filter key. No new mail routing configuration or intake policy is needed for this scope. The current settings values and live deliveries were not queried; this verification follows the UI and backend code.

## Intended result

Keep the existing entity/brand eligibility rules, then restrict ticket references to the department delivering the service. Each provider department has its own catalog, store coverage, vendor list, and cluster organization.

For example, TGI / TAS and TGI / FM can serve the same CBTL store and use the same vendor identity. TAS tickets offer TAS classification items and TAS-approved vendors; FM tickets offer FM items and FM-approved vendors. A request raised by Marketing for TAS uses the TAS catalog. The requesting department remains Marketing.

Three separate checks are necessary:

1. Can this user perform this action on this ticket or reference?
2. Is this reference eligible under the existing ticket/store entity rules?
3. Is this reference available to this ticket's service-provider department?

Passing one check must not implicitly satisfy the others. Duplicate-name rules are a fourth concern: catalog names may repeat across departments without making those departments' records interchangeable.

## What the repository implements today

| Area | Evidence | Consequence |
| --- | --- | --- |
| Reference management | `app/Support/EntityReferenceScope.php`: `visible`, `owned`, `ensureOwned` | The six reference pages distinguish owned, inherited, and null-company shared records, but do not scope by provider department. Shared rows can currently be edited from different entities. |
| Entity inheritance | `EntityReferenceScope::fitsCompany`, `Company::itemSourceIds` | A brand can use catalog rows belonging to its tagged entities. Preserve this behavior. |
| Stores | `TicketController::storesForCompanies` | Ticket pickers also expand entities to their brands' stores. The code explicitly says this is picker filtering, not a save restriction, to support project rollout tickets. |
| Ticket lists/exports | `TicketController::applyDepartmentAxis`, `Ticket::scopeOwnedByDepartment` | Department ownership exists: explicit serving department, otherwise assignee's department. Provider lists also include the unrouted AND unassigned shared intake pool. |
| Provider versus requester | `app/Support/TicketAccess.php` | Other departments' customers are restricted from several desk actions. Administrative roles, executive sessions, and users without a home department have exceptions. |
| Requester department | `tickets.department_id`, `tickets.department` | These represent the requesting side. They must not become reference-catalog ownership keys. |
| Ticket classification endpoints | `TicketController::getCategories`, `getSubCategories`, `getItems` | Categories/items are not department-filtered. Items return entity eligibility metadata and the browser filters it. Subcategories are derived through Items. |
| Ticket validation | `StoreTicketRequest`, `UpdateTicketRequest`, `assertItemMatchesStoreEntity`, `assertVendorMatchesStoreEntity` | Basic references use table-wide existence validation; item/vendor entity checks are added in selected controller paths. There is no provider-reference validation. |
| Catalog structure | `Item`, `Category`, `SubCategory`; migration `2026_03_05_114810_remove_category_id_from_sub_categories_table.php` | Items carry both category and subcategory IDs. Subcategories do not currently belong directly to one category. Do not assume a conventional three-level hierarchy. |
| Uniqueness | Six reference controllers; `ItemController::store/update/import` | Item uniqueness includes entity and classification fields. Cluster/category/subcategory and store/vendor validation still contains global unique rules. Existing entity visibility does not imply all names are unique per entity. |
| Department identity | September 17 department migrations and `DepartmentEntityUniquenessTest` | Department names can repeat across entities; codes remain globally unique. Use department IDs, not names, for mappings. |
| Shared business identities | `Store` relationships and `Vendor` portal/profile/document relationships | Duplicating stores or vendors per department would split operational and portal history. Use allowed-list relationships as confirmed. |

The current ticket entity list filter uses `tickets.company_id`; reference eligibility usually uses the selected store's `company_id`, with the ticket company as fallback. These are different concepts and can legitimately differ for entity projects serving brand stores.

## Specific gaps to address

1. **Create UI does not submit a provider department.** The create form in `resources/js/Pages/Tickets/Index.vue` has the requester `department` string but no `serving_department_id`, although the create request accepts that ID. Selecting a department tab alone does not persist it through this form.
2. **Validation happens before final routing.** `TicketController::store` checks item/vendor entity eligibility before auto-assignment may replace the company and store. Validate the final resolved ticket context after routing and overrides.
3. **Changing context can retain incompatible references.** `update` checks entity compatibility only when item/vendor IDs change. A store or entity change with unchanged reference IDs is not covered by those checks. New department rules must validate changes to any relevant context field.
4. **Ownership can depend on a user's current placement.** `accept` assigns a user but does not stamp `serving_department_id`. An unrouted ticket can therefore move between departments implicitly if its assignee's department later changes.
5. **Not all child paths preserve provider ownership.** Normal child creation, vendor escalation, and duplicate preserve the serving department. `bulkStoreChild` does not copy the serving or requester department IDs. It can resolve to the scheduled user's department instead.
6. **Bulk writes require explicit validation.** The non-status branch of `bulkUpdate` uses a query update, bypassing model events. A model observer alone cannot enforce the new rules.
7. **Related-reference pickers/imports are broad.** Item category/subcategory selections and import name maps use unscoped lookups. Cluster store assignment uses table-wide store existence validation. These become ambiguous or invalid once names can repeat by department.
8. **Department filtering is not a complete read-authorization policy.** Ticket route binding deliberately bypasses the entity listing scope. `TicketAccess` governs provider/customer actions, but does not itself prove the actor is the ticket's requester or permitted follower. `requesterTickets` also does not apply the main list's department axis. Define and apply ticket read authorization to direct URLs and auxiliary endpoints if departmental visibility is intended as a confidentiality boundary.
9. **Null currently has permissive meanings.** Null-company references are shared; no effective reference entity can mean an unrestricted result; unrouted tickets may be common intake. Those defaults cannot silently become the strict department policy.

These are code observations. They are not claims about how many affected records exist in the running database.

## Recommended data model

| Reference | Proposed storage and ownership | Uniqueness |
| --- | --- | --- |
| Clusters | Add provider `department_id`; department-specific groups keep the existing `cluster_store` relationship to shared stores. | `(company_id, department_id, code)` and `(company_id, department_id, name)` |
| Stores | Keep canonical store rows and their existing company/brand ownership. Add `department_store` allowed-list rows. | Unique `(department_id, store_id)` membership; preserve canonical store identity rules. |
| Vendors | Keep canonical vendor rows, credentials, profiles, approval state, and documents. Add `department_vendor` allowed-list rows. | Unique `(department_id, vendor_id)` membership; preserve portal identity/email rules. |
| Categories | Add provider `department_id` to department-owned catalog rows. | `(company_id, department_id, name)` |
| Sub-Categories | Add provider `department_id`; keep the existing Item-mediated category relationship. | `(company_id, department_id, name)` |
| Items | Add provider `department_id`; require category and subcategory to belong to the same supported catalog context. | Existing entity/category/subcategory/concern-type/name key plus `department_id`. |

Allowed-list rows should carry an enabled state and audit information so removal from future selection does not destroy history. Department-specific labels or partner defaults can live on those relationships if needed later; do not copy the canonical vendor account.

For new owned catalog rows, stamp the provider entity and provider department from validated server context. A brand consuming an entity department's catalog does not become the owner of those rows. A legacy brand-owned item needing an entity department requires an explicit migration mapping or copy into that provider's catalog; do not silently claim ownership from matching department names. Cross-entity provider service beyond the existing inheritance rules requires an explicit service coverage relationship, not a general bypass.

Use database constraints as well as request validation. Inspect existing deployed indexes before replacing global uniqueness. Plan SQL Server-compatible indexes for nullable classification fields and staged nullable department IDs, and test their actual behavior; do not rely on SQLite uniqueness behavior alone. Prefer non-cascading department references and deactivate departments in use. Clearing a department ID must not turn private catalog data into shared data.

## One explicit context and validation service

Introduce a focused service such as `TicketReferenceContext` / `TicketReferenceCatalog` rather than a session-dependent global scope on reference models.

Its input is the resolved ticket company, selected store, serving department, acting user/source, and optional existing ticket. Queue/email callers supply the same business context explicitly; they cannot depend on a browser session.

The service should:

- Resolve and authorize the provider department and relevant entity/brand coverage.
- Return only eligible active stores, vendors, clusters, categories, subcategories, and items for selection.
- Apply existing entity eligibility AND department ownership/membership.
- Keep catalog use separate from catalog management: customers may select a provider's published services without acquiring edit access to that provider's references.
- Validate assignee placement or an explicitly supported cross-department assignment rule.
- Validate a complete proposed ticket state, not just fields that happened to change.
- Derive category, subcategory, and priority from a selected item on the server. Reject incompatible standalone classifications when no item is selected.
- Return an existing saved reference separately for display if it is inactive or no longer selectable. It must not become a newly selectable option for unrelated tickets.

Use exact department IDs. Do not derive the provider from the requester department, reference names, department codes, or whichever tab happens to be selected when an existing ticket is opened.

Suggested provider resolution:

1. Existing ticket: persisted serving department, unless an authorized transfer is explicitly requested.
2. New ticket from a routed source: mailbox/form/service department supplied by that source.
3. New manual ticket: explicit provider choice, defaulted from the viewed department. Validate it against service eligibility, including entity/brand inheritance.
4. Legacy intake only: preserve existing routing and visibility behavior. Resolve a provider context when department-owned classification is selected; a missing provider must not silently make every department's reference catalog selectable.

For the reference work, retain an existing serving department and validate against it. Preserve routed sources' existing creation behavior. Any future provider-transfer feature should be a distinct audited operation that revalidates references and assignment; introducing that feature is outside the narrowed scope.

## Application changes

### Reference management

Extend entity reference filtering with explicit department ownership/allowed-list filtering in all six controllers, including related dropdowns, import, export, templates, and assignment endpoints. Keep shared Store/Vendor master edits under their existing owner permissions; department providers manage their own allowed-list membership.

Expose the provider entity and department in the management UI. New catalog records receive that validated context automatically. Imports must match by scoped IDs/codes or scoped names and reject ambiguity; `pluck('id', 'name')` across departments will lose information.

Use module permissions plus department ownership for mutations. Add permission-backed capabilities for managing coverage or transferring provider ownership when needed, including `RoleService` and seeder updates. Review the existing administrative/unplaced-user bypasses explicitly rather than making new reference enforcement inherit all of them.

### Ticket pickers and writes

- Extend `/tickets/data/*` or add a unified reference endpoint with explicit provider/store/entity inputs. Filter on the server; browser filtering is only a usability layer.
- Update Tickets Index create/accept/bulk forms, Tickets Edit, vendor escalation, and ProjectGantt's ticket creation. Update or replace `resources/js/lib/entityItems.js` with the added department dimension.
- On provider/store/entity changes, reload eligible options and clear invalid pending choices. Key caches by the full context and discard stale asynchronous responses after switching.
- Resolve all automated company/store/assignee changes before final validation, then write in a transaction. Re-check permission/provider state under the acceptance or transfer lock where concurrent ownership changes matter.
- Validate every target in a bulk operation before writing; either split the operation by provider or reject an incompatible mixed batch atomically.
- Keep saved historical labels visible without advertising them as valid choices for new classification. Permit unrelated edits under an explicit legacy policy; revalidate whenever provider, store, entity, or reference selection changes.

### Other ticket producers and readers

Inspect and adapt all producers identified by repository search: `EmailTicketService`, `DynamicForms/DefaultFormService`, `PosRequestService`, `SapRequestService`, `PublicQueueController`, `CctvMonitoringController`, `QatFindingController`, `UatFindingController`, and TicketController child/duplicate/split/bulk paths. Email and dynamic forms already provide useful department routing; preserve it and validate conflicting auto-assignment.

Broader ticket read-authorization changes are outside the narrowed scope. Preserve intended requester follow-up access and explicit executive access. Dashboard reporting semantics are a separate existing product decision; do not silently add dashboard department filters as part of reference scoping.

Review department-sensitive downstream behavior such as SLA settings, item-based reporting, knowledge-base generation, and hardcoded/seeded default items when mapping catalogs. `DepartmentService` is the existing service directory; provider reference catalogs should align with its department IDs rather than introducing another department registry.

## Migration and rollout

1. **Inventory:** run a read-only report in the intended environment for reference owners, null companies/departments, item classification consistency, reference usage by serving department, shared store/vendor coverage, and name collisions. Show ambiguous mappings for review. Ticket usage can suggest a mapping; it is not proof of ownership. Local snapshot results do not establish live counts.
2. **Additive schema:** add nullable catalog department columns, allowed-list tables, suitable indexes, and audit support. Keep existing ticket foreign-key values intact.
3. **Populate reviewed mappings:** assign unambiguous catalog ownership and create store/vendor memberships. For a catalog row used by multiple providers, deliberately create department-owned copies where independent maintenance is required. Preserve original rows for historical links; carry over dependent settings intentionally. Do not move every legacy row into the active user's department or treat all unmapped rows as globally available.
4. **Compatibility deployment:** ship context-aware reads/writes and all producer adapters. Report unresolved configurations with a clear intake/legacy path so mail is not silently lost. Do not enable strict routing before producers can supply it.
5. **Enforce:** enable department restrictions once mappings and intake routing are ready; finish scoped unique constraints and required ownership for new catalog rows. Remove temporary legacy fallbacks only after their usage reaches zero.
6. **Observe:** track rejected reference choices and unresolved routes without exposing sensitive ticket payloads. Roll back through a compatible application release/feature switch if needed; avoid destructive schema rollback once duplicate names across departments exist.

The repository documents automatic migrations on deploy. Keep schema deployment separate from a large, assumption-heavy data backfill, with resumable batches and a reviewed mapping artifact.

## Acceptance tests

- Same entity, different provider departments: each sees only its own classifications, allowed stores/vendors, and clusters.
- Same name in two departments succeeds; duplicate identity within one department fails in both validation and database constraints.
- A store/vendor can be enabled for both TAS and FM; removing FM membership preserves TAS access and historical tickets.
- Entity-to-brand inheritance and project rollout stores still work; unrelated entities and sibling brands do not acquire access.
- Marketing requesting TAS gets TAS choices while retaining Marketing as requester.
- Changing store/company/provider while retaining an incompatible item or vendor is rejected; request tampering cannot bypass picker rules.
- Item/category/subcategory combinations cannot cross provider catalogs, including imports and standalone reference submissions.
- Create validates final auto-assignment results; accept stamps provider ownership; staff transfers do not silently move existing tickets.
- Single/bulk children, duplicates, split, escalation, and automated producers preserve or explicitly resolve provider ownership.
- Mixed-provider bulk failures leave no partial changes; concurrent acceptance cannot bypass the final provider check.
- Unrouted intake has the documented restricted workflow; null context does not return every catalog to an ordinary user.
- Existing inactive/legacy references remain readable but cannot be reused on new tickets without eligibility.
- Read authorization, where strengthened, preserves authorized requester follow-up and rejects unrelated direct/auxiliary access.
- Entity/department switching and rapid picker reloads cannot reuse another context's cached options.
- Existing entity, mailbox, form-ticket, project-ticket, acceptance, and uniqueness regression tests pass in an isolated test database; index/migration behavior is also checked on isolated SQL Server.

Existing starting points: `ReferenceEntityScopeTest`, `ItemEntityScopeTest`, `TicketItemEntityTest`, `DepartmentEntityUniquenessTest`, `DepartmentMailboxSettingsTest`, `DynamicFormTicketCreationTest`, `ProjectSubTaskTicketTest`, and `TicketAcceptTest`.

## Assessment limits

This assessment inspected application code, Vue forms, migrations, documented decisions, and test source. It did not connect to a database, execute tests, run migrations, or verify live data. The confirmed reference design is incorporated. Follow-up scope clarification preserves existing mail/intake/access behavior; remaining implementation work concerns catalog ownership mappings, allowed lists, reference visibility, and reference validation.
