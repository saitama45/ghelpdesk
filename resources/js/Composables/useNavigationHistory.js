import { router } from '@inertiajs/vue3';

// Inertia navigates with pushState, so `document.referrer` never changes after
// the first full page load — a detail page cannot tell where it was opened from.
// We record it ourselves: before every visit that actually leaves the current
// page, stash the URL we are leaving. sessionStorage (not a module variable) so
// the target survives a refresh of the detail page itself.
const STORAGE_KEY = 'inertia:previous-url';

const isPartialReload = (visit) => Array.isArray(visit?.only) && visit.only.length > 0;

/**
 * Installed once from app.js.
 */
export function trackNavigationHistory() {
    router.on('before', (event) => {
        const visit = event.detail?.visit;

        if (!visit || String(visit.method).toLowerCase() !== 'get' || isPartialReload(visit)) return;

        const target = visit.url instanceof URL ? visit.url : new URL(String(visit.url), window.location.origin);

        // Re-visiting the same page (filter changes, tab state, saves that redirect
        // back) is not "coming from somewhere else" — keep the older target.
        if (target.pathname === window.location.pathname) return;

        try {
            sessionStorage.setItem(STORAGE_KEY, window.location.pathname + window.location.search);
        } catch {
            // Private mode / storage disabled — callers just fall back.
        }
    });
}

export function useNavigationHistory() {
    const previousUrl = () => {
        try {
            const stored = sessionStorage.getItem(STORAGE_KEY);

            // Only internal, root-relative paths — never bounce off-site.
            return stored && stored.startsWith('/') && !stored.startsWith('//') ? stored : null;
        } catch {
            return null;
        }
    };

    /**
     * Where a "Back" control should point.
     *
     * @param {string} fallback  Used when there is no usable previous page.
     * @param {(url: string) => boolean} [accept]  Optional guard, e.g. only accept
     *        URLs under /projects so Back never returns to an unrelated screen.
     */
    const backHref = (fallback, accept = null) => {
        const previous = previousUrl();

        if (!previous) return fallback;
        if (accept && !accept(previous)) return fallback;

        return previous;
    };

    return { previousUrl, backHref };
}
