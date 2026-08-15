<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="3xl">
        <div v-if="testCase" class="p-6">
            <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-bold text-gray-400">{{ testCase.case_key }}</span>
                        <span v-if="!testCase.is_critical" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                            Non-critical
                        </span>
                        <span v-if="participant" class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
                            {{ participant.label }}
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

            <!-- Procedure, loaded on demand so the matrix payload stays small -->
            <div class="mt-4 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                <p v-if="loadingDetail" class="text-sm text-gray-400">Loading procedure…</p>
                <template v-else>
                    <div v-if="detail?.description" class="mb-3 text-sm text-gray-600 dark:text-gray-300">{{ detail.description }}</div>

                    <div v-if="steps.length">
                        <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Test Steps</h4>
                        <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800 dark:text-gray-100">{{ detail.steps }}</pre>
                    </div>

                    <div v-if="expected.length" class="mt-3">
                        <h4 class="mb-1 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Expected Results</h4>
                        <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-relaxed text-gray-800 dark:text-gray-100">{{ detail.expected_results }}</pre>
                    </div>

                    <p v-if="!steps.length && !expected.length && !detail?.description" class="text-sm text-gray-400">
                        No documented procedure for this item — it is a walkthrough check.
                    </p>
                </template>
            </div>

            <!-- Verdict -->
            <div class="mt-5">
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Your verdict</label>
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
            </div>

            <div class="mt-4">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Remarks
                    <span v-if="remarksRequired" class="text-rose-600">*</span>
                </label>
                <textarea v-model="form.remarks" rows="4"
                          :placeholder="remarksRequired
                              ? 'Describe what went wrong — this is what the fix will be built from.'
                              : 'Optional note about what you saw.'"
                          class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                <InputError :message="errors.remarks" class="mt-1" />
            </div>

            <!-- Evidence -->
            <div v-if="resultId" class="mt-4">
                <div class="mb-1 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Evidence</label>
                    <span class="text-xs text-gray-400">Screenshots replace the workbook's SS1…SSn sheet</span>
                </div>

                <div v-if="evidence.length" class="mb-2 flex flex-wrap gap-2">
                    <div v-for="file in evidence" :key="file.id"
                         class="group relative overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                        <a :href="file.url" target="_blank" rel="noopener" :title="file.file_name">
                            <img v-if="file.is_image" :src="file.url" :alt="file.file_name" class="h-20 w-28 object-cover" />
                            <div v-else class="flex h-20 w-28 items-center justify-center bg-gray-50 px-2 text-center text-[10px] text-gray-500 dark:bg-gray-900 dark:text-gray-300">
                                {{ file.file_name }}
                            </div>
                        </a>
                        <button v-if="can('uat.execute')" type="button" @click="removeEvidence(file)" title="Remove evidence"
                                class="absolute right-1 top-1 rounded-full bg-white/90 p-1 text-red-600 opacity-0 transition-opacity group-hover:opacity-100 dark:bg-gray-800/90">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <input ref="evidenceInput" type="file" multiple accept="image/*,.pdf,.doc,.docx,.xlsx,.txt,.log"
                       @change="uploadEvidence"
                       class="block w-full text-xs text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
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

                <div class="flex items-center gap-3">
                    <button type="button" @click="$emit('close')"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="button" @click="save" :disabled="saving || !canRecord"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ saving ? 'Saving...' : 'Save Verdict' }}
                    </button>
                </div>
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
import { verdict, VERDICT_ORDER, asLines } from '../uatVerdict.js'

const props = defineProps({
    show: Boolean,
    cycle: Object,
    testCase: Object,
    participant: Object,
    result: Object,
})

const emit = defineEmits(['close', 'log-finding'])

const can = inject('uatCan', () => false)

const saving = ref(false)
const loadingDetail = ref(false)
const detail = ref(null)
const evidence = ref([])
const errors = reactive({ remarks: '' })
const evidenceInput = ref(null)

const form = reactive({ result: 'pending', remarks: '' })

const resultId = computed(() => props.result?.id || null)
const canRecord = computed(() => props.cycle?.status !== 'signed_off' && props.cycle?.status !== 'cancelled')

const steps = computed(() => asLines(detail.value?.steps))
const expected = computed(() => asLines(detail.value?.expected_results))

// A failure with no explanation is the single most common way a UAT round
// stalls, so it is blocked at the point of entry.
const remarksRequired = computed(() => ['failed', 'blocked'].includes(form.result))

watch(() => [props.show, props.testCase?.id], async ([show]) => {
    if (!show || !props.testCase) return

    errors.remarks = ''
    form.result = props.result?.result || 'pending'
    form.remarks = props.result?.remarks || ''
    detail.value = null
    evidence.value = []

    loadingDetail.value = true
    try {
        const response = await fetch(`/uat/${props.cycle.id}/cases/${props.testCase.id}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        if (response.ok) {
            const payload = await response.json()
            detail.value = payload.case
            const mine = (payload.results || []).find(r => r.uat_participant_id === props.participant?.id)
            evidence.value = mine?.evidence || []
        }
    } catch {
        detail.value = null
    } finally {
        loadingDetail.value = false
    }
}, { immediate: true })

const save = () => {
    errors.remarks = ''

    if (remarksRequired.value && !form.remarks.trim()) {
        errors.remarks = 'Describe what went wrong so the team can act on it.'
        return
    }

    saving.value = true

    router.post(`/uat/${props.cycle.id}/results`, {
        uat_case_id: props.testCase.id,
        uat_participant_id: props.participant.id,
        result: form.result,
        remarks: form.remarks || null,
    }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (e) => { errors.remarks = e.remarks || '' },
        onFinish: () => { saving.value = false },
    })
}

const uploadEvidence = (event) => {
    const files = Array.from(event.target.files || [])
    if (!files.length || !resultId.value) return

    router.post(`/uat/${props.cycle.id}/evidence`, {
        uat_case_result_id: resultId.value,
        files,
    }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { if (evidenceInput.value) evidenceInput.value.value = '' },
    })
}

const removeEvidence = (file) => {
    router.delete(`/uat/${props.cycle.id}/evidence/${file.id}`, {
        preserveScroll: true,
        onSuccess: () => { evidence.value = evidence.value.filter(e => e.id !== file.id) },
    })
}
</script>
