<template>
    <AppLayout :title="cycle.title" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link :href="route('qat.index')" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                                ← All cycles
                            </Link>
                            <span class="font-mono text-xs text-gray-400">{{ cycle.code }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="STATUS_CHIPS[cycle.status]">
                                {{ statusLabel }}
                            </span>
                        </div>
                        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ cycle.title }}</h1>
                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-300">
                            <span v-if="cycle.system_name">{{ cycle.system_name }}</span>
                            <span>Round {{ cycle.cycle_no }}</span>
                            <span>{{ cycle.environment }}</span>
                            <span v-if="cycle.qa_lead">QA: {{ cycle.qa_lead.name }}</span>
                            <span v-if="cycle.dev_lead">Dev: {{ cycle.dev_lead.name }}</span>
                        </div>

                        <!-- Where this cycle sits relative to UAT -->
                        <div v-if="cycle.promoted_uat_cycle || cycle.uat_cycle" class="mt-2 flex flex-wrap gap-2">
                            <Link v-if="cycle.promoted_uat_cycle" :href="route('uat.show', cycle.promoted_uat_cycle.id)"
                                  class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-100 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                Promoted to {{ cycle.promoted_uat_cycle.code }}
                            </Link>
                            <Link v-else-if="cycle.uat_cycle" :href="route('uat.show', cycle.uat_cycle.id)"
                                  class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 transition-colors hover:bg-indigo-100 dark:border-indigo-400/30 dark:bg-indigo-500/10 dark:text-indigo-200">
                                Linked to {{ cycle.uat_cycle.code }}
                            </Link>
                        </div>

                        <div v-if="(cycle.links || []).length" class="mt-2 flex flex-wrap gap-2">
                            <a v-for="(link, i) in cycle.links" :key="i" :href="link.url" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:border-blue-400/30 dark:bg-blue-500/10 dark:text-blue-200">
                                {{ link.label || link.url }}
                            </a>
                        </div>
                    </div>

                    <div class="flex items-center gap-5">
                        <div class="relative h-20 w-20 shrink-0">
                            <svg class="h-20 w-20 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="currentColor" stroke-width="3" class="text-gray-200 dark:text-gray-700" />
                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                        class="text-blue-600 transition-all duration-500 dark:text-blue-400"
                                        :stroke-dasharray="`${executionPct} 100`" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ executionPct }}%</span>
                                <span class="text-[9px] font-semibold uppercase tracking-wide text-gray-400">executed</span>
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-5 gap-y-1 text-sm">
                            <dt class="text-emerald-600 dark:text-emerald-400">Passed</dt>
                            <dd class="text-right font-bold text-gray-900 dark:text-gray-100">{{ statistics.passed }}</dd>
                            <dt class="text-rose-600 dark:text-rose-400">Failed</dt>
                            <dd class="text-right font-bold text-gray-900 dark:text-gray-100">{{ statistics.failed }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">Pending</dt>
                            <dd class="text-right font-bold text-gray-900 dark:text-gray-100">{{ statistics.pending }}</dd>
                            <dt class="border-t border-gray-200 pt-1 text-gray-500 dark:border-gray-700 dark:text-gray-400">Total</dt>
                            <dd class="border-t border-gray-200 pt-1 text-right font-bold text-gray-900 dark:border-gray-700 dark:text-gray-100">
                                {{ statistics.total_cases }}
                            </dd>
                        </dl>
                    </div>

                    <div class="flex items-center gap-2">
                        <button v-if="can('qat.edit')" @click="editModal = true"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-medium text-blue-700 shadow-sm transition-colors hover:bg-blue-50 dark:border-blue-400/30 dark:bg-slate-900 dark:text-blue-200 dark:hover:bg-blue-500/15">
                            Edit
                        </button>
                        <a v-if="can('qat.export')" :href="route('qat.export', cycle.id)"
                           class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-medium text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15">
                            Export
                        </a>
                        <button v-if="can('qat.import')" @click="importModal = true"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-200 dark:hover:bg-gray-700">
                            Import
                        </button>
                    </div>
                </div>

                <!-- Readiness banner -->
                <div class="mt-4 rounded-lg border p-3 text-sm" :class="readinessClass">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-bold">{{ readinessHeadline }}</span>

                        <template v-if="setupGaps.length">
                            <span>It still needs {{ setupGaps.join(' and ') }}.</span>
                            <button v-if="can('qat.edit')" @click="activeTab = 'setup'"
                                    class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-blue-700">
                                Go to Setup
                            </button>
                            <button v-if="can('qat.import')" @click="importModal = true"
                                    class="rounded-lg border border-blue-300 px-3 py-1 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-50 dark:border-blue-400/40 dark:text-blue-200 dark:hover:bg-blue-500/15">
                                Import a workbook
                            </button>
                        </template>

                        <template v-else>
                            <span v-if="(readiness.unanswered_cases || []).length">
                                {{ readiness.unanswered_cases.length }} {{ readiness.gate_on_critical_only ? 'critical ' : '' }}case(s) with no verdict yet
                            </span>
                            <span v-if="(readiness.failing_cases || []).length">
                                {{ readiness.failing_cases.length }} case(s) failing
                            </span>
                            <span v-if="(readiness.blocking_findings || []).length">
                                {{ readiness.blocking_findings.length }} blocker/major finding(s) blocking sign-off
                            </span>
                            <button v-if="readiness.can_submit && can('qat.submit')" @click="activeTab = 'signoff'"
                                    class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-blue-700">
                                Submit for sign-off
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mt-5 border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex flex-wrap gap-1" aria-label="QAT sections">
                    <button v-for="tab in visibleTabs" :key="tab.id" @click="activeTab = tab.id"
                            class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors"
                            :class="activeTab === tab.id
                                ? 'border-blue-600 text-blue-700 dark:border-blue-400 dark:text-blue-300'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'">
                        {{ tab.label }}
                        <span v-if="tab.count !== undefined && tab.count > 0"
                              class="rounded-full px-1.5 py-0.5 text-[10px] font-bold"
                              :class="activeTab === tab.id ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                            {{ tab.count }}
                        </span>
                    </button>
                </nav>
            </div>

            <div class="mt-5">
                <OverviewTab v-if="activeTab === 'overview'" v-bind="tabProps" @go="activeTab = $event" />
                <MatrixTab v-else-if="activeTab === 'matrix'" v-bind="tabProps" @open-case="openCase" @go="activeTab = $event" />
                <ExecuteTab v-else-if="activeTab === 'execute'" v-bind="tabProps" />
                <FindingsTab v-else-if="activeTab === 'findings'" v-bind="tabProps" />
                <SignoffTab v-else-if="activeTab === 'signoff'" v-bind="tabProps" />
                <SetupTab v-else-if="activeTab === 'setup'" v-bind="tabProps" />
            </div>
        </div>

        <CycleFormModal :show="editModal" :cycle="cycle" :options="options" @close="editModal = false" />

        <Modal :show="importModal" @close="importModal = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Import Workbook</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Accepts a test script with a <span class="font-semibold">Test Case ID</span> or
                    <span class="font-semibold">Title</span> column. This is the same layout the UAT Tracker uses,
                    so a script exported from there imports here. Existing case IDs are skipped, never overwritten.
                </p>

                <form @submit.prevent="submitImport" class="mt-5 space-y-4">
                    <input ref="importInput" type="file" accept=".xlsx,.xls" required
                           class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-gray-300">
                    <InputError :message="importError" />
                    <a :href="route('qat.template')" class="inline-block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                        Download a blank template
                    </a>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="importModal = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="importing"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ importing ? 'Importing...' : 'Import' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted, provide } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import { usePermission } from '@/Composables/usePermission'
import { STATUS_CHIPS } from './qatVerdict'
import OverviewTab from './Partials/OverviewTab.vue'
import MatrixTab from './Partials/MatrixTab.vue'
import ExecuteTab from './Partials/ExecuteTab.vue'
import FindingsTab from './Partials/FindingsTab.vue'
import SignoffTab from './Partials/SignoffTab.vue'
import SetupTab from './Partials/SetupTab.vue'
import CycleFormModal from './Partials/CycleFormModal.vue'

const props = defineProps({
    cycle: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    cases: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    // One entry per department column (reviewer + tester collapsed together).
    columns: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    findings: { type: Array, default: () => [] },
    signoffs: { type: Array, default: () => [] },
    statistics: { type: Object, default: () => ({}) },
    participantProgress: { type: Array, default: () => [] },
    sectionProgress: { type: Object, default: () => ({}) },
    readiness: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
})

const route = window.route
const { hasPermission } = usePermission()
const can = (permission) => hasPermission(permission)

const TAB_IDS = ['overview', 'matrix', 'execute', 'findings', 'signoff', 'setup']

/**
 * The active tab lives in the URL, not in component state.
 *
 * Inertia tears the page component down on any non-GET visit (preserveState
 * defaults to false), so a plain ref reset to the first tab every time a record
 * was saved. Keeping it in the query string survives that, survives a refresh,
 * and makes a tab linkable. `redirect()->back()` returns to the referring URL,
 * so the parameter comes back with it.
 */
const tabFromUrl = () => {
    const requested = new URLSearchParams(window.location.search).get('tab')
    return TAB_IDS.includes(requested) ? requested : 'overview'
}

const activeTab = ref(tabFromUrl())

// replaceState rather than an Inertia visit: switching tabs is a client-side
// concern and must not cost a server round trip.
watch(activeTab, (tab) => {
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    window.history.replaceState(window.history.state, '', url.toString())
})

onMounted(() => {
    const fromUrl = tabFromUrl()
    if (fromUrl !== activeTab.value) activeTab.value = fromUrl
})

const editModal = ref(false)
const importModal = ref(false)
const importing = ref(false)
const importError = ref('')
const importInput = ref(null)

// Every tab reads the same payload; passing it once keeps the children pure.
const tabProps = computed(() => ({
    cycle: props.cycle,
    sections: props.sections,
    cases: props.cases,
    participants: props.participants,
    columns: props.columns,
    results: props.results,
    findings: props.findings,
    signoffs: props.signoffs,
    statistics: props.statistics,
    participantProgress: props.participantProgress,
    sectionProgress: props.sectionProgress,
    readiness: props.readiness,
    options: props.options,
}))

provide('qatCan', can)

const openFindingsCount = computed(() =>
    props.findings.filter(f => ['open', 'in_progress', 'for_retest'].includes(f.status)).length
)

const signoffBadge = computed(() => {
    if (props.readiness?.awaiting_approval) return 1
    return (props.readiness?.blocking_findings || []).length
})

// The Sign-off tab is visible to everyone, including a tester who cannot act on
// it: hiding it would make the gate itself invisible and generate support calls.
const visibleTabs = computed(() => [
    { id: 'overview', label: 'Overview' },
    { id: 'matrix', label: 'Verdict Matrix' },
    { id: 'execute', label: 'Run Tests' },
    { id: 'findings', label: 'Findings', count: openFindingsCount.value },
    { id: 'signoff', label: 'Sign-off', count: signoffBadge.value },
    ...(can('qat.edit') ? [{ id: 'setup', label: 'Setup' }] : []),
])

const executionPct = computed(() => Math.round((props.statistics?.execution_rate || 0) * 100))

const statusLabel = computed(() =>
    (props.options?.statuses || []).find(s => s.value === props.cycle.status)?.label || props.cycle.status
)

/** What is missing before this cycle can even be tested. */
const setupGaps = computed(() => {
    const gaps = []
    if (!props.readiness?.has_cases) gaps.push('test cases')
    if (!props.readiness?.has_participants) gaps.push('testers')
    return gaps
})

const readinessHeadline = computed(() => {
    if (props.readiness?.signed_off) return 'Signed off by the manager.'
    if (props.readiness?.awaiting_approval) return 'Waiting on the manager’s sign-off.'
    if (props.cycle.status === 'returned') return 'Returned by the manager.'
    // Checked before readiness: an empty cycle has no outstanding cases, which
    // would otherwise read as "testing complete".
    if (setupGaps.value.length) return 'This cycle is not set up yet.'
    if (props.readiness?.can_submit) return 'Testing complete — ready to submit.'
    return 'Testing in progress.'
})

const readinessClass = computed(() => {
    if (props.readiness?.signed_off) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
    }
    if (props.readiness?.awaiting_approval) {
        return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
    }
    if (props.cycle.status === 'returned') {
        return 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200'
    }
    return 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300'
})

const openCase = (caseId) => {
    activeTab.value = 'execute'
    // ExecuteTab reads this on mount to jump straight to the chosen case.
    window.sessionStorage.setItem('qat.jumpToCase', String(caseId))
}

const submitImport = () => {
    const file = importInput.value?.files?.[0]
    if (!file) return

    importing.value = true
    importError.value = ''

    router.post(route('qat.import', props.cycle.id), { file }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { importModal.value = false },
        onError: (errors) => { importError.value = errors.file || 'Import failed.' },
        onFinish: () => { importing.value = false },
    })
}
</script>
