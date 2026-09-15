/**
 * Ticket item pickers follow the company of the selected store.
 *
 * Items belong to an entity (items.company_id, managed per entity on /items).
 * A store's company may use its own items plus the items of every Entity it is
 * tagged to on /companies (entity_brand) — so a NONOS store also gets TGI items.
 * `tickets.data.items` sends that list per item as `usable_company_ids`. When no
 * store is picked yet, the form's own company is used instead. The server
 * enforces the same rule (TicketController::assertItemMatchesStoreEntity).
 */

/** The company id a ticket form should draw items for. */
export const entityIdForStore = (stores, storeId, fallbackCompanyId = null) => {
    const store = storeId ? (stores || []).find((s) => String(s.id) === String(storeId)) : null;

    return store?.company_id ?? (fallbackCompanyId || null);
};

/** Whether one item may be used by a company. Items with no entity fit everywhere. */
const usableBy = (item, companyId) => {
    if (item.company_id == null) return true;

    const allowed = Array.isArray(item.usable_company_ids) ? item.usable_company_ids : [item.company_id];

    return allowed.some((id) => String(id) === String(companyId));
};

/**
 * Items a company may use. `keepItemId` keeps an already-saved item listed so an
 * older ticket still shows its current value. Without a company nothing is filtered.
 */
export const itemsForEntity = (items, companyId, keepItemId = null) => {
    if (!companyId) return items || [];

    return (items || []).filter((item) =>
        usableBy(item, companyId) || (keepItemId && String(item.id) === String(keepItemId)),
    );
};

/** Whether a picked item still fits the company (used to clear a stale pick). */
export const itemFitsEntity = (items, itemId, companyId) => {
    if (!itemId || !companyId) return true;

    const item = (items || []).find((candidate) => String(candidate.id) === String(itemId));

    return !item || usableBy(item, companyId);
};
