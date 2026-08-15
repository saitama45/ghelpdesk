<template>
    <div class="min-h-screen bg-gray-50 pb-16">
        <Head :title="`UAT — ${cycle.title}`" />

        <!-- Header -->
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-gray-400">{{ cycle.code }}</span>
                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700">
                                Round {{ cycle.cycle_no }}
                            </span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">
                                {{ cycle.environment }}
                            </span>
                        </div>
                        <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ cycle.title }}</h1>
                        <p v-if="cycle.system_name" class="text-sm text-gray-500">{{ cycle.system_name }}</p>
                        <p class="mt-2 text-sm text-gray-600">
                            You are testing as <span class="font-semibold text-gray-900">{{ participant.label }}</span>
                            <span v-if="participant.name"> ({{ participant.name }})</span>.
                        </p>
                    </div>

                    <div class="text-right">
                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-400">Your progress</div>
                        <div class="text-2xl font-bold text-gray-900">{{ answered }} / {{ cases.length }}</div>
                        <div class="mt-1 h-2 w-40 overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full bg-emerald-500 transition-all" :style="{ width: progressWidth }"></div>
                        </div>
                    </div>
                </div>

                <div v-if="(cycle.links || []).length" class="mt-4 flex flex-wrap gap-2">
                    <a v-for="(link, index) in cycle.links" :key="index" :href="link.url" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        Open {{ link.label || 'the system' }}
                    </a>
                </div>

                <p v-if="cycle.description" class="mt-3 max-w-3xl text-sm text-gray-600">{{ cycle.description }}</p>
            </div>
        </header>

        <div v-if="flash" class="mx-auto mt-4 max-w-6xl px-4 sm:px-6">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ flash }}
            </div>
        </div>

        <div v-if="!cycle.is_open" class="mx-auto mt-4 max-w-6xl px-4 sm:px-6">
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                This round is closed. You can still read the checklist, but verdicts can no longer be changed.
            </div>
        </div>

        <main class="mx-auto mt-6 max-w-6xl px-4 sm:px-6">
            <!-- Tabs -->
            <div class="mb-5 flex gap-2">
                <button @click="tab = 'checklist'"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                        :class="tab === 'checklist' ? 'bg-blue-600 text-white shadow-sm' : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50'">
                    My Checklist
                </button>
                <button v-if="participant.is_approver" @click="tab = 'signoff'"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                        :class="tab === 'signoff' ? 'bg-blue-600 text-white shadow-sm' : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50'">
                    Acceptance &amp; Sign-off
                </button>
            </div>

            <!-- ============ CHECKLIST ============ -->
            <div v-if="tab === 'checklist'" class="space-y-4">
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <input v-model="search" type="text" placeholder="Search the checklist..."
                           class="min-w-[220px] flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <div class="flex gap-2">
                        <button v-for="option in scopeOptions" :key="option.value" @click="scope = option.value"
                                class="rounded-lg px-3 py-2 text-xs font-semibold transition-colors"
                                :class="scope === option.value ? 'bg-gray-900 text-white' : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50'">
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <p v-if="!visibleCases.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">
                    Nothing here — try switching the filter back to “All”.
                </p>

                <template v-for="group in grouped" :key="group.id ?? 'none'">
                    <h2 class="pt-2 text-xs font-black uppercase tracking-widest text-gray-500">{{ group.name }}</h2>

                    <div v-for="item in group.cases" :key="item.id"
                         class="rounded-xl border bg-white p-4 shadow-sm transition-colors"
                         :class="borderFor(item)">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-[11px] font-bold text-gray-400">{{ item.case_key }}</span>
                                    <span v-if="!item.is_critical" class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-500">
                                        Optional
                                    </span>
                                </div>
                                <h3 class="mt-0.5 text-base font-semibold text-gray-900">{{ item.title }}</h3>
                                <p v-if="item.screen && item.screen !== item.title" class="text-sm text-gray-500">{{ item.screen }}</p>
                            </div>

                            <button @click="toggle(item)"
                                    class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-50">
                                {{ expanded === item.id ? 'Hide steps' : 'Show steps' }}
                            </button>
                        </div>

                        <!-- Procedure -->
                        <div v-if="expanded === item.id" class="mt-3 rounded-lg bg-gray-50 p-3">
                            <p v-if="detailLoading" class="text-sm text-gray-400">Loading…</p>
                            <template v-else>
                                <p v-if="detail?.description" class="mb-2 text-sm text-gray-700">{{ detail.description }}</p>
                                <div v-if="detail?.steps">
                                    <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500">Test Steps</h4>
                                    <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800">{{ detail.steps }}</pre>
                                </div>
                                <div v-if="detail?.expected_results" class="mt-3">
                                    <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500">Expected Results</h4>
                                    <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800">{{ detail.expected_results }}</pre>
                                </div>
                                <p v-if="!detail?.steps && !detail?.expected_results && !detail?.description" class="text-sm text-gray-400">
                                    No written procedure — just confirm you can do this, then answer below.
                                </p>
                            </template>
                        </div>

                        <!-- Answer -->
                        <div v-if="participant.can_record && cycle.is_open" class="mt-3 border-t border-gray-100 pt-3">
                            <div class="flex flex-wrap gap-2">
                                <button v-for="key in ANSWER_ORDER" :key="key" @click="setVerdict(item, key)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-semibold transition-all"
                                        :class="verdictOf(item) === key
                                            ? verdict(key).solid + ' border-transparent shadow'
                                            : 'border-gray-300 bg-white text-gray-600 hover:-translate-y-0.5 hover:shadow'">
                                    <span aria-hidden="true">{{ verdict(key).glyph || '○' }}</span>
                                    {{ answerLabel(key) }}
                                </button>
                            </div>

                            <div v-if="isOpenForRemark(item)" class="mt-3">
                                <label class="mb-1 block text-sm font-medium text-gray-700">
                                    What went wrong? <span class="text-rose-600">*</span>
                                </label>
                                <textarea v-model="drafts[item.id]" rows="3"
                                          placeholder="Describe what you saw and what you expected instead."
                                          class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>

                                <!-- A picture of the problem. Required when something
                                     is broken; optional when it could not be tested. -->
                                <div class="mt-3">
                                    <label class="mb-1 block text-sm font-medium text-gray-700">
                                        Screenshot
                                        <span v-if="pendingVerdict[item.id] === 'failed'" class="text-rose-600">*</span>
                                        <span v-else class="text-xs font-normal text-gray-400">(optional)</span>
                                    </label>
                                    <input type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp"
                                           @change="onShotsPicked(item, $event)"
                                           class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700">
                                    <p v-if="compressing === item.id" class="mt-1 text-xs font-medium text-blue-600">
                                        Resizing your image…
                                    </p>
                                    <p v-else-if="(shots[item.id] || []).length" class="mt-1 text-xs text-emerald-600">
                                        {{ shots[item.id].length }} file(s) ready
                                    </p>
                                    <p v-for="(note, i) in (shotNotes[item.id] || [])" :key="i" class="mt-0.5 text-xs text-gray-500">
                                        Resized for upload — {{ note }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-400">
                                        A screenshot is by far the fastest way for the team to understand the problem.
                                        Large images are resized automatically — you don't need to shrink them yourself.
                                    </p>
                                </div>

                                <p v-if="errors[item.id]" class="mt-2 text-xs font-medium text-rose-600">{{ errors[item.id] }}</p>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button @click="cancelRemark(item)"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50">
                                        Cancel
                                    </button>
                                    <button @click="submitVerdict(item)" :disabled="saving === item.id || compressing === item.id"
                                            class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                                        {{ compressing === item.id ? 'Resizing...' : (saving === item.id ? 'Saving...' : 'Submit') }}
                                    </button>
                                </div>
                            </div>

                            <p v-else-if="remarkOf(item)" class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                <span class="font-semibold">Your note:</span> {{ remarkOf(item) }}
                            </p>
                        </div>

                        <div v-else-if="verdictOf(item) !== 'pending'" class="mt-3 border-t border-gray-100 pt-3">
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="verdict(verdictOf(item)).chip">
                                {{ answerLabel(verdictOf(item)) }}
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ============ SIGN-OFF ============ -->
            <div v-else-if="tab === 'signoff'" class="space-y-4">
                <div v-if="signoff" class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                    <h2 class="text-base font-bold text-emerald-900">You have already responded</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide" :class="SIGNOFF_CHIPS[signoff.result]">
                            {{ signoffLabel(signoff.result) }}
                        </span>
                        <span class="text-emerald-800">{{ formatDateTime(signoff.confirmed_at) }}</span>
                    </div>
                    <p v-if="signoff.remarks" class="mt-2 text-sm text-emerald-900">{{ signoff.remarks }}</p>
                    <p class="mt-3 text-xs text-emerald-700">
                        You can submit again below — your latest response replaces this one, and both are kept on record.
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900">Acceptance &amp; Sign-off</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Confirm on behalf of <span class="font-semibold">{{ participant.label }}</span>.
                        {{ outstanding }} of {{ cases.length }} item(s) on your checklist are still unanswered.
                    </p>

                    <form @submit.prevent="submitSignoff" class="mt-5 space-y-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">Overall result</label>
                            <div class="space-y-2">
                                <label v-for="option in options.signoffResults" :key="option.value"
                                       class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                                       :class="signoffForm.result === option.value ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'">
                                    <input v-model="signoffForm.result" :value="option.value" type="radio" class="mt-0.5 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-gray-800">{{ option.label }}</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">
                                Remarks <span v-if="signoffForm.result !== 'passed'" class="text-rose-600">*</span>
                            </label>
                            <textarea v-model="signoffForm.remarks" rows="3"
                                      :placeholder="signoffForm.result === 'passed' ? 'Optional note.' : 'State the reservation, or why this is not accepted.'"
                                      class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Type your full name to confirm <span class="text-rose-600">*</span></label>
                            <input v-model="signoffForm.confirmed_name" type="text" required
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs text-gray-400">
                                Your name, the date and time are recorded against this acceptance.
                            </p>
                        </div>

                        <p v-if="signoffError" class="text-sm font-medium text-rose-600">{{ signoffError }}</p>

                        <button type="submit" :disabled="signoffSaving"
                                class="w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50">
                            {{ signoffSaving ? 'Submitting...' : 'Submit My Acceptance' }}
                        </button>
                    </form>
                </div>
            </div>
        </main>

        <footer class="mx-auto mt-10 max-w-6xl px-4 text-center text-xs text-gray-400 sm:px-6">
            This is a private link issued for {{ participant.label }}. Please do not forward it.
        </footer>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { verdict, SIGNOFF_CHIPS, formatDateTime } from '../Uat/uatVerdict.js'
import { compressImages } from '@/Composables/useImageCompressor.js'

const props = defineProps({
    token: String,
    participant: Object,
    cycle: Object,
    sections: { type: Array, default: () => [] },
    cases: { type: Array, default: () => [] },
    results: { type: Array, default: () => [] },
    signoff: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
})

const page = usePage()
const flash = computed(() => page.props?.flash?.success || null)

const tab = ref('checklist')
const search = ref('')
const scope = ref('all')
const expanded = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const saving = ref(null)
const drafts = reactive({})
const errors = reactive({})
const pendingVerdict = reactive({})
const shots = reactive({})
const shotNotes = reactive({})
const compressing = ref(null)

/**
 * Clients photograph or screenshot on whatever device they have, and those files
 * are routinely far over the limit. Shrink them here rather than making a
 * non-technical stakeholder work out how to resize an image.
 */
const onShotsPicked = async (item, event) => {
    compressing.value = item.id
    shotNotes[item.id] = []

    try {
        const { files, notes } = await compressImages(event.target.files)
        shots[item.id] = files
        shotNotes[item.id] = notes
    } finally {
        compressing.value = null
    }
}

const signoffForm = reactive({
    result: props.signoff?.result || 'passed',
    remarks: props.signoff?.remarks || '',
    confirmed_name: props.participant?.name || '',
})
const signoffSaving = ref(false)
const signoffError = ref('')

// External stakeholders get plain language, not QA vocabulary.
const ANSWER_LABELS = {
    passed: 'Works as expected',
    failed: 'Has a problem',
    blocked: "Couldn't test it",
    not_applicable: 'Not applicable',
}
const ANSWER_ORDER = ['passed', 'failed', 'blocked', 'not_applicable']

const scopeOptions = [
    { label: 'All', value: 'all' },
    { label: 'Unanswered', value: 'pending' },
    { label: 'Problems', value: 'problem' },
]

const answerLabel = (key) => ANSWER_LABELS[key] || verdict(key).label

const resultIndex = computed(() => {
    const index = new Map()
    for (const row of props.results || []) index.set(row.uat_case_id, row)
    return index
})

const verdictOf = (item) => pendingVerdict[item.id] || resultIndex.value.get(item.id)?.result || 'pending'
const remarkOf = (item) => resultIndex.value.get(item.id)?.remarks || ''

const answered = computed(() =>
    (props.cases || []).filter(c => (resultIndex.value.get(c.id)?.result || 'pending') !== 'pending').length
)
const outstanding = computed(() => (props.cases || []).length - answered.value)
const progressWidth = computed(() => {
    const total = (props.cases || []).length
    return total ? `${Math.round((answered.value / total) * 100)}%` : '0%'
})

const visibleCases = computed(() => {
    const term = search.value.trim().toLowerCase()

    return (props.cases || []).filter(item => {
        const current = resultIndex.value.get(item.id)?.result || 'pending'
        if (scope.value === 'pending' && current !== 'pending') return false
        if (scope.value === 'problem' && !['failed', 'blocked'].includes(current)) return false
        if (!term) return true
        return [item.case_key, item.title, item.screen].some(f => String(f || '').toLowerCase().includes(term))
    })
})

const grouped = computed(() => {
    const byId = new Map()
    for (const item of visibleCases.value) {
        const key = item.uat_section_id ?? 'none'
        if (!byId.has(key)) {
            const section = (props.sections || []).find(s => s.id === item.uat_section_id)
            byId.set(key, { id: item.uat_section_id ?? null, name: section?.name || 'General', cases: [] })
        }
        byId.get(key).cases.push(item)
    }
    return Array.from(byId.values())
})

const borderFor = (item) => {
    const current = verdictOf(item)
    if (current === 'failed') return 'border-rose-300'
    if (current === 'blocked') return 'border-amber-300'
    if (current === 'passed') return 'border-emerald-200'
    return 'border-gray-200'
}

const isOpenForRemark = (item) => ['failed', 'blocked'].includes(pendingVerdict[item.id])

const toggle = async (item) => {
    if (expanded.value === item.id) {
        expanded.value = null
        return
    }

    expanded.value = item.id
    detail.value = null
    detailLoading.value = true

    try {
        const response = await fetch(`/public/uat/${props.token}/cases/${item.id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        detail.value = response.ok ? (await response.json()).case : null
    } catch {
        detail.value = null
    } finally {
        detailLoading.value = false
    }
}

const setVerdict = (item, key) => {
    errors[item.id] = ''

    // A problem needs an explanation, so it opens the note box instead of
    // saving straight away. Everything else saves on the click.
    if (['failed', 'blocked'].includes(key)) {
        pendingVerdict[item.id] = key
        drafts[item.id] = drafts[item.id] || remarkOf(item) || ''
        return
    }

    pendingVerdict[item.id] = key
    post(item, key, drafts[item.id] || null)
}

const submitVerdict = (item) => {
    const key = pendingVerdict[item.id]

    if (!(drafts[item.id] || '').trim()) {
        errors[item.id] = 'Please describe the problem so the team can act on it.'
        return
    }

    // Mirrors the server rule, so the client is told before the upload round trip.
    if (key === 'failed' && (shots[item.id] || []).length === 0) {
        errors[item.id] = 'Please attach a screenshot showing the problem.'
        return
    }

    post(item, key, drafts[item.id])
}

const cancelRemark = (item) => {
    delete pendingVerdict[item.id]
    delete shots[item.id]
    delete shotNotes[item.id]
    errors[item.id] = ''
}

const post = (item, result, remarks) => {
    saving.value = item.id

    router.post(`/public/uat/${props.token}/verdict`, {
        uat_case_id: item.id,
        result,
        remarks,
        screenshots: shots[item.id] || [],
    }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            delete pendingVerdict[item.id]
            delete shots[item.id]
            delete shotNotes[item.id]
        },
        onError: (e) => { errors[item.id] = e.remarks || e.screenshots || 'Could not save that.' },
        onFinish: () => { saving.value = null },
    })
}

const signoffLabel = (value) =>
    (props.options?.signoffResults || []).find(o => o.value === value)?.label || value

const submitSignoff = () => {
    signoffError.value = ''

    if (signoffForm.result !== 'passed' && !signoffForm.remarks.trim()) {
        signoffError.value = 'Please explain the reservation or the reason for not accepting.'
        return
    }

    signoffSaving.value = true
    router.post(`/public/uat/${props.token}/signoff`, { ...signoffForm }, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { signoffError.value = e.remarks || e.confirmed_name || 'Could not submit that.' },
        onFinish: () => { signoffSaving.value = false },
    })
}
</script>
