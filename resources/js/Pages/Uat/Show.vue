<template>
    <AppLayout :title="cycle.title" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6">
            <!-- Cycle header: the workbook's header block, live -->
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link :href="route('uat.index')" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                                ← All cycles
                            </Link>
                            <span class="font-mono text-xs text-gray-400">{{ cycle.code }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="statusClass">
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
                        <div v-if="(cycle.links || []).length" class="mt-2 flex flex-wrap gap-2">
                            <a v-for="(link, i) in cycle.links" :key="i" :href="link.url" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:border-blue-400/30 dark:bg-blue-500/10 dark:text-blue-200">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                {{ link.label || link.url }}
                            </a>
                        </div>
                    </div>

                    <!-- Progress ring + tallies -->
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
                        <button v-if="can('uat.edit')" @click="editModal = true"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm font-medium text-blue-700 shadow-sm transition-colors hover:bg-blue-50 dark:border-blue-400/30 dark:bg-slate-900 dark:text-blue-200 dark:hover:bg-blue-500/15">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Edit
                        </button>
                        <a v-if="can('uat.export')" :href="route('uat.export', cycle.id)"
                           class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-medium text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            Export
                        </a>
                        <button v-if="can('uat.import')" @click="importModal = true"
                                class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-200 dark:hover:bg-gray-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Import
                        </button>
                    </div>
                </div>

                <!-- Readiness banner -->
                <div class="mt-4 rounded-lg border p-3 text-sm" :class="readinessClass">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-bold">{{ readinessHeadline }}</span>

                        <!-- Empty cycle: say what is missing and offer the way in -->
                        <template v-if="setupGaps.length">
                            <span>It still needs {{ setupGaps.join(' and ') }}.</span>
                            <button v-if="can('uat.edit')" @click="activeTab = 'setup'"
                                    class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-blue-700">
                                Go to Setup
                            </button>
                            <button v-if="can('uat.import')" @click="importModal = true"
                                    class="rounded-lg border border-blue-300 px-3 py-1 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-50 dark:border-blue-400/40 dark:text-blue-200 dark:hover:bg-blue-500/15">
                                Import a workbook
                            </button>
                        </template>

                        <template v-else>
                            <span v-if="readiness.outstanding_cases.length">
                                {{ readiness.outstanding_cases.length }} {{ readiness.gate_on_critical_only ? 'critical ' : '' }}case(s) outstanding
                            </span>
                            <span v-if="readiness.blocking_findings.length">
                                {{ readiness.blocking_findings.length }} blocker/major finding(s) open
                            </span>
                            <span v-if="readiness.pending_approvers.length">
                                {{ readiness.pending_approvers.length }} approver(s) yet to accept
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mt-5 border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex flex-wrap gap-1" aria-label="UAT sections">
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

        <!-- Edit the cycle header -->
        <CycleFormModal
            :show="editModal"
            :cycle="cycle"
            :options="options"
            @close="editModal = false"
        />

        <!-- Import workbook -->
        <Modal :show="importModal" @close="importModal = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Import Workbook</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Accepts either layout — a test script with a <span class="font-semibold">Test Case ID</span> column, or a
                    walkthrough checklist with a <span class="font-semibold">Title</span> column followed by one column per department.
                    The layout is detected automatically. Existing case IDs are skipped, never overwritten.
                </p>

                <form @submit.prevent="submitImport" class="mt-5 space-y-4">
                    <input ref="importInput" type="file" accept=".xlsx,.xls" required
                           class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-gray-300">
                    <InputError :message="importError" />
                    <a :href="route('uat.template')" class="inline-block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
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
import { ref, computed, provide } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import { usePermission } from '@/Composables/usePermission'
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
    results: { type: Array, default: () => [] },
    findings: { type: Array, default: () => [] },
    signoffs: { type: Array, default: () => [] },
    statistics: { type: Object, default: () => ({}) },
    participantProgress: { type: Array, default: () => [] },
    sectionProgress: { type: Object, default: () => ({}) },
    readiness: { type: Object, default: () => ({}) },
    acceptance: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
})

const route = window.route
const { hasPermission } = usePermission()
const can = (permission) => hasPermission(permission)

const activeTab = ref('overview')
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
    results: props.results,
    findings: props.findings,
    signoffs: props.signoffs,
    statistics: props.statistics,
    participantProgress: props.participantProgress,
    sectionProgress: props.sectionProgress,
    readiness: props.readiness,
    acceptance: props.acceptance,
    options: props.options,
}))

provide('uatCan', can)

const openFindingsCount = computed(() =>
    props.findings.filter(f => ['open', 'in_progress', 'for_retest'].includes(f.status)).length
)

const pendingApproversCount = computed(() => (props.readiness?.pending_approvers || []).length)

const visibleTabs = computed(() => [
    { id: 'overview', label: 'Overview' },
    { id: 'matrix', label: 'Verdict Matrix' },
    { id: 'execute', label: 'Run Tests' },
    { id: 'findings', label: 'Findings', count: openFindingsCount.value },
    { id: 'signoff', label: 'Sign-off', count: pendingApproversCount.value },
    ...(can('uat.edit') ? [{ id: 'setup', label: 'Setup' }] : []),
])

const executionPct = computed(() => Math.round((props.statistics?.execution_rate || 0) * 100))

const statusLabel = computed(() =>
    (props.options?.statuses || []).find(s => s.value === props.cycle.status)?.label || props.cycle.status
)

const statusClass = computed(() => ({
    draft: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    on_hold: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    completed: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
    signed_off: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200',
    cancelled: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
}[props.cycle.status] || 'bg-gray-100 text-gray-600'))

/** What is missing before this cycle can even be tested. */
const setupGaps = computed(() => {
    const gaps = []
    if (!props.readiness?.has_cases) gaps.push('test cases')
    if (!props.readiness?.has_participants) gaps.push('participants')
    return gaps
})

const readinessHeadline = computed(() => {
    if (props.cycle.status === 'signed_off') return 'Signed off.'
    // Checked before readiness: an empty cycle has no outstanding cases, which
    // would otherwise read as "testing complete".
    if (setupGaps.value.length) return 'This cycle is not set up yet.'
    if (props.readiness?.is_ready) return 'Ready for final sign-off.'
    if (props.readiness?.testing_ready) return 'Testing complete — waiting on approvers.'
    return 'Not ready for sign-off yet.'
})

const readinessClass = computed(() => {
    if (props.cycle.status === 'signed_off' || props.readiness?.is_ready) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
    }
    if (props.readiness?.testing_ready) {
        return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
    }
    return 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300'
})

const openCase = (caseId) => {
    activeTab.value = 'execute'
    // ExecuteTab reads this on mount to jump straight to the chosen case.
    window.sessionStorage.setItem('uat.jumpToCase', String(caseId))
}

const submitImport = () => {
    const file = importInput.value?.files?.[0]
    if (!file) return

    importing.value = true
    importError.value = ''

    router.post(`/uat/${props.cycle.id}/import`, { file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { importModal.value = false },
        onError: (errors) => { importError.value = errors.file || 'Import failed.' },
        onFinish: () => { importing.value = false },
    })
}
</script>
