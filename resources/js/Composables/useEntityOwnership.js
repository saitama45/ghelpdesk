import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Entity switching on reference pages (/items, /stores, /clusters, /vendors,
 * /categories, /sub-categories).
 *
 * A brand also lists the rows of the entities it is tagged to on /companies.
 * Those rows are managed from their own entity, so the page shows them
 * read-only; the server enforces it (App\Support\EntityReferenceScope).
 * Rows with no company are shared and stay editable everywhere.
 */
export function useEntityOwnership() {
    const page = usePage();
    const activeCompanyId = computed(() => page.props.activeCompany?.id ?? null);

    const isInherited = (row) =>
        !!activeCompanyId.value
        && row?.company_id != null
        && Number(row.company_id) !== Number(activeCompanyId.value);

    return { activeCompanyId, isInherited };
}
