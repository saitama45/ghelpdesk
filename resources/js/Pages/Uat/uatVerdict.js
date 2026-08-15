/**
 * One place for how a verdict looks, so the matrix cell, the runner button, the
 * report chip and the portal all agree. Green/red/amber carry meaning here, so
 * every state also has a distinct glyph — colour is never the only signal.
 */
export const VERDICTS = {
    passed: {
        label: 'Passed',
        short: 'Pass',
        glyph: '✓',
        chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        cell: 'bg-emerald-500 text-white hover:bg-emerald-600',
        solid: 'bg-emerald-600 hover:bg-emerald-700 text-white',
        dot: 'bg-emerald-500',
        text: 'text-emerald-600 dark:text-emerald-400',
    },
    failed: {
        label: 'Failed',
        short: 'Fail',
        glyph: '✕',
        chip: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        cell: 'bg-rose-500 text-white hover:bg-rose-600',
        solid: 'bg-rose-600 hover:bg-rose-700 text-white',
        dot: 'bg-rose-500',
        text: 'text-rose-600 dark:text-rose-400',
    },
    blocked: {
        label: 'Blocked',
        short: 'Blocked',
        glyph: '⊘',
        chip: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        cell: 'bg-amber-500 text-white hover:bg-amber-600',
        solid: 'bg-amber-600 hover:bg-amber-700 text-white',
        dot: 'bg-amber-500',
        text: 'text-amber-600 dark:text-amber-400',
    },
    ongoing: {
        label: 'Ongoing',
        short: 'Ongoing',
        glyph: '◐',
        chip: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
        cell: 'bg-blue-500 text-white hover:bg-blue-600',
        solid: 'bg-blue-600 hover:bg-blue-700 text-white',
        dot: 'bg-blue-500',
        text: 'text-blue-600 dark:text-blue-400',
    },
    not_applicable: {
        label: 'Not applicable',
        short: 'N/A',
        glyph: '–',
        chip: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
        cell: 'bg-slate-300 text-slate-700 hover:bg-slate-400 dark:bg-slate-600 dark:text-slate-100',
        solid: 'bg-slate-500 hover:bg-slate-600 text-white',
        dot: 'bg-slate-400',
        text: 'text-slate-500 dark:text-slate-300',
    },
    pending: {
        label: 'Pending',
        short: 'Pending',
        glyph: '',
        chip: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        cell: 'bg-gray-100 text-gray-400 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-600',
        solid: 'bg-gray-500 hover:bg-gray-600 text-white',
        dot: 'bg-gray-300',
        text: 'text-gray-500 dark:text-gray-400',
    },
}

export const verdict = (key) => VERDICTS[key] || VERDICTS.pending

/** Order the quick-verdict buttons appear in, most-used first. */
export const VERDICT_ORDER = ['passed', 'failed', 'blocked', 'ongoing', 'not_applicable', 'pending']

export const SEVERITY_CHIPS = {
    blocker: 'bg-rose-600 text-white',
    major: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    minor: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    cosmetic: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
}

export const FINDING_STATUS_CHIPS = {
    open: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    for_retest: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    closed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    deferred: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
}

export const SIGNOFF_CHIPS = {
    passed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    passed_with_reservation: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    not_accepted: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
}

/** Renders the stored step text as a list, preserving the authored numbering. */
export const asLines = (text) =>
    String(text || '')
        .split(/\r?\n/)
        .map(line => line.replace(/\s+$/, ''))
        .filter(line => line.trim() !== '')

export const formatDateTime = (value) => {
    if (!value) return '—'
    const d = new Date(value)
    return Number.isNaN(d.getTime())
        ? '—'
        : d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export const formatDate = (value) => {
    if (!value) return '—'
    const [year, month, day] = String(value).split('T')[0].split('-').map(Number)
    if (!year) return '—'
    return new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

export const pct = (value) => `${Math.round((Number(value) || 0) * 100)}%`
