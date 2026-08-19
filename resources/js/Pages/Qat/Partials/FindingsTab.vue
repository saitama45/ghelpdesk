<template>
    <div class="space-y-4">
        <!-- Severity summary -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="key in ['blocker', 'major', 'minor', 'cosmetic']" :key="key"
                 class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between">
                    <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase" :class="SEVERITY_CHIPS[key]">{{ key }}</span>
                    <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ countBy(key) }}</span>
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ openBy(key) }} still open</div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-[12rem]">
                    <Autocomplete v-model="statusFilter" :options="statusFilterOptions" placeholder="All statuses" />
                </div>
                <div class="min-w-[12rem]">
                    <Autocomplete v-model="severityFilter" :options="severityFilterOptions" placeholder="All severities" />
                </div>
            </div>
            <button v-if="can('qat.execute')" @click="newFinding = true"
                    class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                Log a finding
            </button>
        </div>

        <div v-if="!visible.length" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ findings.length ? 'Nothing matches these filters.' : 'No findings logged yet.' }}
            </p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="finding in visible" :key="finding.id"
                 class="rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-800"
                 :class="finding.waived_at
                     ? 'border-amber-300 dark:border-amber-500/40'
                     : 'border-gray-200 dark:border-gray-700'">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="SEVERITY_CHIPS[finding.severity]">
                                {{ finding.severity }}
                            </span>
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="FINDING_STATUS_CHIPS[finding.status]">
                                {{ statusLabel(finding.status) }}
                            </span>
                            <span class="font-mono text-xs text-gray-400">{{ finding.reference }}</span>
                            <span v-if="finding.test_case" class="text-xs text-gray-500 dark:text-gray-400">
                                on {{ finding.test_case.case_key }}
                            </span>
                            <span v-if="finding.waived_at"
                                  class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                Waived
                            </span>
                        </div>

                        <h4 class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ finding.title }}</h4>
                        <p v-if="finding.details" class="mt-1 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{{ finding.details }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span v-if="finding.assignee">Assigned to {{ finding.assignee.name }}</span>
                            <span v-if="finding.reported_by_name">Reported by {{ finding.reported_by_name }}</span>
                            <a v-if="finding.ticket" :href="route('tickets.edit', finding.ticket.id)"
                               class="font-semibold text-indigo-600 hover:underline dark:text-indigo-300">
                                Ticket {{ finding.ticket.ticket_key }}
                            </a>
                        </div>

                        <!-- The manager's override stays on the record permanently -->
                        <p v-if="finding.waived_at" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                            <span class="font-semibold">Accepted under waiver</span>
                            <span v-if="finding.waived_by"> by {{ finding.waived_by.name }}</span>
                            <span> on {{ formatDateTime(finding.waived_at) }}</span>
                            <span v-if="finding.waiver_reason"> — “{{ finding.waiver_reason }}”</span>
                        </p>

                        <!-- Evidence is mandatory, so it is always worth showing -->
                        <div v-if="(finding.evidence || []).length" class="mt-2 flex flex-wrap gap-2">
                            <a v-for="shot in finding.evidence" :key="shot.id" :href="shot.url" target="_blank" rel="noopener"
                               class="block h-16 w-16 overflow-hidden rounded border border-gray-200 dark:border-gray-600">
                                <img v-if="shot.is_image" :src="shot.url" :alt="shot.file_name" class="h-full w-full object-cover">
                                <span v-else class="flex h-full w-full items-center justify-center bg-gray-50 text-[9px] text-gray-500 dark:bg-slate-900">file</span>
                            </a>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <QatIconBtn v-if="can('qat.edit')" kind="edit" title="Edit finding" @click="editing = finding" />
                        <QatIconBtn v-if="can('qat.edit') && !finding.ticket_id" kind="ticket" title="Raise a ticket" @click="openTicket(finding)" />
                        <QatIconBtn v-if="can('qat.delete')" kind="delete" title="Delete finding" @click="destroy(finding)" />
                    </div>
                </div>
            </div>
        </div>

        <FindingModal :show="newFinding" :cycle="cycle" :options="options" @close="newFinding = false" />
        <FindingModal :show="editing !== null" :cycle="cycle" :finding="editing" :options="options" @close="editing = null" />

        <!-- Convert to a helpdesk ticket -->
        <Modal :show="ticketFor !== null" @close="ticketFor = null" maxWidth="lg">
            <div v-if="ticketFor" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Raise a ticket</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Creates a helpdesk ticket for <span class="font-mono text-xs">{{ ticketFor.reference }}</span> and links the two.
                    The finding's screenshots are copied onto the ticket so whoever picks it up can see the defect.
                </p>

                <form @submit.prevent="submitTicket" class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Assign to</label>
                        <Autocomplete v-model="ticketForm.assignee_id" :options="options.users || []" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Serving department</label>
                        <Autocomplete v-model="ticketForm.serving_department_id" :options="options.departments || []" placeholder="Which desk fixes this?" />
                    </div>
                    <div v-if="!(ticketFor.evidence || []).length">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Screenshots <span class="text-rose-600">*</span>
                        </label>
                        <input type="file" multiple accept="image/*" @change="pickTicketShots"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-gray-300">
                        <InputError :message="ticketForm.errors.screenshots" class="mt-1" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="ticketFor = null"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="ticketForm.processing"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-50">
                            {{ ticketForm.processing ? 'Raising...' : 'Raise ticket' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, inject } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import FindingModal from './FindingModal.vue'
import QatIconBtn from './QatIconBtn.vue'
import { SEVERITY_CHIPS, FINDING_STATUS_CHIPS, formatDateTime } from '../qatVerdict'
import { compressImages } from '@/Composables/useImageCompressor.js'

const props = defineProps({
    cycle: { type: Object, required: true },
    findings: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const route = window.route
const can = inject('qatCan', () => false)

const statusFilter = ref('')
const severityFilter = ref('')
const newFinding = ref(false)
const editing = ref(null)
const ticketFor = ref(null)

const ticketForm = useForm({
    assignee_id: null,
    serving_department_id: null,
    screenshots: [],
})

const statusFilterOptions = computed(() => [
    { label: 'All statuses', value: '' },
    ...(props.options.findingStatuses || []),
])

const severityFilterOptions = computed(() => [
    { label: 'All severities', value: '' },
    ...(props.options.severities || []),
])

const visible = computed(() => props.findings.filter(f =>
    (!statusFilter.value || f.status === statusFilter.value) &&
    (!severityFilter.value || f.severity === severityFilter.value)
))

const countBy = (severity) => props.findings.filter(f => f.severity === severity).length
const openBy = (severity) => props.findings.filter(f =>
    f.severity === severity && ['open', 'in_progress', 'for_retest'].includes(f.status)
).length

const statusLabel = (value) =>
    (props.options.findingStatuses || []).find(s => s.value === value)?.label || value

const openTicket = (finding) => {
    ticketForm.reset()
    ticketForm.serving_department_id = finding.department_id ?? null
    ticketForm.assignee_id = finding.assigned_to_user_id ?? null
    ticketFor.value = finding
}

const pickTicketShots = async (event) => {
    if (!event.target.files?.length) return
    const { files } = await compressImages(event.target.files)
    ticketForm.screenshots = files
}

const submitTicket = () => {
    ticketForm.post(route('qat.findings.ticket', [props.cycle.id, ticketFor.value.id]), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { ticketFor.value = null },
    })
}

const destroy = (finding) => {
    if (!window.confirm(`Delete finding ${finding.reference}? Its screenshots go with it.`)) return
    router.delete(route('qat.findings.destroy', [props.cycle.id, finding.id]), {
        preserveScroll: true,
        preserveState: true,
    })
}
</script>
