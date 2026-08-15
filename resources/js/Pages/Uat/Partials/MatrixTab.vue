<template>
    <div class="space-y-4">
        <!-- Controls -->
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="min-w-[220px] flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Search</label>
                <input v-model="search" type="text" placeholder="Filter by case ID, title or screen..."
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="w-52">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Section/Module</label>
                <Autocomplete v-model="sectionFilter" :options="sectionOptions" placeholder="All sections/modules" />
            </div>
            <div class="w-52">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Verdict</label>
                <Autocomplete v-model="verdictFilter" :options="verdictFilterOptions" placeholder="All verdicts" />
            </div>
            <label class="flex cursor-pointer items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
                <input v-model="criticalOnly" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                Critical only
            </label>
            <div class="ml-auto text-sm text-gray-500 dark:text-gray-400">
                {{ visibleCases.length }} of {{ cases.length }} case(s)
            </div>
        </div>

        <!-- Legend -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="font-semibold uppercase tracking-wider">Legend</span>
            <span v-for="key in VERDICT_ORDER" :key="key" class="inline-flex items-center gap-1.5">
                <span class="inline-flex h-5 w-5 items-center justify-center rounded text-[11px] font-bold" :class="verdict(key).cell">
                    {{ verdict(key).glyph || '·' }}
                </span>
                {{ verdict(key).label }}
            </span>
        </div>

        <!-- Nothing to show yet: say exactly what is missing and how to add it.
             Verdicts are not created directly — a cell exists where a test case
             meets a participant, so both have to exist first. -->
        <div v-if="!columns.length || !cases.length"
             class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
            <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">Nothing to record verdicts against yet</h3>
            <p class="mx-auto mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                The matrix is a grid of <span class="font-semibold">test cases</span> (rows) against
                <span class="font-semibold">participants</span> (columns). A verdict is the cell where the two meet,
                so this cycle needs both before anything is clickable.
            </p>

            <ul class="mx-auto mt-4 max-w-sm space-y-2 text-left text-sm">
                <li class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold text-white"
                          :class="cases.length ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                        {{ cases.length ? '✓' : '1' }}
                    </span>
                    <span :class="cases.length ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-200'">
                        Add test cases ({{ cases.length }} so far)
                    </span>
                </li>
                <li class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold text-white"
                          :class="columns.length ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                        {{ columns.length ? '✓' : '2' }}
                    </span>
                    <span :class="columns.length ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-200'">
                        Add participants — the departments or clients doing the testing ({{ columns.length }} so far)
                    </span>
                </li>
                <li class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-300 text-[11px] font-bold text-white dark:bg-gray-600">3</span>
                    <span class="text-gray-700 dark:text-gray-200">Click any cell here to record its verdict</span>
                </li>
            </ul>

            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <button v-if="can('uat.edit')" @click="$emit('go', 'setup')"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    Open Setup
                </button>
                <span v-else class="text-xs text-gray-400">Ask a cycle owner to set this up — you do not hold uat.edit.</span>
            </div>
        </div>

        <div v-else-if="!visibleCases.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
            <p class="text-sm text-gray-500 dark:text-gray-400">No test cases match these filters.</p>
        </div>

        <!-- The matrix -->
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full border-separate" style="border-spacing: 0">
                <thead>
                    <tr>
                        <th class="sticky left-0 top-0 z-30 min-w-[320px] border-b border-r border-gray-200 bg-gray-50 px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            Test Case
                        </th>
                        <th v-for="column in columns" :key="column.key"
                            class="sticky top-0 z-20 min-w-[104px] border-b border-gray-200 bg-gray-50 px-2 py-3 text-center dark:border-gray-700 dark:bg-gray-900">
                            <div class="text-xs font-bold text-gray-700 dark:text-gray-200">{{ column.label }}</div>
                            <!-- One column per department. Who sits behind it is
                                 shown on the cell drill-down, not here. -->
                            <div class="mt-0.5 text-[10px] font-medium text-gray-400" :title="memberSummary(column)">
                                {{ column.members.length }} {{ column.members.length === 1 ? 'person' : 'people' }}
                            </div>
                            <button v-if="canExecute && cycleOpen" @click="openBulk('participant', column)"
                                    class="mt-1 text-[10px] font-semibold text-blue-600 hover:underline dark:text-blue-300">
                                fill column
                            </button>
                        </th>
                        <th class="sticky top-0 z-20 min-w-[110px] border-b border-l border-gray-200 bg-gray-50 px-3 py-3 text-center text-xs font-bold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            Roll-up
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <template v-for="group in groupedCases" :key="group.id ?? 'none'">
                        <tr>
                            <td :colspan="columns.length + 2"
                                class="sticky left-0 border-b border-gray-200 bg-gray-100 px-4 py-2 dark:border-gray-700 dark:bg-gray-700/60">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-black uppercase tracking-widest text-gray-600 dark:text-gray-200">{{ group.name }}</span>
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ group.cases.length }} item(s)</span>
                                    <button v-if="canExecute && cycleOpen && group.id" @click="openBulk('section', null, group)"
                                            class="text-[11px] font-semibold text-blue-600 hover:underline dark:text-blue-300">
                                        fill module
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="testCase in group.cases" :key="testCase.id"
                            class="group hover:bg-blue-50/40 dark:hover:bg-blue-500/5">
                            <td class="sticky left-0 z-10 border-b border-r border-gray-200 bg-white px-4 py-2.5 group-hover:bg-blue-50/40 dark:border-gray-700 dark:bg-gray-800 dark:group-hover:bg-blue-500/5">
                                <div class="flex items-start gap-2">
                                    <span class="mt-0.5 font-mono text-[11px] font-bold text-gray-400">{{ testCase.case_key }}</span>
                                    <div class="min-w-0">
                                        <button @click="$emit('open-case', testCase.id)"
                                                class="block text-left text-sm font-medium text-gray-900 hover:text-blue-700 hover:underline dark:text-gray-100 dark:hover:text-blue-300">
                                            {{ testCase.title }}
                                        </button>
                                        <div v-if="testCase.screen && testCase.screen !== testCase.title" class="truncate text-[11px] text-gray-400">
                                            {{ testCase.screen }}
                                        </div>
                                    </div>
                                    <span v-if="!testCase.is_critical"
                                          class="ml-auto shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                        Non-crit
                                    </span>
                                </div>
                            </td>

                            <td v-for="column in columns" :key="column.key"
                                class="border-b border-gray-200 px-1.5 py-2 text-center dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="openCell(testCase, column)"
                                    :disabled="!canExecute"
                                    :title="cellTitle(testCase, column)"
                                    class="inline-flex h-8 w-full max-w-[80px] items-center justify-center rounded-md text-sm font-bold transition-all disabled:cursor-default"
                                    :class="[cellClass(testCase, column), canExecute ? 'hover:scale-105' : '']"
                                >
                                    <span aria-hidden="true">{{ cellGlyph(testCase, column) }}</span>
                                    <span class="sr-only">{{ cellLabel(testCase, column) }}</span>
                                    <span v-if="cellHasRemark(testCase, column)"
                                          class="ml-1 h-1.5 w-1.5 rounded-full bg-current opacity-70"
                                          title="Has remarks"></span>
                                </button>
                            </td>

                            <td class="border-b border-l border-gray-200 px-2 py-2 text-center dark:border-gray-700">
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                                      :class="verdict(rollUp(testCase)).chip">
                                    {{ verdict(rollUp(testCase)).short }}
                                </span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <VerdictModal
            :show="cellModal.open"
            :cycle="cycle"
            :test-case="cellModal.testCase"
            :column="cellModal.column"
            :results="results"
            @close="cellModal.open = false"
            @log-finding="startFinding"
        />

        <FindingModal
            :show="findingModal.open"
            :cycle="cycle"
            :cases="cases"
            :options="options"
            :prefill="findingModal.prefill"
            @close="findingModal.open = false"
        />

        <!-- Bulk fill -->
        <Modal :show="bulk.open" @close="bulk.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Bulk Fill Verdicts</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">{{ bulkDescription }}</p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Set verdict to</label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="key in VERDICT_ORDER" :key="key" type="button" @click="bulk.result = key"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-semibold transition-all"
                                    :class="bulk.result === key
                                        ? verdict(key).solid + ' border-transparent shadow'
                                        : 'border-gray-300 bg-white text-gray-600 hover:shadow dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300'">
                                <span aria-hidden="true">{{ verdict(key).glyph || '○' }}</span>
                                {{ verdict(key).label }}
                            </button>
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="bulk.only_pending" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Only overwrite pending cells</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Leave ticked to protect verdicts somebody already recorded.</span>
                        </span>
                    </label>

                    <p v-if="!bulk.only_pending" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                        This will overwrite verdicts other testers have already given, including their remarks attribution.
                    </p>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="bulk.open = false"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="button" @click="submitBulk" :disabled="bulk.saving"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ bulk.saving ? 'Applying...' : 'Apply' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, inject } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import VerdictModal from './VerdictModal.vue'
import FindingModal from './FindingModal.vue'
import { verdict, VERDICT_ORDER } from '../uatVerdict.js'

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

defineEmits(['open-case', 'go'])

const can = inject('uatCan', () => false)
const canExecute = computed(() => can('uat.execute') && cycleOpen.value)
const cycleOpen = computed(() => !['signed_off', 'cancelled'].includes(props.cycle?.status))

const search = ref('')
const sectionFilter = ref(null)
const verdictFilter = ref(null)
const criticalOnly = ref(false)

const cellModal = reactive({ open: false, testCase: null, column: null })
const findingModal = reactive({ open: false, prefill: null })
const bulk = reactive({ open: false, scope: 'participant', participant: null, section: null, result: 'passed', only_pending: true, saving: false })

// One column per DEPARTMENT, built server-side so the grid, the tallies and the
// exported workbook all use the same definition.
const columns = computed(() => props.columns || [])

const memberSummary = (column) =>
    (column.members || [])
        .map(m => `${m.name || column.label} (${m.role === 'approver' ? 'Approver' : 'Tester'})`)
        .join(', ')

// Verdict lookup keyed "caseId:participantId" — the matrix reads it once per cell.
const resultIndex = computed(() => {
    const index = new Map()
    for (const row of props.results || []) {
        index.set(`${row.uat_case_id}:${row.uat_participant_id}`, row)
    }
    return index
})

const resultFor = (testCase, participantId) =>
    participantId ? resultIndex.value.get(`${testCase.id}:${participantId}`) || null : null

/**
 * The department's verdict on a case: the approver's answer is the decision,
 * and until they give one the tester's stands. Mirrors
 * UatService::columnVerdict so the grid agrees with the header tallies.
 */
const cellResult = (testCase, column) => {
    const approved = resultFor(testCase, column.approver_id)
    if (approved && approved.result !== 'pending') return approved.result

    const others = (column.member_ids || [])
        .filter(id => id !== column.approver_id)
        .map(id => resultFor(testCase, id)?.result)
        .filter(Boolean)
        .filter(v => v !== 'not_applicable')

    if (!others.length) {
        const anyNa = (column.member_ids || []).some(id => resultFor(testCase, id)?.result === 'not_applicable')
        return anyNa ? 'not_applicable' : 'pending'
    }
    if (others.includes('failed')) return 'failed'
    if (others.includes('blocked')) return 'blocked'
    if (others.includes('pending')) return 'pending'
    if (others.includes('ongoing')) return 'ongoing'
    return 'passed'
}

/** The row actually shown in the cell, so remarks/evidence markers match it. */
const cellFor = (testCase, column) => {
    const approved = resultFor(testCase, column.approver_id)
    if (approved && approved.result !== 'pending') return approved

    for (const id of column.member_ids || []) {
        if (id === column.approver_id) continue
        const row = resultFor(testCase, id)
        if (row && row.result !== 'pending') return row
    }

    return resultFor(testCase, column.default_participant_id)
}

const cellClass = (testCase, column) => verdict(cellResult(testCase, column)).cell
const cellGlyph = (testCase, column) => verdict(cellResult(testCase, column)).glyph || '·'
const cellLabel = (testCase, column) => verdict(cellResult(testCase, column)).label
const cellHasRemark = (testCase, column) => Boolean(cellFor(testCase, column)?.remarks)

const cellTitle = (testCase, column) => {
    const decided = resultFor(testCase, column.approver_id)
    const source = decided && decided.result !== 'pending' ? 'approver' : 'tester'
    const base = `${column.label}: ${verdict(cellResult(testCase, column)).label} (${source}) — click for the breakdown`
    const row = cellFor(testCase, column)
    return row?.remarks ? `${base}\n${row.remarks.slice(0, 120)}` : base
}

/**
 * Mirrors UatService::caseVerdict: collapse each department first, then
 * worst-wins across them.
 */
const rollUp = (testCase) => {
    const verdicts = columns.value
        .map(column => cellResult(testCase, column))
        .filter(v => v !== 'not_applicable')

    if (!verdicts.length) return columns.value.length ? 'not_applicable' : 'pending'
    if (verdicts.includes('failed')) return 'failed'
    if (verdicts.includes('blocked')) return 'blocked'
    if (verdicts.includes('pending')) return 'pending'
    if (verdicts.includes('ongoing')) return 'ongoing'
    return 'passed'
}

const sectionOptions = computed(() => [
    { label: 'All sections/modules', value: null },
    ...(props.sections || []).map(s => ({ label: s.name, value: s.id })),
])

const verdictFilterOptions = computed(() => [
    { label: 'All verdicts', value: null },
    ...VERDICT_ORDER.map(key => ({ label: `Roll-up: ${verdict(key).label}`, value: key })),
])

const visibleCases = computed(() => {
    const term = search.value.trim().toLowerCase()

    return (props.cases || []).filter(testCase => {
        if (criticalOnly.value && !testCase.is_critical) return false
        if (sectionFilter.value && testCase.uat_section_id !== sectionFilter.value) return false
        if (verdictFilter.value && rollUp(testCase) !== verdictFilter.value) return false
        if (!term) return true

        return [testCase.case_key, testCase.title, testCase.screen]
            .some(field => String(field || '').toLowerCase().includes(term))
    })
})

const groupedCases = computed(() => {
    const byId = new Map()

    for (const testCase of visibleCases.value) {
        const key = testCase.uat_section_id ?? 'none'
        if (!byId.has(key)) {
            const section = (props.sections || []).find(s => s.id === testCase.uat_section_id)
            byId.set(key, { id: testCase.uat_section_id ?? null, name: section?.name || 'Ungrouped', cases: [] })
        }
        byId.get(key).cases.push(testCase)
    }

    return Array.from(byId.values())
})

/**
 * Clicking a department cell opens the breakdown: the tester's answer and the
 * approver's, side by side, each editable. The grid shows one number; this is
 * where you see who said what.
 */
const openCell = (testCase, column) => {
    if (!canExecute.value) return
    cellModal.testCase = testCase
    cellModal.column = column
    cellModal.open = true
}

const startFinding = (testCase) => {
    cellModal.open = false
    findingModal.prefill = {
        uat_case_id: testCase.id,
        uat_participant_id: cellModal.column?.default_participant_id ?? null,
        title: `${testCase.case_key} — `,
    }
    findingModal.open = true
}

const openBulk = (scope, participant = null, section = null) => {
    bulk.scope = scope
    bulk.participant = participant
    bulk.section = section
    bulk.result = 'passed'
    bulk.only_pending = true
    bulk.open = true
}

const bulkDescription = computed(() => {
    if (bulk.scope === 'participant') {
        const owner = (bulk.participant?.members || []).find(m => m.id === bulk.participant?.default_participant_id)
        const who = owner ? `${owner.name} (${owner.role === 'approver' ? 'Approver' : 'Tester'})` : 'the column owner'
        return `Applies to every test case in the ${bulk.participant?.label} column, recorded against ${who}.`
    }
    return `Applies to every test case in the ${bulk.section?.name} section/module, across all columns.`
})

const submitBulk = () => {
    bulk.saving = true

    router.post(`/uat/${props.cycle.id}/results/bulk`, {
        scope: bulk.scope,
        // A department column is filled through whoever owns its decision —
        // the approver when there is one, otherwise the tester.
        uat_participant_id: bulk.participant?.default_participant_id ?? null,
        uat_section_id: bulk.section?.id ?? null,
        result: bulk.result,
        only_pending: bulk.only_pending,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { bulk.open = false },
        onFinish: () => { bulk.saving = false },
    })
}
</script>
