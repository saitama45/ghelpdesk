<template>
    <div class="space-y-5">
        <!-- Gate -->
        <div class="rounded-xl border p-5 shadow-sm" :class="gateClass">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-bold">{{ gateHeadline }}</h3>
                    <ul v-if="gateReasons.length" class="mt-2 space-y-1 text-sm">
                        <li v-for="(reason, index) in gateReasons" :key="index" class="flex items-start gap-2">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60"></span>
                            <span>{{ reason }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-sm">
                        Every gate has cleared. The final sign-off can be recorded.
                    </p>
                </div>

                <button v-if="can('uat.approve')" @click="finalModal.open = true"
                        class="shrink-0 whitespace-nowrap rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700">
                    Record Final Sign-off
                </button>
            </div>
        </div>

        <!-- Final sign-off record -->
        <div v-if="finalSignoff" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Final Sign-off</h3>
            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide" :class="SIGNOFF_CHIPS[finalSignoff.result]">
                    {{ signoffLabel(finalSignoff.result) }}
                </span>
                <span class="text-gray-700 dark:text-gray-200">
                    {{ finalSignoff.confirmed_name || finalSignoff.confirmed_by?.name }}
                </span>
                <span class="text-gray-500 dark:text-gray-400">{{ formatDateTime(finalSignoff.confirmed_at) }}</span>
            </div>
            <p v-if="finalSignoff.remarks" class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ finalSignoff.remarks }}</p>
        </div>

        <!-- Acceptance roster -->
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">User Acceptance</h3>
                <p class="mt-0.5 text-xs text-gray-400">
                    One row per nominated approver. Re-signing appends a new record rather than overwriting the old one.
                </p>
            </div>

            <p v-if="!approvers.length" class="px-5 py-10 text-center text-sm text-gray-400">
                No approvers nominated yet. In the Setup tab, set a participant's role to “Approver”.
            </p>

            <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Department / Stakeholder</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Date Confirmed</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Overall Result</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="approver in approvers" :key="approver.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ approver.label }}</div>
                            <div class="text-xs text-gray-400">
                                {{ approver.kind === 'stakeholder' ? 'Client / Stakeholder' : 'Department' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ approver.display_name || '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-300">{{ approver.display_email || '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ formatDateTime(acceptance[approver.id]?.confirmed_at) }}
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="acceptance[approver.id]"
                                  class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                                  :class="SIGNOFF_CHIPS[acceptance[approver.id].result]">
                                {{ signoffLabel(acceptance[approver.id].result) }}
                            </span>
                            <span v-else class="rounded-full bg-gray-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                Pending
                            </span>
                            <p v-if="acceptance[approver.id]?.remarks" class="mt-1 max-w-md text-xs text-gray-500 dark:text-gray-400">
                                {{ acceptance[approver.id].remarks }}
                            </p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <div class="flex justify-end space-x-1">
                                <UatIconBtn v-if="can('uat.signoff')" kind="edit"
                                            :title="acceptance[approver.id] ? 'Re-record acceptance' : 'Record acceptance'"
                                            @click="openAcceptance(approver)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Audit trail -->
        <div v-if="signoffs.length" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sign-off History</h3>
            <ul class="mt-3 space-y-2.5">
                <li v-for="record in signoffs" :key="record.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="SIGNOFF_CHIPS[record.result]">
                        {{ signoffLabel(record.result) }}
                    </span>
                    <span class="font-medium text-gray-800 dark:text-gray-100">
                        {{ record.stage === 'final' ? 'Final sign-off' : labelForParticipant(record.uat_participant_id) }}
                    </span>
                    <span class="text-gray-500 dark:text-gray-400">
                        by {{ record.confirmed_name || record.confirmed_by?.name || 'Unknown' }}
                    </span>
                    <span class="text-gray-400">{{ formatDateTime(record.confirmed_at) }}</span>
                    <span v-if="!record.is_current" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                        Superseded
                    </span>
                </li>
            </ul>
        </div>

        <!-- Record acceptance -->
        <Modal :show="acceptModal.open" @close="acceptModal.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Record Acceptance</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    On behalf of <span class="font-semibold">{{ acceptModal.approver?.label }}</span>.
                    Your account and the time are stamped on the record.
                </p>

                <form @submit.prevent="submitAcceptance" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Overall result</label>
                        <div class="space-y-2">
                            <label v-for="option in (options.signoffResults || [])" :key="option.value"
                                   class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                                   :class="acceptForm.result === option.value
                                       ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-500/10'
                                       : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700'">
                                <input v-model="acceptForm.result" :value="option.value" type="radio" class="mt-0.5 border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ option.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Remarks <span v-if="acceptForm.result !== 'passed'" class="text-rose-600">*</span>
                        </label>
                        <textarea v-model="acceptForm.remarks" rows="3"
                                  :placeholder="acceptForm.result === 'passed'
                                      ? 'Optional note.'
                                      : 'State the reservation, or why this is not accepted.'"
                                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <InputError :message="acceptModal.error" class="mt-1" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="acceptModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="acceptModal.saving"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ acceptModal.saving ? 'Recording...' : 'Record Acceptance' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Final sign-off -->
        <Modal :show="finalModal.open" @close="finalModal.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Final Sign-off</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Closes the cycle. Accepting is blocked until every gate clears; recording a rejection is always allowed.
                </p>

                <div v-if="gateReasons.length" class="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Still outstanding:</p>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="(reason, index) in gateReasons" :key="index">• {{ reason }}</li>
                    </ul>
                </div>

                <form @submit.prevent="submitFinal" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Result</label>
                        <div class="space-y-2">
                            <label v-for="option in (options.signoffResults || [])" :key="option.value"
                                   class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                                   :class="finalForm.result === option.value
                                       ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-500/10'
                                       : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700'">
                                <input v-model="finalForm.result" :value="option.value" type="radio" class="mt-0.5 border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ option.label }}</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Remarks</label>
                        <textarea v-model="finalForm.remarks" rows="3"
                                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        <InputError :message="finalModal.error" class="mt-1" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="finalModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="finalModal.saving"
                                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-emerald-700 disabled:opacity-50">
                            {{ finalModal.saving ? 'Recording...' : 'Record Sign-off' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { reactive, computed, inject } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import UatIconBtn from './UatIconBtn.vue'
import { SIGNOFF_CHIPS, formatDateTime } from '../uatVerdict.js'

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

const acceptModal = reactive({ open: false, approver: null, saving: false, error: '' })
const acceptForm = reactive({ result: 'passed', remarks: '' })
const finalModal = reactive({ open: false, saving: false, error: '' })
const finalForm = reactive({ result: 'passed', remarks: '' })

const approvers = computed(() =>
    (props.participants || []).filter(p => p.is_active && p.role === 'approver')
)

const finalSignoff = computed(() =>
    (props.signoffs || []).find(s => s.stage === 'final' && s.is_current) || null
)

const signoffLabel = (value) =>
    (props.options?.signoffResults || []).find(o => o.value === value)?.label || value

const labelForParticipant = (id) =>
    (props.participants || []).find(p => p.id === id)?.label || 'Participant'

const gateReasons = computed(() => {
    const out = []
    const r = props.readiness || {}

    if (r.outstanding_cases?.length) {
        out.push(`${r.outstanding_cases.length} ${r.gate_on_critical_only ? 'critical ' : ''}test case(s) not yet cleared.`)
    }
    if (r.blocking_findings?.length) {
        out.push(`${r.blocking_findings.length} blocker or major finding(s) still unresolved.`)
    }
    if (r.pending_approvers?.length) {
        out.push(`Awaiting acceptance from: ${r.pending_approvers.map(a => a.label).join(', ')}.`)
    }
    if (!approvers.value.length) {
        out.push('No approvers nominated — add at least one in the Setup tab.')
    }

    return out
})

const gateHeadline = computed(() => {
    if (props.cycle?.status === 'signed_off') return 'This cycle has been signed off.'
    return props.readiness?.is_ready ? 'Ready for final sign-off.' : 'Not ready for final sign-off.'
})

const gateClass = computed(() => {
    if (props.cycle?.status === 'signed_off' || props.readiness?.is_ready) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'
    }
    return 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100'
})

const openAcceptance = (approver) => {
    acceptModal.approver = approver
    acceptModal.error = ''
    const existing = props.acceptance?.[approver.id]
    acceptForm.result = existing?.result || 'passed'
    acceptForm.remarks = existing?.remarks || ''
    acceptModal.open = true
}

const submitAcceptance = () => {
    acceptModal.error = ''

    if (acceptForm.result !== 'passed' && !acceptForm.remarks.trim()) {
        acceptModal.error = 'Explain the reservation or the reason for not accepting.'
        return
    }

    acceptModal.saving = true
    router.post(`/uat/${props.cycle.id}/signoff`, {
        uat_participant_id: acceptModal.approver.id,
        result: acceptForm.result,
        remarks: acceptForm.remarks || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { acceptModal.open = false },
        onError: (e) => { acceptModal.error = e.remarks || e.result || 'Could not record that.' },
        onFinish: () => { acceptModal.saving = false },
    })
}

const submitFinal = () => {
    finalModal.error = ''
    finalModal.saving = true

    router.post(`/uat/${props.cycle.id}/final-signoff`, { ...finalForm }, {
        preserveScroll: true,
        onSuccess: () => { finalModal.open = false },
        onError: (e) => { finalModal.error = e.result || e.remarks || 'Could not record the sign-off.' },
        onFinish: () => { finalModal.saving = false },
    })
}
</script>
