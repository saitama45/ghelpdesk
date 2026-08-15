<template>
    <div class="space-y-4">
        <!-- Who am I testing as -->
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="w-64">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Recording as</label>
                <Autocomplete v-model="participantId" :options="participantOptions" placeholder="Select your column..." />
            </div>
            <div class="w-52">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Show</label>
                <Autocomplete v-model="scopeFilter" :options="scopeOptions" placeholder="All cases" />
            </div>
            <div class="w-52">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Section</label>
                <Autocomplete v-model="sectionFilter" :options="sectionOptions" placeholder="All sections" />
            </div>

            <div v-if="activeParticipant" class="ml-auto text-right">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400">Your progress</div>
                <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ myAnswered }} / {{ queue.length ? queue.length : myTotal }}
                </div>
            </div>
        </div>

        <div v-if="!participantOptions.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No tester columns exist yet. Add participants in the <span class="font-semibold">Setup</span> tab first.
            </p>
        </div>

        <div v-else-if="!queue.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing to run here — try switching "Show" back to All cases.
            </p>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
            <!-- Case queue -->
            <div class="max-h-[70vh] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Queue — {{ queue.length }} case(s)
                    </span>
                </div>
                <button v-for="(item, index) in queue" :key="item.id" @click="goTo(index)"
                        class="flex w-full items-start gap-2.5 border-b border-gray-100 px-4 py-2.5 text-left transition-colors last:border-0 dark:border-gray-700"
                        :class="index === cursor
                            ? 'bg-blue-50 dark:bg-blue-500/10'
                            : 'hover:bg-gray-50 dark:hover:bg-gray-700'">
                    <span class="mt-1 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold text-white"
                          :class="verdict(myVerdict(item)).dot"
                          :title="verdict(myVerdict(item)).label">
                        {{ verdict(myVerdict(item)).glyph }}
                    </span>
                    <span class="min-w-0">
                        <span class="block font-mono text-[10px] font-bold text-gray-400">{{ item.case_key }}</span>
                        <span class="block truncate text-sm"
                              :class="index === cursor ? 'font-semibold text-blue-800 dark:text-blue-200' : 'text-gray-700 dark:text-gray-200'">
                            {{ item.title }}
                        </span>
                    </span>
                </button>
            </div>

            <!-- Runner -->
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 p-5 dark:border-gray-700">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-bold text-gray-400">{{ current?.case_key }}</span>
                            <span v-if="current && !current.is_critical"
                                  class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                Non-critical
                            </span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="verdict(currentVerdict).chip">
                                {{ verdict(currentVerdict).label }}
                            </span>
                        </div>
                        <h3 class="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">{{ current?.title }}</h3>
                        <p v-if="current?.screen" class="text-sm text-gray-500 dark:text-gray-300">{{ current.screen }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button @click="step(-1)" :disabled="cursor === 0" title="Previous case"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            ←
                        </button>
                        <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ cursor + 1 }} / {{ queue.length }}</span>
                        <button @click="step(1)" :disabled="cursor >= queue.length - 1" title="Next case"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            →
                        </button>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    <p v-if="loading" class="text-sm text-gray-400">Loading procedure…</p>

                    <template v-else>
                        <div v-if="detail?.description" class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900/40 dark:text-gray-200">
                            {{ detail.description }}
                        </div>

                        <div v-if="detail?.steps">
                            <h4 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Test Steps</h4>
                            <pre class="max-h-72 overflow-y-auto whitespace-pre-wrap break-words rounded-lg border border-gray-200 bg-white p-3 font-sans text-sm leading-relaxed text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ detail.steps }}</pre>
                        </div>

                        <!-- Expected results as a tick-list the tester actually works through -->
                        <div v-if="expectedLines.length">
                            <h4 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Expected Results</h4>
                            <ul class="space-y-1.5">
                                <li v-for="(line, index) in expectedLines" :key="index">
                                    <label class="flex cursor-pointer items-start gap-2.5 rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <input v-model="checkedExpectations[index]" type="checkbox"
                                               class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700 dark:text-gray-200"
                                              :class="checkedExpectations[index] ? 'line-through opacity-60' : ''">
                                            {{ line.replace(/^[*•-]\s*/, '') }}
                                        </span>
                                    </label>
                                </li>
                            </ul>
                            <p class="mt-1 px-2 text-[11px] text-gray-400">
                                Ticks are a personal scratchpad for this case — the verdict below is what gets saved.
                            </p>
                        </div>

                        <div v-if="!detail?.steps && !expectedLines.length && !detail?.description"
                             class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-400 dark:border-gray-600">
                            No documented procedure — this is a walkthrough check. Confirm you can do it, then record the verdict.
                        </div>
                    </template>

                    <!-- Verdict -->
                    <div class="border-t border-gray-200 pt-4 dark:border-gray-700">
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Verdict</label>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="key in VERDICT_ORDER" :key="key" type="button" @click="form.result = key"
                                    :disabled="!canRecord"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-4 py-2.5 text-sm font-semibold transition-all disabled:opacity-50"
                                    :class="form.result === key
                                        ? verdict(key).solid + ' border-transparent shadow'
                                        : 'border-gray-300 bg-white text-gray-600 hover:-translate-y-0.5 hover:shadow dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300'">
                                <span aria-hidden="true">{{ verdict(key).glyph || '○' }}</span>
                                {{ verdict(key).label }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Remarks <span v-if="remarksRequired" class="text-rose-600">*</span>
                        </label>
                        <textarea v-model="form.remarks" rows="3" :disabled="!canRecord"
                                  :placeholder="remarksRequired
                                      ? 'Describe what went wrong — this is what the fix will be built from.'
                                      : 'Optional note about what you saw.'"
                                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <InputError :message="error" class="mt-1" />
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button v-if="can('uat.execute')" type="button" @click="startFinding"
                                class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-600 hover:underline dark:text-rose-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" />
                            </svg>
                            Log a finding for this case
                        </button>
                        <span v-else></span>

                        <div class="flex items-center gap-3">
                            <button type="button" @click="save(false)" :disabled="saving || !canRecord"
                                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                Save
                            </button>
                            <button type="button" @click="save(true)" :disabled="saving || !canRecord"
                                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                                {{ saving ? 'Saving...' : 'Save & Next →' }}
                            </button>
                        </div>
                    </div>

                    <p v-if="!canRecord" class="text-right text-xs text-amber-600 dark:text-amber-400">
                        {{ participantId ? 'This cycle is closed to new verdicts.' : 'Pick the column you are recording as first.' }}
                    </p>
                </div>
            </div>
        </div>

        <FindingModal
            :show="findingModal.open"
            :cycle="cycle"
            :cases="cases"
            :options="options"
            :prefill="findingModal.prefill"
            @close="findingModal.open = false"
        />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, inject } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import Autocomplete from '@/Components/Autocomplete.vue'
import InputError from '@/Components/InputError.vue'
import FindingModal from './FindingModal.vue'
import { verdict, VERDICT_ORDER, asLines } from '../uatVerdict.js'

const props = defineProps({
    cycle: Object,
    sections: Array,
    cases: Array,
    participants: Array,
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

const can = inject('uatCan', () => false)

const participantId = ref(null)
const scopeFilter = ref('all')
const sectionFilter = ref(null)
const cursor = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const detail = ref(null)
const checkedExpectations = ref({})

const form = reactive({ result: 'pending', remarks: '' })
const findingModal = reactive({ open: false, prefill: null })

const testerColumns = computed(() =>
    (props.participants || []).filter(p => p.is_active && ['tester', 'approver'].includes(p.role))
)

const participantOptions = computed(() =>
    testerColumns.value.map(p => ({
        label: p.display_name && p.display_name !== p.label ? `${p.label} — ${p.display_name}` : p.label,
        value: p.id,
    }))
)

const activeParticipant = computed(() => testerColumns.value.find(p => p.id === participantId.value) || null)

const cycleOpen = computed(() => !['signed_off', 'cancelled'].includes(props.cycle?.status))
const canRecord = computed(() => Boolean(participantId.value) && cycleOpen.value && can('uat.execute'))

const scopeOptions = [
    { label: 'All cases', value: 'all' },
    { label: 'Not yet answered', value: 'pending' },
    { label: 'Failed or blocked', value: 'problem' },
    { label: 'Critical only', value: 'critical' },
]

const sectionOptions = computed(() => [
    { label: 'All sections', value: null },
    ...(props.sections || []).map(s => ({ label: s.name, value: s.id })),
])

const resultIndex = computed(() => {
    const index = new Map()
    for (const row of props.results || []) {
        index.set(`${row.uat_case_id}:${row.uat_participant_id}`, row)
    }
    return index
})

const resultFor = (testCase) =>
    participantId.value ? resultIndex.value.get(`${testCase.id}:${participantId.value}`) || null : null

const myVerdict = (testCase) => resultFor(testCase)?.result || 'pending'

const queue = computed(() => {
    return (props.cases || []).filter(testCase => {
        if (sectionFilter.value && testCase.uat_section_id !== sectionFilter.value) return false

        const mine = myVerdict(testCase)
        if (scopeFilter.value === 'pending' && mine !== 'pending') return false
        if (scopeFilter.value === 'problem' && !['failed', 'blocked'].includes(mine)) return false
        if (scopeFilter.value === 'critical' && !testCase.is_critical) return false

        return true
    })
})

const current = computed(() => queue.value[cursor.value] || null)
const currentVerdict = computed(() => (current.value ? myVerdict(current.value) : 'pending'))

const myTotal = computed(() => (props.cases || []).length)
const myAnswered = computed(() =>
    queue.value.filter(testCase => myVerdict(testCase) !== 'pending').length
)

const expectedLines = computed(() => asLines(detail.value?.expected_results))

const remarksRequired = computed(() => ['failed', 'blocked'].includes(form.result))

// Default to the column matching the signed-in user, so the common case needs
// no selection at all.
onMounted(() => {
    const currentUserId = usePage().props?.auth?.user?.id ?? null
    const mine = testerColumns.value.find(p => p.user_id && p.user_id === currentUserId)
    participantId.value = mine?.id ?? testerColumns.value[0]?.id ?? null

    const jump = window.sessionStorage.getItem('uat.jumpToCase')
    if (jump) {
        window.sessionStorage.removeItem('uat.jumpToCase')
        const index = queue.value.findIndex(c => String(c.id) === jump)
        if (index >= 0) cursor.value = index
    }
})

// Keep the cursor inside the queue when filters shrink it.
watch(queue, (list) => {
    if (cursor.value > list.length - 1) cursor.value = Math.max(0, list.length - 1)
})

watch([current, participantId], async () => {
    error.value = ''
    checkedExpectations.value = {}

    const testCase = current.value
    if (!testCase) {
        detail.value = null
        return
    }

    const mine = resultFor(testCase)
    form.result = mine?.result || 'pending'
    form.remarks = mine?.remarks || ''

    loading.value = true
    try {
        const response = await fetch(`/uat/${props.cycle.id}/cases/${testCase.id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        detail.value = response.ok ? (await response.json()).case : null
    } catch {
        detail.value = null
    } finally {
        loading.value = false
    }
}, { immediate: true })

const goTo = (index) => { cursor.value = index }

const step = (delta) => {
    const next = cursor.value + delta
    if (next >= 0 && next < queue.value.length) cursor.value = next
}

const save = (advance) => {
    if (!current.value || !canRecord.value) return

    error.value = ''
    if (remarksRequired.value && !form.remarks.trim()) {
        error.value = 'Describe what went wrong so the team can act on it.'
        return
    }

    saving.value = true
    const wasAt = cursor.value

    router.post(`/uat/${props.cycle.id}/results`, {
        uat_case_id: current.value.id,
        uat_participant_id: participantId.value,
        result: form.result,
        remarks: form.remarks || null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (!advance) return
            // The queue can re-filter under us after a save (e.g. "not yet
            // answered"), so only advance when the list still holds.
            const next = wasAt + 1
            cursor.value = next < queue.value.length ? next : Math.min(wasAt, Math.max(0, queue.value.length - 1))
        },
        onError: (e) => { error.value = e.remarks || e.result || 'Could not save that verdict.' },
        onFinish: () => { saving.value = false },
    })
}

const startFinding = () => {
    if (!current.value) return
    findingModal.prefill = {
        uat_case_id: current.value.id,
        uat_participant_id: participantId.value,
        title: `${current.value.case_key} — `,
        details: form.remarks || '',
    }
    findingModal.open = true
}
</script>
