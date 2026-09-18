# Department reference setup

This change extends reference selection with the service-provider department already recorded on a ticket. It preserves Settings > Mail Configuration, mailbox routing, and the existing ticket list's provider/customer behavior.

## Deployment

Deploy the application and run the normal migrations before serving requests with the new code:

- `2026_09_18_100000_add_department_ticket_references.php` adds department ownership to Clusters, Categories, Sub-Categories, and Items.
- `2026_09_18_140000_tag_existing_references_to_default_department.php` tags every catalogue row that is still untagged to **Technology and Solutions (TAS)** in its own entity — the desk that has been running the helpdesk until now. Rows a department has already claimed keep their owner. The migration refuses to run if two untagged rows (or an untagged row and an already tagged one) would collide under the catalogue's unique index; rename them and deploy again.

Stores and Vendors carry **no** department tag. They are the entity's own locations and suppliers, so every department serving that entity sees the same list with nothing to enable. The `department_store` / `department_vendor` tables from the first migration are left in place but unused — no migration drops them.

The migration has been exercised on isolated SQLite through the feature tests. Validate the filtered indexes and foreign keys on an isolated SQL Server staging database before production rollout. No application database migration was executed during implementation.

The migration intentionally refuses automatic rollback: after departments create references with identical names, restoring global uniqueness requires a reviewed consolidation. A compatible application rollback can leave the additive schema in place.

## Configure each service department

1. Select the owning entity and service department in the existing navigation.
2. On **Categories** and **Sub-Categories**, use **Tag to this department** for existing untagged records. New records receive the selected department automatically.
3. On **Items**, tag the existing items after their category/subcategory are tagged. Category and subcategory must belong to the same entity and department as the item. Create separate catalog records where two departments need independent versions of the same classification.
4. **Stores** and **Vendors** need no configuration: they follow the entity, and every department of that entity can use them.
5. On **Clusters**, tag or create this department's groups, then assign any store of the entity.

Catalogue ownership **can** be changed after the fact: each row on Clusters, Categories, Sub-Categories and Items shows `Service department: <name>` with a **Change** link that opens a department picker. Moving a row is refused when the target department already has a reference with the same identity (cluster name/code, category or sub-category name, item classification + name) — create a separate record there instead.

Reference mutations require the module's existing permission. Department catalogue management additionally requires the user's home department to match, or `departments.edit` for administration — that also applies to retagging, so a desk can claim or release only its own rows while an administrator can move a row between departments. Store/Vendor master edits retain their existing entity permissions.

## Ticket behavior

- New manual tickets default to the viewed service department. Email/form tickets retain their existing route.
- Existing ticket choices use the ticket's serving department even when opened from another department tab. Accepting unrouted intake records the selected desk.
- Item/category/subcategory choices require department ownership. Stores and vendors are shared by every department of the entity; existing entity and brand rules still apply to them.
- Server validation uses the final store/entity after auto-assignment and covers model-based automated creation as well as controller saves. Bulk changes validate all targets before writes.
- Unclassified email/form intake remains possible while references are configured. New classification and new classified child tickets require valid tags and coverage.
- Saved legacy references remain readable, and unrelated edits can still be saved. Changing classification, store, entity, or provider requires eligible references. Disabled/inactive saved references are retained in that ticket's picker for display, but server validation prevents assigning them to new classifications.
- Entities without a department configuration retain their legacy behavior. Once departments are configured, untagged catalog rows are available for repair on reference management pages, not as new ticket choices.
- Catalog imports use the selected department. Item import names are resolved within the owning entity/department. Department-specific duplicate names are supported without changing Store/Vendor identity rules.

For CCTV ticket generation in a department workspace, configure/tag the **CCTV** category and **CCTV – General** item first. The inspection flow uses that department's existing catalog rather than creating or selecting another department's similarly named records.

## Verification

Persistent coverage is in `TicketDepartmentReferencesTest.php`, alongside the existing entity-reference, mailbox, acceptance, form-ticket, project-ticket, store, and ticket-source tests. Tests run with SQLite `:memory:`, array mail/cache, and no application data access. PHP syntax checks, the route listing, and the production frontend build are also checked.
