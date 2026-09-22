import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Ticket status catalogue (References → Ticket Statuses), shared by
// HandleInertiaRequests as `ticketStatuses`. Class strings are written out in
// full so Tailwind keeps them; the palette names match TicketStatuses::COLORS.
const PALETTE = {
    blue: { soft: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-200', outline: 'border-blue-500 text-black bg-white dark:bg-slate-900 dark:text-blue-100', pill: 'border-blue-500 text-blue-800 bg-blue-50 dark:bg-blue-500/15 dark:text-blue-200', swatch: 'bg-blue-500' },
    sky: { soft: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200', outline: 'border-sky-500 text-black bg-white dark:bg-slate-900 dark:text-sky-100', pill: 'border-sky-500 text-sky-800 bg-sky-50 dark:bg-sky-500/15 dark:text-sky-200', swatch: 'bg-sky-500' },
    cyan: { soft: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/15 dark:text-cyan-200', outline: 'border-cyan-500 text-black bg-white dark:bg-slate-900 dark:text-cyan-100', pill: 'border-cyan-500 text-cyan-800 bg-cyan-50 dark:bg-cyan-500/15 dark:text-cyan-200', swatch: 'bg-cyan-500' },
    teal: { soft: 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-200', outline: 'border-teal-500 text-black bg-white dark:bg-slate-900 dark:text-teal-100', pill: 'border-teal-500 text-teal-800 bg-teal-50 dark:bg-teal-500/15 dark:text-teal-200', swatch: 'bg-teal-500' },
    green: { soft: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-200', outline: 'border-green-500 text-black bg-white dark:bg-slate-900 dark:text-green-100', pill: 'border-green-500 text-green-800 bg-green-50 dark:bg-green-500/15 dark:text-green-200', swatch: 'bg-green-500' },
    lime: { soft: 'bg-lime-100 text-lime-800 dark:bg-lime-500/15 dark:text-lime-200', outline: 'border-lime-500 text-black bg-white dark:bg-slate-900 dark:text-lime-100', pill: 'border-lime-500 text-lime-800 bg-lime-50 dark:bg-lime-500/15 dark:text-lime-200', swatch: 'bg-lime-500' },
    amber: { soft: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200', outline: 'border-amber-500 text-black bg-white dark:bg-slate-900 dark:text-amber-100', pill: 'border-amber-500 text-amber-800 bg-amber-50 dark:bg-amber-500/15 dark:text-amber-200', swatch: 'bg-amber-500' },
    orange: { soft: 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-200', outline: 'border-orange-400 text-black bg-white dark:bg-slate-900 dark:text-orange-100', pill: 'border-orange-500 text-orange-800 bg-orange-50 dark:bg-orange-500/15 dark:text-orange-200', swatch: 'bg-orange-500' },
    red: { soft: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-200', outline: 'border-red-500 text-black bg-white dark:bg-slate-900 dark:text-red-100', pill: 'border-red-500 text-red-800 bg-red-50 dark:bg-red-500/15 dark:text-red-200', swatch: 'bg-red-500' },
    pink: { soft: 'bg-pink-100 text-pink-800 dark:bg-pink-500/15 dark:text-pink-200', outline: 'border-pink-500 text-black bg-white dark:bg-slate-900 dark:text-pink-100', pill: 'border-pink-500 text-pink-800 bg-pink-50 dark:bg-pink-500/15 dark:text-pink-200', swatch: 'bg-pink-500' },
    violet: { soft: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-200', outline: 'border-violet-500 text-black bg-white dark:bg-slate-900 dark:text-violet-100', pill: 'border-violet-500 text-violet-800 bg-violet-50 dark:bg-violet-500/15 dark:text-violet-200', swatch: 'bg-violet-500' },
    indigo: { soft: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-200', outline: 'border-indigo-500 text-black bg-white dark:bg-slate-900 dark:text-indigo-100', pill: 'border-indigo-500 text-indigo-800 bg-indigo-50 dark:bg-indigo-500/15 dark:text-indigo-200', swatch: 'bg-indigo-500' },
    slate: { soft: 'bg-slate-200 text-slate-700 dark:bg-slate-500/20 dark:text-slate-200', outline: 'border-slate-400 text-black bg-white dark:border-slate-500 dark:bg-slate-900 dark:text-slate-100', pill: 'border-slate-400 text-slate-700 bg-slate-50 dark:bg-slate-500/15 dark:text-slate-200', swatch: 'bg-slate-500' },
}

// Used only until the shared prop arrives (e.g. a page rendered before login).
const FALLBACK = [
    { key: 'open', label: 'Open', color: 'blue', is_system: true },
    { key: 'for_schedule', label: 'For Schedule', color: 'teal', is_system: true },
    { key: 'in_progress', label: 'In Progress', color: 'violet', is_system: true },
    { key: 'resolved', label: 'Resolved', color: 'green', is_system: true },
    { key: 'closed', label: 'Closed', color: 'slate', is_system: true },
    { key: 'waiting_service_provider', label: 'Waiting for Service Provider', color: 'orange', is_system: true },
    { key: 'waiting_client_feedback', label: "Waiting for Client's Feedback", color: 'sky', is_system: true },
]

export const STATUS_COLORS = Object.keys(PALETTE)

export const statusClasses = (color, variant = 'soft') => (PALETTE[color] || PALETTE.slate)[variant]

export function useTicketStatuses() {
    const page = usePage()
    const statuses = computed(() => (page.props.ticketStatuses?.length ? page.props.ticketStatuses : FALLBACK))
    const keys = computed(() => statuses.value.map(s => s.key))
    const find = key => statuses.value.find(s => s.key === key)

    const statusLabel = key => find(key)?.label ?? String(key || '').replace(/_/g, ' ')
    const statusColor = (key, variant = 'soft') => statusClasses(find(key)?.color || 'slate', variant)
    // A custom status behaves like the system status it was created against.
    const statusBehavior = key => {
        const status = find(key)
        return status && !status.is_system && status.behaves_like ? status.behaves_like : key
    }

    return { statuses, keys, statusLabel, statusColor, statusBehavior }
}
