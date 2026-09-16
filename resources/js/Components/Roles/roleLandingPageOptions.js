import { computed } from 'vue'
import { MODULE_REGISTRY } from '@/Composables/useModuleRegistry.js'
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js'

/**
 * Landing-page choices for a role, derived from the module registry so the
 * dropdown always mirrors the sidebar — including sections added later and any
 * labels/order the user customised in the layout settings.
 *
 * Direct sections (Dashboard, Project Tracker) have no children of their own,
 * so they are listed together under "General". Every other section becomes an
 * optgroup holding its modules.
 *
 * A landing page is stored as a bare route NAME and resolved server-side with
 * `route($name)` (AuthenticatedSessionController), so anything needing route
 * parameters — hub pages, dynamic forms — cannot be a landing page and is
 * skipped here.
 */
const GENERAL_GROUP = 'General'

export function buildRoleLandingPageOptions() {
    const { getSectionOrder, getChildOrder, getSectionLabel, getChildLabel } = useSidebarOrder()

    const sections = MODULE_REGISTRY
        .slice()
        .sort((a, b) => getSectionOrder(a.id) - getSectionOrder(b.id))

    const general = { group: GENERAL_GROUP, options: [] }
    const groups = []

    for (const section of sections) {
        if (section.direct) {
            if (!section.routeName || section.routeParams) continue
            general.options.push({
                label: getSectionLabel(section.id),
                value: section.routeName,
            })
            continue
        }

        const options = (section.children || [])
            .filter(child => child.routeName && !child.routeParams)
            .slice()
            .sort((a, b) => getChildOrder(section.id, a.id) - getChildOrder(section.id, b.id))
            .map(child => ({
                label: getChildLabel(section.id, child.id),
                value: child.routeName,
            }))

        if (options.length === 0) continue

        groups.push({ group: getSectionLabel(section.id), options })
    }

    return general.options.length > 0 ? [general, ...groups] : groups
}

/** Reactive options — follows the user's saved sidebar order and labels. */
export function useRoleLandingPageOptions() {
    return computed(() => buildRoleLandingPageOptions())
}
