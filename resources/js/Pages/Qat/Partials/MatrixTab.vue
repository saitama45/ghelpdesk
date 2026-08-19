<template>
    <div class="space-y-4">
        <!-- Legend + bulk fill -->
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold uppercase tracking-wider text-gray-400">Legend</span>
                <span v-for="key in VERDICT_ORDER" :key="key" class="inline-flex items-center gap-1">
                    <span class="inline-flex h-4 w-4 items-center justify-center rounded text-[10px] font-bold" :class="verdict(key).cell">
                        {{ verdict(key).glyph }}
                    </span>
                    <span class="text-gray-600 dark:text-gray-300">{{ verdict(key).label }}</span>
                </span>
            </div>

            <button v-if="can('qat.execute') && editable" @click="bulkOpen = true"
                    class="rounded-lg border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-50 dark:border-blue-400/30 dark:bg-slate-900 dark:text-blue-200 dark:hover:bg-blue-500/15">
                Bulk fill
            </button>
        </div>

        <div v-if="!columns.length || !cases.length"
             class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                The matrix needs {{ !cases.length ? 'test cases' : '' }}{{ !cases.length && !columns.length ? ' and ' : '' }}{{ !columns.length ? 'testers' : '' }}.
            </p>
            <button @click="$emit('go', 'setup')" class="mt-3 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                Go to Setup
            </button>
        </div>

        <!-- The grid -->
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-300">
                            Test case
                        </th>
                        <th v-for="column in columns" :key="column.key"
                            class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">
                            <div class="whitespace-nowrap">{{ column.label }}</div>
                            <div class="mt-0.5 text-[9px] font-normal normal-case text-gray-400">
                                {{ column.members.length }} {{ column.members.length === 1 ? 'person' : 'people' }}
                            </div>
                        </th>
                        <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">
                            Overall
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <template v-for="group in grouped" :key="group.id ?? 'ungrouped'">
                        <tr v-if="group.name" class="bg-gray-50 dark:bg-gray-900/60">
                            <td :colspan="columns.length + 2" class="px-3 py-1.5">
                                <span class="text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300">{{ group.name }}</span>
                                <span class="ml-2 text-[11px] text-gray-400">
                                    {{ sectionProgress[group.id]?.passed || 0 }} / {{ sectionProgress[group.id]?.total || group.cases.length }} passed
                                </span>
                            </td>
                        </tr>

                        <tr v-for="row in group.cases" :key="row.id"
                            class="border-b border-gray-100 transition-colors hover:bg-blue-50/40 dark:border-gray-700/60 dark:hover:bg-blue-500/5">
                            <td class="sticky left-0 z-10 max-w-xs bg-white px-3 py-1.5 dark:bg-gray-800">
                                <button @click="$emit('open-case', row.id)" class="text-left">
                                    <span class="font-mono text-[11px] text-gray-400">{{ row.case_key }}</span>
                                    <span class="ml-1.5 text-gray-800 hover:underline dark:text-gray-100">{{ row.title }}</span>
                                    <span v-if="!row.is_critical" class="ml-1 rounded bg-slate-100 px-1 text-[9px] font-semibold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                        non-critical
                                    </span>
                                </button>
                            </td>

                            <td v-for="column in columns" :key="column.key" class="px-1 py-1 text-center">
                                <button
                                    :disabled="!can('qat.execute') || !editable"
                                    @click="openCell(row, column)"
                                    class="inline-flex h-7 w-full min-w-[3rem] items-center justify-center rounded text-xs font-bold transition-colors disabled:cursor-not-allowed"
                                    :class="verdict(cellVerdict(row.id, column)).cell"
                                    :title="`${column.label}: ${verdict(cellVerdict(row.id, column)).label}`">
                                    {{ verdict(cellVerdict(row.id, column)).glyph || '·' }}
                                </button>
                            </td>

                            <td class="px-3 py-1.5 text-center">
                                <span class="inline-block rounded px-2 py-0.5 text-[11px] font-bold" :class="verdict(overall(row.id)).chip">
                                    {{ verdict(overall(row.id)).short }}
                                </span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <VerdictModal
            :show="!!activeCell"
            :cycle="cycle"
            :test-case="activeCell?.row"
            :column="activeCell?.column"
            :results="results"
            :participants="participants"
            :options="options"
            :editable="editable"
            @close="activeCell = null"
        />

        <!-- Bulk fill -->
        <Modal :show="bulkOpen" @close="bulkOpen = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Bulk fill verdicts</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Fills a whole column, section or case at once — the matrix equivalent of dragging a value
                    down a spreadsheet.
                </p>

                <form @submit.prevent="submitBulk" class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Fill by</label>
                        <Autocomplete v-model="bulk.scope" :options="SCOPES" placeholder="Select..." />
                    </div>

                    <div v-if="bulk.scope === 'participant' || bulk.scope === 'section'">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ bulk.scope === 'section' ? 'Section' : 'Tester' }}
                        </label>
                        <Autocomplete v-if="bulk.scope === 'section'" v-model="bulk.qat_section_id" :options="sectionOptions" placeholder="Select section..." />
                        <Autocomplete v-else v-model="bulk.qat_participant_id" :options="participantOptions" placeholder="Select tester..." />
                    </div>

                    <div v-if="bulk.scope === 'case'">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Test case</label>
                        <Autocomplete v-model="bulk.qat_case_id" :options="caseOptions" placeholder="Select case..." />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Set verdict to</label>
                        <Autocomplete v-model="bulk.result" :options="options.results || []" placeholder="Select verdict..." />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="bulk.only_pending" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Only fill cells that are still pending
                    </label>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Leave this ticked to protect verdicts somebody has already recorded.
                    </p>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="bulkOpen = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="bulk.processing"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ bulk.processing ? 'Filling...' : 'Fill' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, inject } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import VerdictModal from './VerdictModal.vue'
import { verdict, VERDICT_ORDER, columnVerdict, caseVerdict } from '../qatVerdict'

const props = defineProps({
    cycle: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    cases: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    columns: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    sectionProgress: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
})

defineEmits(['open-case', 'go'])

const route = window.route
const can = inject('qatCan', () => false)

const activeCell = ref(null)
const bulkOpen = ref(false)

const SCOPES = [
    { label: 'A tester (whole column)', value: 'participant' },
    { label: 'A section', value: 'section' },
    { label: 'A test case (whole row)', value: 'case' },
]

const bulk = useForm({
    scope: 'participant',
    qat_case_id: null,
    qat_participant_id: null,
    qat_section_id: null,
    result: 'passed',
    only_pending: true,
})

// A cycle awaiting a decision is frozen, so the manager sees what they were shown.
const editable = computed(() => ['draft', 'testing', 'returned'].includes(props.cycle.status))

const byCase = computed(() => {
    const map = {}
    for (const r of props.results) (map[r.qat_case_id] ||= []).push(r)
    return map
})

const cellVerdict = (caseId, column) => columnVerdict(byCase.value[caseId] || [], column)
const overall = (caseId) => caseVerdict(byCase.value[caseId] || [], props.columns)

const grouped = computed(() => {
    const groups = []
    for (const section of props.sections) {
        const rows = props.cases.filter(c => c.qat_section_id === section.id)
        if (rows.length) groups.push({ id: section.id, name: section.name, cases: rows })
    }
    const ungrouped = props.cases.filter(c => !c.qat_section_id)
    if (ungrouped.length) groups.push({ id: null, name: groups.length ? 'Ungrouped' : null, cases: ungrouped })
    return groups
})

const sectionOptions = computed(() => props.sections.map(s => ({ label: s.name, value: s.id })))
const caseOptions = computed(() => props.cases.map(c => ({ label: `${c.case_key} — ${c.title}`, value: c.id })))
const participantOptions = computed(() =>
    props.participants.filter(p => p.is_active && p.role !== 'observer')
        .map(p => ({ label: `${p.label} — ${p.display_name}`, value: p.id }))
)

const openCell = (row, column) => {
    activeCell.value = { row, column }
}

const submitBulk = () => {
    bulk.post(route('qat.results.bulk', props.cycle.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { bulkOpen.value = false },
    })
}
</script>
