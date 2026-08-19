<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="2xl">
        <div v-if="testCase && column" class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <span class="font-mono text-xs text-gray-400">{{ testCase.case_key }}</span>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ testCase.title }}</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-300">{{ column.label }}</p>
                </div>
                <span class="shrink-0 rounded px-2 py-1 text-xs font-bold" :class="verdict(columnResult).chip">
                    {{ verdict(columnResult).label }}
                </span>
            </div>

            <p class="mt-3 rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-slate-900 dark:text-gray-300">
                Each person records their own answer and both are kept. The
                <span class="font-semibold">reviewer's</span> answer is the department's decision; the tester's
                stands in until the reviewer gives one.
            </p>

            <!-- Reviewer first: theirs is the one that counts -->
            <div class="mt-4 space-y-4">
                <div v-for="member in orderedMembers" :key="member.id"
                     class="rounded-lg border p-4"
                     :class="member.role === 'reviewer'
                         ? 'border-blue-300 bg-blue-50/40 dark:border-blue-500/40 dark:bg-blue-500/5'
                         : 'border-gray-200 dark:border-gray-700'">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ member.name }}</span>
                            <span class="ml-2 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                  :class="member.role === 'reviewer'
                                      ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-200'
                                      : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                {{ member.role }}
                            </span>
                        </div>
                        <span class="text-xs text-gray-400">{{ formatDateTime(rowFor(member.id)?.executed_at) }}</span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <button v-for="key in VERDICT_ORDER" :key="key"
                                type="button"
                                :disabled="!editable || saving"
                                @click="save(member.id, key)"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                :class="rowFor(member.id)?.result === key
                                    ? verdict(key).solid
                                    : 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-300 dark:hover:bg-gray-700'">
                            {{ verdict(key).glyph }} {{ verdict(key).short }}
                        </button>
                    </div>

                    <textarea v-model="remarks[member.id]" rows="2" :disabled="!editable"
                              placeholder="Remarks (required for a failure or a block)"
                              @blur="saveRemarks(member.id)"
                              class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                </div>
            </div>

            <p v-if="!editable" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                This cycle is closed to new verdicts.
            </p>

            <div class="mt-5 flex justify-end border-t border-gray-200 pt-4 dark:border-gray-700">
                <button type="button" @click="$emit('close')"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Close
                </button>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import { verdict, VERDICT_ORDER, columnVerdict, formatDateTime } from '../qatVerdict'

const props = defineProps({
    show: { type: Boolean, default: false },
    cycle: { type: Object, required: true },
    testCase: { type: Object, default: null },
    column: { type: Object, default: null },
    results: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    editable: { type: Boolean, default: true },
})

defineEmits(['close'])

const route = window.route

// Declared before the watch that reads it — a watch getter runs immediately, and
// referencing a const declared lower down throws in the temporal dead zone and
// blanks the entire page.
const remarks = ref({})
const saving = ref(false)

const caseResults = computed(() =>
    props.testCase ? props.results.filter(r => r.qat_case_id === props.testCase.id) : []
)

const columnResult = computed(() =>
    props.column ? columnVerdict(caseResults.value, props.column) : 'pending'
)

/** Reviewer first — theirs is the answer that decides the column. */
const orderedMembers = computed(() => {
    const members = [...(props.column?.members || [])]
    return members.sort((a, b) => (a.role === 'reviewer' ? -1 : b.role === 'reviewer' ? 1 : 0))
})

const rowFor = (participantId) =>
    caseResults.value.find(r => r.qat_participant_id === participantId)

watch(() => [props.show, props.testCase?.id, props.column?.key], () => {
    const next = {}
    for (const member of props.column?.members || []) {
        next[member.id] = rowFor(member.id)?.remarks || ''
    }
    remarks.value = next
})

const post = (participantId, result) => {
    saving.value = true
    router.post(route('qat.results.store', props.cycle.id), {
        qat_case_id: props.testCase.id,
        qat_participant_id: participantId,
        result,
        remarks: remarks.value[participantId] || null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { saving.value = false },
    })
}

const save = (participantId, result) => post(participantId, result)

const saveRemarks = (participantId) => {
    const row = rowFor(participantId)
    if (!row || (row.remarks || '') === (remarks.value[participantId] || '')) return
    post(participantId, row.result)
}
</script>
