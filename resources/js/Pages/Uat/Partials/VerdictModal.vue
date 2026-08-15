<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="3xl">
        <div v-if="testCase && column" class="p-6">
            <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-bold text-gray-400">{{ testCase.case_key }}</span>
                        <span v-if="!testCase.is_critical" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                            Non-critical
                        </span>
                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
                            {{ column.label }}
                        </span>
                    </div>
                    <h3 class="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">{{ testCase.title }}</h3>
                    <p v-if="testCase.screen" class="text-sm text-gray-500 dark:text-gray-300">{{ testCase.screen }}</p>
                </div>
                <button type="button" @click="$emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- The department's standing verdict, and where it came from -->
            <div class="mt-4 flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ column.label }} verdict
                </span>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="verdict(departmentVerdict).chip">
                    {{ verdict(departmentVerdict).label }}
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ decidedByApprover
                        ? "the approver's answer stands"
                        : "the tester's answer stands until the approver responds" }}
                </span>
            </div>

            <!-- Procedure, loaded on demand so the matrix payload stays small -->
            <div class="mt-4 max-h-52 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                <p v-if="loadingDetail" class="text-sm text-gray-400">Loading procedure…</p>
                <template v-else>
                    <div v-if="detail?.description" class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ detail.description }}</div>
                    <div v-if="detail?.steps">
                        <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Test Steps</h4>
                        <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800 dark:text-gray-100">{{ detail.steps }}</pre>
                    </div>
                    <div v-if="detail?.expected_results" class="mt-3">
                        <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Expected Results</h4>
                        <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800 dark:text-gray-100">{{ detail.expected_results }}</pre>
                    </div>
                    <p v-if="!detail?.steps && !detail?.expected_results && !detail?.description" class="text-sm text-gray-400">
                        No documented procedure for this item — it is a walkthrough check.
                    </p>
                </template>
            </div>

            <!-- One block per person behind the department -->
            <div class="mt-5 space-y-3">
                <div v-for="member in members" :key="member.id"
                     class="rounded-lg border p-3"
                     :class="member.id === editingId
                         ? 'border-blue-400 bg-blue-50/40 dark:border-blue-500/50 dark:bg-blue-500/5'
                         : 'border-gray-200 dark:border-gray-700'">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                              :class="member.role === 'approver'
                                  ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200'
                                  : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'">
                            {{ member.role === 'approver' ? 'Approver' : 'Tester' }}
                        </span>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ member.name }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                              :class="verdict(resultOf(member.id)?.result || 'pending').chip">
                            {{ verdict(resultOf(member.id)?.result || 'pending').label }}
                        </span>
                        <span v-if="resultOf(member.id)?.executed_at" class="text-xs text-gray-400">
                            {{ formatDateTime(resultOf(member.id).executed_at) }}
                        </span>

                        <button v-if="canRecord && member.id !== editingId" type="button" @click="startEditing(member.id)"
                                class="ml-auto rounded-lg border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ resultOf(member.id)?.result && resultOf(member.id).result !== 'pending' ? 'Change' : 'Record' }}
                        </button>
                    </div>

                    <p v-if="resultOf(member.id)?.remarks && member.id !== editingId"
                       class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">
                        <span class="font-semibold">Note:</span> {{ resultOf(member.id).remarks }}
                    </p>

                    <!-- Inline editor for this person's own answer -->
                    <div v-if="member.id === editingId" class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                        <div class="flex flex-wrap gap-2">
                            <button v-for="key in VERDICT_ORDER" :key="key" type="button" @click="form.result = key"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-semibold transition-all"
                                    :class="form.result === key
                                        ? verdict(key).solid + ' border-transparent shadow'
                                        : 'border-gray-300 bg-white text-gray-600 hover:-translate-y-0.5 hover:shadow dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300'">
                                <span aria-hidden="true">{{ verdict(key).glyph || '○' }}</span>
                                <span>{{ verdict(key).label }}</span>
                            </button>
                        </div>

                        <textarea v-model="form.remarks" rows="3"
                                  :placeholder="remarksRequired
                                      ? 'Describe what went wrong — this is what the fix will be built from.'
                                      : 'Optional note about what you saw.'"
                                  class="mt-3 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <InputError :message="errors.remarks" class="mt-1" />

                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" @click="editingId = null"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </button>
                            <button type="button" @click="save(member)" :disabled="saving"
                                    class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                                {{ saving ? 'Saving...' : 'Save' }}
                            </button>
                        </div>
                    </div>

                    <!-- Evidence for this person's answer -->
                    <div v-if="evidenceOf(member.id).length" class="mt-2">
                        <p class="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400">Screenshots</p>
                        <EvidenceGallery :items="evidenceOf(member.id)" size="sm" />
                    </div>

                    <div v-if="canRecord && resultOf(member.id)?.id" class="mt-2">
                        <input :ref="el => setUploadRef(member.id, el)" type="file" multiple
                               accept="image/*,.pdf,.doc,.docx,.xlsx,.txt,.log"
                               @change="uploadEvidence(member.id, $event)"
                               class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <button v-if="can('uat.execute')" type="button" @click="$emit('log-finding', testCase)"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-600 hover:underline dark:text-rose-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" />
                    </svg>
                    Log a finding
                </button>
                <span v-else></span>

                <button type="button" @click="$emit('close')"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Close
                </button>
            </div>

            <p v-if="!canRecord" class="mt-2 text-right text-xs text-amber-600 dark:text-amber-400">
                This cycle is closed to new verdicts.
            </p>
        </div>
    </Modal>
</template>

<script setup>
import { ref, reactive, computed, watch, inject } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import { verdict, VERDICT_ORDER, formatDateTime } from '../uatVerdict.js'
import { compressImages } from '@/Composables/useImageCompressor.js'
import EvidenceGallery from './EvidenceGallery.vue'

/**
 * The drill-down behind one DEPARTMENT cell of the matrix.
 *
 * The grid shows a single verdict per department; this is where the tester's and
 * the approver's own answers are shown separately and can each be edited. Their
 * records stay distinct — the approver's answer decides the column, it does not
 * overwrite the tester's.
 */
const props = defineProps({
    show: Boolean,
    cycle: Object,
    testCase: Object,
    column: Object,
    results: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'log-finding'])

const can = inject('uatCan', () => false)

const saving = ref(false)
const loadingDetail = ref(false)
const detail = ref(null)
const editingId = ref(null)
const evidenceByParticipant = ref({})
const uploadRefs = {}
const errors = reactive({ remarks: '' })

const form = reactive({ result: 'pending', remarks: '' })

const canRecord = computed(() =>
    can('uat.execute') && !['signed_off', 'cancelled'].includes(props.cycle?.status)
)

/** Approver first — they hold the decision. */
const members = computed(() => {
    const list = [...(props.column?.members || [])]
    return list.sort((a, b) => (a.role === 'approver' ? -1 : 0) - (b.role === 'approver' ? -1 : 0))
})

const resultOf = (participantId) =>
    (props.results || []).find(
        r => r.uat_case_id === props.testCase?.id && r.uat_participant_id === participantId
    ) || null

const evidenceOf = (participantId) => evidenceByParticipant.value[participantId] || []

const departmentVerdict = computed(() => {
    const approved = resultOf(props.column?.approver_id)
    if (approved && approved.result !== 'pending') return approved.result

    const others = (props.column?.member_ids || [])
        .filter(id => id !== props.column?.approver_id)
        .map(id => resultOf(id)?.result)
        .filter(Boolean)
        .filter(v => v !== 'not_applicable')

    if (!others.length) return 'pending'
    if (others.includes('failed')) return 'failed'
    if (others.includes('blocked')) return 'blocked'
    if (others.includes('pending')) return 'pending'
    if (others.includes('ongoing')) return 'ongoing'
    return 'passed'
})

const decidedByApprover = computed(() => {
    const approved = resultOf(props.column?.approver_id)
    return Boolean(approved && approved.result !== 'pending')
})

const remarksRequired = computed(() => ['failed', 'blocked'].includes(form.result))

const setUploadRef = (participantId, el) => { uploadRefs[participantId] = el }

const startEditing = (participantId) => {
    errors.remarks = ''
    const existing = resultOf(participantId)
    form.result = existing?.result || 'pending'
    form.remarks = existing?.remarks || ''
    editingId.value = participantId
}

watch(() => [props.show, props.testCase?.id, props.column?.key], async ([show]) => {
    if (!show || !props.testCase) return

    errors.remarks = ''
    editingId.value = null
    detail.value = null
    evidenceByParticipant.value = {}

    loadingDetail.value = true
    try {
        const response = await fetch(`/uat/${props.cycle.id}/cases/${props.testCase.id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        if (response.ok) {
            const payload = await response.json()
            detail.value = payload.case
            const map = {}
            for (const row of payload.results || []) {
                map[row.uat_participant_id] = row.evidence || []
            }
            evidenceByParticipant.value = map
        }
    } catch {
        detail.value = null
    } finally {
        loadingDetail.value = false
    }
}, { immediate: true })

const save = (member) => {
    errors.remarks = ''

    if (remarksRequired.value && !form.remarks.trim()) {
        errors.remarks = 'Describe what went wrong so the team can act on it.'
        return
    }

    saving.value = true

    router.post(`/uat/${props.cycle.id}/results`, {
        uat_case_id: props.testCase.id,
        // Recorded against this person, never merged with the other's answer.
        uat_participant_id: member.id,
        result: form.result,
        remarks: form.remarks || null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editingId.value = null },
        onError: (e) => { errors.remarks = e.remarks || '' },
        onFinish: () => { saving.value = false },
    })
}

const uploadEvidence = async (participantId, event) => {
    const resultId = resultOf(participantId)?.id
    if (!event.target.files?.length || !resultId) return

    const { files } = await compressImages(event.target.files)

    router.post(`/uat/${props.cycle.id}/evidence`, {
        uat_case_result_id: resultId,
        files,
    }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { if (uploadRefs[participantId]) uploadRefs[participantId].value = '' },
    })
}
</script>
