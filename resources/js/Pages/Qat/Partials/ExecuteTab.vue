<template>
    <div class="space-y-4">
        <!-- Who am I testing as -->
        <div class="flex flex-wrap items-end gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="min-w-[16rem]">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Recording as</label>
                <Autocomplete v-model="asParticipant" :options="participantOptions" placeholder="Select who you are testing as..." />
            </div>
            <div class="min-w-[12rem]">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Show</label>
                <Autocomplete v-model="filter" :options="FILTERS" placeholder="All cases" />
            </div>
            <div class="ml-auto text-sm text-gray-500 dark:text-gray-400">
                {{ visible.length }} of {{ cases.length }} case(s)
            </div>
        </div>

        <div v-if="!editable" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            This cycle is closed to new verdicts. It is frozen while it waits for the manager's decision, so they
            are deciding on exactly what was submitted.
        </div>

        <div v-if="!asParticipant" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Choose who you are recording verdicts as to start the run.</p>
        </div>

        <!-- The queue -->
        <div v-else class="space-y-3">
            <div v-for="row in visible" :key="row.id"
                 :ref="el => setRef(row.id, el)"
                 class="rounded-xl border bg-white p-4 shadow-sm transition-colors dark:bg-gray-800"
                 :class="row.id === jumpedTo
                     ? 'border-blue-400 ring-2 ring-blue-200 dark:border-blue-500 dark:ring-blue-500/30'
                     : 'border-gray-200 dark:border-gray-700'">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-gray-400">{{ row.case_key }}</span>
                            <span v-if="row.screen" class="text-xs text-gray-500 dark:text-gray-400">{{ row.screen }}</span>
                            <span v-if="!row.is_critical" class="rounded bg-slate-100 px-1.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                non-critical
                            </span>
                        </div>
                        <h4 class="mt-0.5 font-semibold text-gray-900 dark:text-gray-100">{{ row.title }}</h4>
                    </div>

                    <span class="rounded px-2 py-1 text-xs font-bold" :class="verdict(current(row.id)).chip">
                        {{ verdict(current(row.id)).label }}
                    </span>
                </div>

                <!-- Full text is fetched per case: steps/expected are nvarchar(MAX)
                     and shipping them for every case would drag the page down. -->
                <button @click="toggle(row.id)"
                        class="mt-2 text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                    {{ expanded === row.id ? 'Hide' : 'Show' }} steps &amp; expected results
                </button>

                <div v-if="expanded === row.id" class="mt-2 grid gap-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-slate-900 md:grid-cols-2">
                    <div>
                        <h5 class="text-xs font-bold uppercase tracking-wider text-gray-400">Steps</h5>
                        <ol v-if="asLines(detail?.case?.steps).length" class="mt-1 space-y-0.5 text-gray-700 dark:text-gray-200">
                            <li v-for="(line, i) in asLines(detail?.case?.steps)" :key="i">{{ line }}</li>
                        </ol>
                        <p v-else class="mt-1 italic text-gray-400">None recorded.</p>
                    </div>
                    <div>
                        <h5 class="text-xs font-bold uppercase tracking-wider text-gray-400">Expected results</h5>
                        <ul v-if="asLines(detail?.case?.expected_results).length" class="mt-1 space-y-0.5 text-gray-700 dark:text-gray-200">
                            <li v-for="(line, i) in asLines(detail?.case?.expected_results)" :key="i">☐ {{ line }}</li>
                        </ul>
                        <p v-else class="mt-1 italic text-gray-400">None recorded.</p>
                    </div>
                </div>

                <!-- Quick verdicts -->
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <button v-for="key in VERDICT_ORDER" :key="key"
                            type="button" :disabled="!can('qat.execute') || !editable || saving"
                            @click="record(row, key)"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                            :class="current(row.id) === key
                                ? verdict(key).solid
                                : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-300 dark:hover:bg-gray-700'">
                        {{ verdict(key).glyph }} {{ verdict(key).short }}
                    </button>

                    <button v-if="can('qat.execute')" type="button" @click="openFinding(row)"
                            class="ml-auto rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-50 dark:border-rose-400/30 dark:bg-slate-900 dark:text-rose-200 dark:hover:bg-rose-500/15">
                        Log a finding
                    </button>
                </div>
            </div>

            <p v-if="!visible.length" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                Nothing matches this filter.
            </p>
        </div>

        <FindingModal
            :show="findingFor !== null"
            :cycle="cycle"
            :test-case="findingFor"
            :participant-id="asParticipant"
            :options="options"
            @close="findingFor = null"
        />
    </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import Autocomplete from '@/Components/Autocomplete.vue'
import FindingModal from './FindingModal.vue'
import { verdict, VERDICT_ORDER, asLines } from '../qatVerdict'

const props = defineProps({
    cycle: { type: Object, required: true },
    cases: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const route = window.route
const can = inject('qatCan', () => false)

// Every ref is declared before anything that reads it.
const asParticipant = ref(null)
const filter = ref('all')
const expanded = ref(null)
const detail = ref(null)
const saving = ref(false)
const findingFor = ref(null)
const jumpedTo = ref(null)
const rowRefs = {}

const FILTERS = [
    { label: 'All cases', value: 'all' },
    { label: 'Not yet answered', value: 'pending' },
    { label: 'Failed', value: 'problem' },
    { label: 'Critical only', value: 'critical' },
]

const editable = computed(() => ['draft', 'testing', 'returned'].includes(props.cycle.status))

const participantOptions = computed(() =>
    props.participants
        .filter(p => p.is_active && p.role !== 'observer')
        .map(p => ({ label: `${p.label} — ${p.display_name} (${p.role})`, value: p.id }))
)

const current = (caseId) => {
    if (!asParticipant.value) return 'pending'
    return props.results.find(r => r.qat_case_id === caseId && r.qat_participant_id === asParticipant.value)?.result || 'pending'
}

const visible = computed(() => {
    if (filter.value === 'pending') return props.cases.filter(c => current(c.id) === 'pending')
    // 'blocked' is retired as a verdict but still catches cycles recorded before that.
    if (filter.value === 'problem') return props.cases.filter(c => ['failed', 'blocked'].includes(current(c.id)))
    if (filter.value === 'critical') return props.cases.filter(c => c.is_critical)
    return props.cases
})

const setRef = (id, el) => { if (el) rowRefs[id] = el }

const toggle = async (caseId) => {
    if (expanded.value === caseId) {
        expanded.value = null
        return
    }
    expanded.value = caseId
    detail.value = null

    const response = await fetch(route('qat.cases.show', [props.cycle.id, caseId]), {
        headers: { Accept: 'application/json' },
    })
    if (response.ok) detail.value = await response.json()
}

const record = (row, result) => {
    if (!asParticipant.value) return
    saving.value = true
    router.post(route('qat.results.store', props.cycle.id), {
        qat_case_id: row.id,
        qat_participant_id: asParticipant.value,
        result,
    }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { saving.value = false },
    })
}

const openFinding = (row) => { findingFor.value = row }

// The matrix hands a case over through sessionStorage rather than a prop, because
// switching tabs is a client-side move with no server round trip to carry state.
onMounted(async () => {
    if (participantOptions.value.length === 1) {
        asParticipant.value = participantOptions.value[0].value
    }

    const jump = window.sessionStorage.getItem('qat.jumpToCase')
    if (!jump) return
    window.sessionStorage.removeItem('qat.jumpToCase')

    const id = Number(jump)
    jumpedTo.value = id
    await nextTick()
    rowRefs[id]?.scrollIntoView({ behavior: 'smooth', block: 'center' })
})
</script>
