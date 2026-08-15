<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="min-w-[220px] flex-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Search</label>
                <input v-model="search" type="text" placeholder="Filter by reference, title or detail..."
                       class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="w-48">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</label>
                <Autocomplete v-model="statusFilter" :options="statusFilterOptions" placeholder="All statuses" />
            </div>
            <div class="w-48">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Severity</label>
                <Autocomplete v-model="severityFilter" :options="severityFilterOptions" placeholder="All severities" />
            </div>
            <button v-if="can('uat.execute') && cycleOpen" @click="openCreate"
                    class="ml-auto inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-rose-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Log Finding
            </button>
        </div>

        <!-- Severity summary -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="tile in severityTiles" :key="tile.key"
                 class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ tile.label }}</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ tile.open }}</span>
                    <span class="text-xs text-gray-400">open of {{ tile.total }}</span>
                </div>
            </div>
        </div>

        <div v-if="!visibleFindings.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ findings.length ? 'No findings match these filters.' : 'No findings logged yet.' }}
            </p>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Ref</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Finding</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Severity</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Owner</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Ticket</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-slate-300">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="finding in visibleFindings" :key="finding.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-bold text-gray-500 dark:text-gray-300">
                            {{ finding.reference }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ finding.title }}</div>
                            <div v-if="finding.test_case" class="text-xs text-gray-500 dark:text-gray-400">
                                {{ finding.test_case.case_key }} — {{ finding.test_case.title }}
                            </div>
                            <div v-if="finding.details" class="mt-0.5 line-clamp-2 text-xs text-gray-400">{{ finding.details }}</div>
                            <div v-if="finding.participant" class="mt-0.5 text-[11px] text-gray-400">
                                Reported by {{ finding.reported_by_name || finding.participant.label }}
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="SEVERITY_CHIPS[finding.severity]">
                                {{ severityLabel(finding.severity) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="FINDING_STATUS_CHIPS[finding.status]">
                                {{ statusLabel(finding.status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ finding.assignee?.name || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <Link v-if="finding.ticket" :href="`/tickets/${finding.ticket.id}/edit`"
                                  class="font-mono text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">
                                {{ finding.ticket.ticket_key }}
                            </Link>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <div class="flex justify-end space-x-1">
                                <UatIconBtn v-if="can('uat.edit')" kind="edit" title="Edit finding" @click="openEdit(finding)" />
                                <UatIconBtn v-if="can('uat.edit') && !finding.ticket" kind="ticket"
                                            title="Raise a helpdesk ticket for this finding" @click="openConvert(finding)" />
                                <UatIconBtn v-if="can('uat.delete')" kind="delete" title="Delete finding" @click="remove(finding)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <FindingModal
            :show="modal.open"
            :cycle="cycle"
            :cases="cases"
            :options="options"
            :finding="modal.finding"
            @close="modal.open = false"
        />

        <!-- Convert to ticket -->
        <Modal :show="convert.open" @close="convert.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Raise a Ticket</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Creates a helpdesk ticket for <span class="font-semibold">{{ convert.finding?.reference }}</span> and links the two.
                    Priority follows the severity, and the finding moves to In Progress.
                </p>

                <form @submit.prevent="submitConvert" class="mt-5 space-y-4">
                    <!-- Every ticket leaves with a screenshot. Findings logged
                         before that rule, or raised from the client portal, get
                         one attached here. -->
                    <div v-if="convertNeedsScreenshot" class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <label class="mb-1 block text-sm font-medium text-amber-900 dark:text-amber-100">
                            Screenshot required <span class="text-rose-600">*</span>
                        </label>
                        <p class="mb-2 text-xs text-amber-800 dark:text-amber-200">
                            This finding has no evidence attached. Add at least one screenshot so whoever picks up the ticket can see the defect.
                        </p>
                        <input ref="convertFileInput" type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp"
                               @change="onConvertFilesPicked"
                               class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-amber-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-amber-700 dark:text-gray-200">
                        <p v-if="convertCompressing" class="mt-1 text-xs font-medium text-blue-700 dark:text-blue-300">Resizing image(s)…</p>
                        <p v-for="(note, i) in convertNotes" :key="i" class="mt-0.5 text-xs text-amber-800 dark:text-amber-200">
                            Resized — {{ note }}
                        </p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                            Anything over {{ MAX_UPLOAD_MB }}&nbsp;MB is resized automatically.
                        </p>
                        <InputError :message="convert.error" class="mt-1" />
                    </div>

                    <p v-else class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                        {{ (convert.finding?.evidence || []).length }} screenshot(s) will be copied onto the ticket.
                    </p>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assign the ticket to</label>
                        <Autocomplete v-model="convertForm.assignee_id" :options="userOptions" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Serving Department</label>
                        <Autocomplete v-model="convertForm.serving_department_id" :options="departmentOptions" placeholder="Which desk delivers the fix..." />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="convert.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="convert.saving || convertCompressing"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-50">
                            {{ convertCompressing ? 'Resizing...' : (convert.saving ? 'Raising...' : 'Raise Ticket') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, inject } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import InputError from '@/Components/InputError.vue'
import UatIconBtn from './UatIconBtn.vue'
import FindingModal from './FindingModal.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { compressImages, MAX_UPLOAD_MB } from '@/Composables/useImageCompressor.js'
import { SEVERITY_CHIPS, FINDING_STATUS_CHIPS } from '../uatVerdict.js'

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
const { confirm } = useConfirm()

const cycleOpen = computed(() => !['signed_off', 'cancelled'].includes(props.cycle?.status))

const search = ref('')
const statusFilter = ref(null)
const severityFilter = ref(null)

const modal = reactive({ open: false, finding: null })
const convert = reactive({ open: false, finding: null, saving: false, error: '' })
const convertForm = reactive({ assignee_id: null, serving_department_id: null })
const convertFiles = ref([])
const convertFileInput = ref(null)
const convertCompressing = ref(false)
const convertNotes = ref([])

const onConvertFilesPicked = async (event) => {
    convertCompressing.value = true
    convertNotes.value = []

    try {
        const { files, notes } = await compressImages(event.target.files)
        convertFiles.value = files
        convertNotes.value = notes
    } finally {
        convertCompressing.value = false
    }
}

const convertNeedsScreenshot = computed(() => (convert.finding?.evidence || []).length === 0)

const statusFilterOptions = computed(() => [
    { label: 'All statuses', value: null },
    { label: 'Unresolved only', value: '__unresolved' },
    ...(props.options?.findingStatuses || []),
])

const severityFilterOptions = computed(() => [
    { label: 'All severities', value: null },
    ...(props.options?.severities || []),
])

const userOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.users || [])])
const departmentOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.departments || [])])

const severityLabel = (value) => (props.options?.severities || []).find(s => s.value === value)?.label || value
const statusLabel = (value) => (props.options?.findingStatuses || []).find(s => s.value === value)?.label || value

const UNRESOLVED = ['open', 'in_progress', 'for_retest']

const severityTiles = computed(() =>
    (props.options?.severities || []).map(severity => {
        const rows = (props.findings || []).filter(f => f.severity === severity.value)
        return {
            key: severity.value,
            label: severity.label,
            total: rows.length,
            open: rows.filter(f => UNRESOLVED.includes(f.status)).length,
        }
    })
)

const visibleFindings = computed(() => {
    const term = search.value.trim().toLowerCase()

    return (props.findings || []).filter(finding => {
        if (severityFilter.value && finding.severity !== severityFilter.value) return false

        if (statusFilter.value === '__unresolved') {
            if (!UNRESOLVED.includes(finding.status)) return false
        } else if (statusFilter.value && finding.status !== statusFilter.value) {
            return false
        }

        if (!term) return true

        return [finding.reference, finding.title, finding.details]
            .some(field => String(field || '').toLowerCase().includes(term))
    })
})

const openCreate = () => { modal.finding = null; modal.open = true }
const openEdit = (finding) => { modal.finding = finding; modal.open = true }

const openConvert = (finding) => {
    convert.finding = finding
    convert.error = ''
    convertFiles.value = []
    if (convertFileInput.value) convertFileInput.value.value = ''
    convertForm.assignee_id = finding.assigned_to_user_id ?? null
    convertForm.serving_department_id = finding.department_id ?? null
    convert.open = true
}

const submitConvert = () => {
    convert.error = ''

    if (convertNeedsScreenshot.value && convertFiles.value.length === 0) {
        convert.error = 'Attach at least one screenshot before raising the ticket.'
        return
    }

    convert.saving = true
    router.post(`/uat/${props.cycle.id}/findings/${convert.finding.id}/ticket`, {
        ...convertForm,
        screenshots: convertFiles.value,
    }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { convert.open = false },
        onError: (e) => { convert.error = e.screenshots || 'Could not raise the ticket.' },
        onFinish: () => { convert.saving = false },
    })
}

const remove = async (finding) => {
    const ok = await confirm({
        title: 'Delete Finding',
        message: `Delete ${finding.reference} — "${finding.title}"? Any evidence attached to it is removed too.`,
        confirmLabel: 'Delete',
        variant: 'danger',
    })

    if (!ok) return

    router.delete(`/uat/${props.cycle.id}/findings/${finding.id}`, { preserveScroll: true, preserveState: true })
}
</script>
