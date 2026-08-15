<template>
    <AppLayout title="UAT Tracker" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-8">
            <!-- Rows excluded by the entity filter. Without this, a cycle created
                 under another entity simply vanishes from the list. -->
            <div v-if="hiddenByEntity > 0"
                 class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>
                    <span class="font-semibold">{{ hiddenByEntity }}</span>
                    cycle{{ hiddenByEntity === 1 ? '' : 's' }} hidden by the entity filter —
                    they belong to a different entity than the one you are viewing.
                </span>
                <button @click="filters.company_id = 'all'"
                        class="ml-auto whitespace-nowrap rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-amber-700">
                    Show all entities
                </button>
            </div>

            <DataTable
                title="UAT Tracker"
                subtitle="Acceptance test cycles, the department/stakeholder verdict matrix, findings and sign-off — replacing the test-script workbook and the walkthrough checklist."
                search-placeholder="Search by cycle code, title or system..."
                empty-message="No UAT cycles yet. Create one, then import your existing test script or walkthrough checklist."
                :search="pagination.search.value"
                :data="pagination.data.value"
                :current-page="pagination.currentPage.value"
                :last-page="pagination.lastPage.value"
                :per-page="pagination.perPage.value"
                :showing-text="pagination.showingText.value"
                :is-loading="pagination.isLoading.value"
                @update:search="pagination.search.value = $event"
                @go-to-page="pagination.goToPage"
                @change-per-page="pagination.changePerPage"
            >
                <template #actions>
                    <div class="flex items-center gap-2 flex-nowrap">
                        <div class="w-44">
                            <Autocomplete v-model="filters.status" :options="statusFilterOptions" placeholder="All statuses" />
                        </div>
                        <div class="w-48">
                            <Autocomplete v-model="filters.company_id" :options="entityFilterOptions" placeholder="Entity" />
                        </div>
                        <a
                            v-if="hasPermission('uat.export')"
                            :href="route('uat.template')"
                            title="Download a blank import workbook in both supported layouts"
                            class="bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm whitespace-nowrap inline-flex items-center gap-2 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span>Template</span>
                        </a>
                        <button
                            v-if="hasPermission('uat.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm whitespace-nowrap inline-flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>New Cycle</span>
                        </button>
                    </div>
                </template>

                <template #header>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Cycle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Scope</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300 w-64">Progress</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Findings</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Target Sign-off</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="cycle in data" :key="cycle.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                        <td class="px-6 py-4">
                            <Link :href="route('uat.show', cycle.id)" class="text-sm font-semibold text-blue-700 hover:underline dark:text-blue-300">
                                {{ cycle.title }}
                            </Link>
                            <div class="mt-0.5 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-300">
                                <span class="font-mono">{{ cycle.code }}</span>
                                <span v-if="cycle.cycle_no" class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold dark:bg-gray-700">
                                    Cycle {{ cycle.cycle_no }}
                                </span>
                            </div>
                            <div v-if="cycle.system_name" class="text-xs text-gray-400 dark:text-gray-400">{{ cycle.system_name }}</div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-gray-100">{{ cycle.cases_count }} test case{{ cycle.cases_count === 1 ? '' : 's' }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-300">{{ cycle.environment }}</div>
                            <div v-if="cycle.company" class="text-xs text-gray-400 dark:text-gray-400">{{ cycle.company.name }}</div>
                        </td>

                        <td class="px-6 py-4">
                            <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="bg-emerald-500" :style="{ width: barWidth(cycle, 'passed') }"></div>
                                <div class="bg-rose-500" :style="{ width: barWidth(cycle, 'failed') }"></div>
                                <div class="bg-amber-500" :style="{ width: barWidth(cycle, 'blocked') }"></div>
                            </div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-medium">
                                <span class="text-emerald-600 dark:text-emerald-400">{{ summaryOf(cycle).passed }} passed</span>
                                <span class="text-rose-600 dark:text-rose-400">{{ summaryOf(cycle).failed }} failed</span>
                                <span v-if="summaryOf(cycle).blocked" class="text-amber-600 dark:text-amber-400">{{ summaryOf(cycle).blocked }} blocked</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ summaryOf(cycle).pending }} pending</span>
                            </div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <span v-if="summaryOf(cycle).open_findings"
                                  class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-1 text-xs font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-200">
                                {{ summaryOf(cycle).open_findings }} open
                            </span>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">None open</span>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wider" :class="statusClass(cycle.status)">
                                {{ statusLabel(cycle.status) }}
                            </span>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(cycle.target_signoff_date) }}</div>
                            <div v-if="cycle.qa_lead" class="text-xs text-gray-400 dark:text-gray-400">QA: {{ cycle.qa_lead.name }}</div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="flex justify-end space-x-1">
                                <Link :href="route('uat.show', cycle.id)" title="Open cycle"
                                      class="p-2 rounded-full transition-colors text-gray-600 hover:text-gray-900 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </Link>
                                <button v-if="hasPermission('uat.edit')" @click="openEditModal(cycle)" title="Edit cycle details"
                                        class="p-2 rounded-full transition-colors text-blue-600 hover:text-blue-900 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <a v-if="hasPermission('uat.export')" :href="route('uat.export', cycle.id)" title="Export workbook"
                                   class="p-2 rounded-full transition-colors text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                </a>
                                <button v-if="hasPermission('uat.create')" @click="openDuplicateModal(cycle)" title="Start a re-test round from this cycle"
                                        class="p-2 rounded-full transition-colors text-amber-600 hover:text-amber-900 hover:bg-amber-50 dark:hover:bg-amber-900/30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </button>
                                <button v-if="hasPermission('uat.delete')" @click="deleteCycle(cycle)" title="Delete cycle"
                                        class="p-2 rounded-full transition-colors text-red-600 hover:text-red-900 hover:bg-red-50 dark:hover:bg-red-900/30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <!-- Create / edit cycle (same form, shared component) -->
        <CycleFormModal
            :show="showModal"
            :cycle="editing"
            :options="formOptions"
            :default-company-id="activeCompanyId"
            @close="closeModal"
        />
        <!-- Duplicate for a re-test round -->
        <Modal :show="duplicateModal.open" @close="duplicateModal.open = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Start a Re-test Round</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Copies the sections, test cases and roster of
                    <span class="font-semibold">{{ duplicateModal.cycle?.code }}</span> into a new cycle with every verdict reset to pending.
                </p>

                <form @submit.prevent="submitDuplicate" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">New Cycle Title</label>
                        <input v-model="duplicateForm.title" type="text" required
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Round #</label>
                        <input v-model.number="duplicateForm.cycle_no" type="number" min="1" max="99" required
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="duplicateForm.copy_participants" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Copy the participant roster</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Access links are not copied — the new round issues its own.</span>
                        </span>
                    </label>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="duplicateModal.open = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="processing"
                                class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-amber-700 disabled:opacity-50">
                            {{ processing ? 'Copying...' : 'Create Round' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import CycleFormModal from './Partials/CycleFormModal.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    cycles: Object,
    hiddenByEntity: { type: Number, default: 0 },
    activeCompanyId: { type: Number, default: null },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    environments: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
})

const route = window.route
const { confirm } = useConfirm()
const { post, destroy } = useErrorHandler()
const pagination = usePagination(props.cycles, 'uat.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
// null = create mode; a cycle object = edit mode. Both use CycleFormModal.
const editing = ref(null)
const processing = ref(false)

const emptySummary = { passed: 0, failed: 0, blocked: 0, pending: 0, total: 0, open_findings: 0 }

const duplicateModal = reactive({ open: false, cycle: null })
const duplicateForm = reactive({ title: '', cycle_no: 2, copy_participants: true })

const filters = reactive({
    status: props.filters?.status ?? null,
    // Defaults to every entity — see the controller for why.
    company_id: props.filters?.company_id ?? 'all',
})

const statusFilterOptions = computed(() => [{ label: 'All statuses', value: null }, ...props.statuses])

const entityFilterOptions = computed(() => [
    { label: 'All entities', value: 'all' },
    { label: 'Active entity only', value: 'active' },
    ...props.companies.map(c => ({ label: c.label, value: String(c.value) })),
])

/** Option lists the shared cycle form needs. */
const formOptions = computed(() => ({
    statuses: props.statuses,
    environments: props.environments,
    companies: props.companies,
    departments: props.departments,
    users: props.users,
}))

onMounted(() => pagination.updateData(props.cycles))
watch(() => props.cycles, (value) => pagination.updateData(value), { deep: true })

watch(() => [filters.status, filters.company_id], () => {
    router.get('/uat', {
        status: filters.status || undefined,
        company_id: filters.company_id || undefined,
        search: pagination.search.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
})

const summaryOf = (cycle) => cycle.summary || emptySummary

const barWidth = (cycle, key) => {
    const summary = summaryOf(cycle)
    const total = summary.total || cycle.cases_count || 0
    if (!total) return '0%'
    return `${Math.round(((summary[key] || 0) / total) * 100)}%`
}

const statusLabel = (status) => props.statuses.find(s => s.value === status)?.label || status

const statusClass = (status) => ({
    draft: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    on_hold: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    completed: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
    signed_off: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200',
    cancelled: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
}[status] || 'bg-gray-100 text-gray-600')

const formatDate = (value) => {
    if (!value) return '—'
    const [year, month, day] = String(value).split('T')[0].split('-').map(Number)
    return new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const openCreateModal = () => {
    editing.value = null
    showModal.value = true
}

/** Same modal, seeded with the row — fixes a typo without touching any verdicts. */
const openEditModal = (cycle) => {
    editing.value = cycle
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    editing.value = null
}

const openDuplicateModal = (cycle) => {
    duplicateModal.cycle = cycle
    duplicateForm.title = `${cycle.title} (Round ${(cycle.cycle_no || 1) + 1})`
    duplicateForm.cycle_no = (cycle.cycle_no || 1) + 1
    duplicateForm.copy_participants = true
    duplicateModal.open = true
}

const submitDuplicate = () => {
    processing.value = true
    post(`/uat/${duplicateModal.cycle.id}/duplicate`, { ...duplicateForm }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { duplicateModal.open = false },
        onFinish: () => { processing.value = false },
    })
}

const deleteCycle = async (cycle) => {
    const ok = await confirm({
        title: 'Delete UAT Cycle',
        message: `Delete ${cycle.code} — "${cycle.title}"? Its test cases, verdicts, findings and sign-off records go with it. This cannot be undone.`,
        confirmLabel: 'Delete',
        variant: 'danger',
    })

    if (!ok) return

    destroy(`/uat/${cycle.id}`, { preserveScroll: true, preserveState: true })
}
</script>
