<template>
    <div class="space-y-5">
        <!-- Verdict tallies -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <button v-for="tile in tiles" :key="tile.key" @click="$emit('go', 'matrix')"
                    class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="tile.dot"></span>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ tile.label }}</span>
                </div>
                <div class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ tile.value }}</div>
                <div class="text-[11px] text-gray-400">{{ tile.hint }}</div>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- Participant progress: the walkthrough checklist's columns, as a scoreboard -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Progress by Participant</h3>
                    <button @click="$emit('go', 'matrix')" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                        Open matrix →
                    </button>
                </div>

                <p v-if="!participantProgress.length" class="mt-4 text-sm text-gray-400">
                    No participants yet. Add the departments and stakeholders who will test in the Setup tab.
                </p>

                <!-- One row per DEPARTMENT. The people behind it appear when the
                     row is expanded, which is where tester vs approver matters. -->
                <div v-else class="mt-4 space-y-3">
                    <div v-for="row in participantProgress" :key="row.key">
                        <button type="button" @click="toggle(row.key)"
                                class="mb-1 flex w-full items-center justify-between gap-3 text-left text-sm">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <svg class="h-3 w-3 shrink-0 text-gray-400 transition-transform"
                                     :class="expanded[row.key] ? 'rotate-90' : ''"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                                <span class="truncate font-medium text-gray-800 dark:text-gray-100">{{ row.label }}</span>
                                <span class="shrink-0 text-xs text-gray-400">
                                    ({{ row.members.length }} {{ row.members.length === 1 ? 'person' : 'people' }})
                                </span>
                            </span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ row.answered }} / {{ row.total }}</span>
                        </button>

                        <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="bg-emerald-500" :style="{ width: share(row, 'passed') }" :title="`${row.passed} passed`"></div>
                            <div class="bg-rose-500" :style="{ width: share(row, 'failed') }" :title="`${row.failed} failed`"></div>
                            <div class="bg-amber-500" :style="{ width: share(row, 'blocked') }" :title="`${row.blocked} blocked`"></div>
                            <div class="bg-blue-500" :style="{ width: share(row, 'ongoing') }" :title="`${row.ongoing} ongoing`"></div>
                            <div class="bg-slate-400" :style="{ width: share(row, 'not_applicable') }" :title="`${row.not_applicable} not applicable`"></div>
                        </div>

                        <div v-if="expanded[row.key]" class="mt-2 space-y-1.5 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                            <div v-for="member in row.members" :key="member.id"
                                 class="flex items-center justify-between gap-3 text-xs">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                          :class="member.role === 'approver'
                                              ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200'
                                              : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'">
                                        {{ member.role === 'approver' ? 'Approver' : 'Tester' }}
                                    </span>
                                    <span class="truncate text-gray-700 dark:text-gray-200">{{ member.name }}</span>
                                </span>
                                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ member.answered }} / {{ member.total }}</span>
                            </div>
                            <p class="pt-1 text-[11px] text-gray-400">
                                The department's verdict is the approver's answer; the tester's stands until they give one.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Critical readiness -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Go-Live Readiness</h3>

                <div class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600 dark:text-gray-300">Critical cases cleared</span>
                        <span class="font-bold text-gray-900 dark:text-gray-100">
                            {{ statistics.critical_passed }} / {{ statistics.critical_total }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600 dark:text-gray-300">Blocker / major findings</span>
                        <span class="font-bold" :class="readiness.blocking_findings?.length ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
                            {{ readiness.blocking_findings?.length || 0 }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600 dark:text-gray-300">Approvers outstanding</span>
                        <span class="font-bold" :class="readiness.pending_approvers?.length ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                            {{ readiness.pending_approvers?.length || 0 }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-200 pt-3 dark:border-gray-700">
                        <span class="text-gray-600 dark:text-gray-300">Matrix filled</span>
                        <span class="font-bold text-gray-900 dark:text-gray-100">
                            {{ statistics.cells_filled }} / {{ statistics.cells_total }}
                        </span>
                    </div>
                </div>

                <p class="mt-4 text-xs text-gray-400">
                    {{ readiness.gate_on_critical_only
                        ? 'Non-critical cases are tracked but never block go-live.'
                        : 'Every case must clear before sign-off.' }}
                </p>

                <button @click="$emit('go', 'signoff')"
                        class="mt-4 w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    Go to Sign-off
                </button>
            </div>
        </div>

        <!-- Section/Module breakdown -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Progress by Section/Module</h3>
            <p class="mt-0.5 text-xs text-gray-400">
                How each module of the system under test is tracking.
            </p>

            <p v-if="!sectionRows.length" class="mt-4 text-sm text-gray-400">
                No test cases yet. Import a workbook or add cases in the Setup tab.
            </p>

            <div v-else class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="row in sectionRows" :key="row.id ?? 'none'"
                     class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ row.name }}</span>
                        <span v-if="!row.is_critical" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                            Non-critical
                        </span>
                    </div>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-emerald-500 transition-all" :style="{ width: pct(row.rate) }"></div>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ row.passed }} / {{ row.total }} cleared</span>
                        <span class="font-semibold">{{ pct(row.rate) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- What is standing in the way -->
        <div v-if="blockers.length" class="rounded-xl border border-rose-200 bg-rose-50/60 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
            <h3 class="text-sm font-bold uppercase tracking-wider text-rose-700 dark:text-rose-300">Standing In The Way</h3>
            <ul class="mt-3 space-y-1.5 text-sm text-rose-900 dark:text-rose-100">
                <li v-for="(item, index) in blockers" :key="index" class="flex items-start gap-2">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500"></span>
                    <span>{{ item }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { pct } from '../uatVerdict.js'

const props = defineProps({
    cycle: Object,
    sections: Array,
    cases: Array,
    participants: Array,
    columns: Array,
    results: Array,
    findings: Array,
    signoffs: Array,
    statistics: Object,
    participantProgress: Array,
    sectionProgress: Object,
    readiness: Object,
    acceptance: Object,
    options: Object,
})

defineEmits(['go'])

const tiles = computed(() => {
    const s = props.statistics || {}
    return [
        { key: 'passed', label: 'Passed', value: s.passed || 0, dot: 'bg-emerald-500', hint: 'cleared by every column' },
        { key: 'failed', label: 'Failed', value: s.failed || 0, dot: 'bg-rose-500', hint: 'at least one failure' },
        { key: 'blocked', label: 'Blocked', value: s.blocked || 0, dot: 'bg-amber-500', hint: 'could not be tested' },
        { key: 'ongoing', label: 'Ongoing', value: s.ongoing || 0, dot: 'bg-blue-500', hint: 'partially done' },
        { key: 'pending', label: 'Pending', value: s.pending || 0, dot: 'bg-gray-300', hint: 'not started' },
        { key: 'na', label: 'N/A', value: s.not_applicable || 0, dot: 'bg-slate-400', hint: 'out of scope' },
    ]
})

const share = (row, key) => {
    if (!row.total) return '0%'
    return `${Math.round(((row[key] || 0) / row.total) * 100)}%`
}

// A department row expands to show the tester and approver behind it.
const expanded = reactive({})
const toggle = (key) => { expanded[key] = !expanded[key] }

const sectionRows = computed(() => {
    const progress = props.sectionProgress || {}
    const rows = (props.sections || []).map(section => ({
        id: section.id,
        name: section.name,
        is_critical: section.is_critical,
        ...(progress[section.id] || { total: 0, passed: 0, outstanding: 0, rate: 0 }),
    })).filter(row => row.total > 0)

    // Cases whose section was removed still need somewhere to be counted.
    const ungrouped = progress['']
        || progress[null]
        || Object.entries(progress).find(([key]) => key === 'null')?.[1]

    if (ungrouped && ungrouped.total > 0) {
        rows.push({ id: null, name: 'No module', is_critical: true, ...ungrouped })
    }

    return rows
})

const blockers = computed(() => {
    const out = []

    for (const item of (props.readiness?.outstanding_cases || []).slice(0, 8)) {
        out.push(`${item.case_key} — ${item.title} (${item.verdict.replace('_', ' ')})`)
    }
    const extraCases = (props.readiness?.outstanding_cases || []).length - 8
    if (extraCases > 0) out.push(`…and ${extraCases} more outstanding case(s).`)

    for (const item of (props.readiness?.blocking_findings || []).slice(0, 5)) {
        out.push(`${item.reference} — ${item.title} (${item.severity})`)
    }

    for (const item of (props.readiness?.pending_approvers || []).slice(0, 5)) {
        out.push(`Awaiting acceptance from ${item.label}${item.name ? ` (${item.name})` : ''}.`)
    }

    return out
})
</script>
