<template>
    <AppLayout title="UAT Tracker" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-8">
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

        <!-- Create cycle -->
        <Modal :show="showModal" @close="closeModal" maxWidth="3xl">
            <div class="p-6">
                <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">New UAT Cycle</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                            A cycle is one round of acceptance testing. Add the roster and test cases once it exists — or import them from an existing workbook.
                        </p>
                    </div>
                    <button type="button" @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="mt-6 space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Cycle Title</label>
                            <input v-model="form.title" type="text" required placeholder="e.g. System Testing of Planning Website"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="errors.title" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Round #</label>
                            <input v-model.number="form.cycle_no" type="number" min="1" max="99" required
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">System Under Test</label>
                            <input v-model="form.system_name" type="text" placeholder="e.g. Planning Service Website"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Environment</label>
                            <Autocomplete v-model="form.environment" :options="environments" placeholder="Select environment..." />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Entity</label>
                            <Autocomplete v-model="form.company_id" :options="companyOptions" placeholder="Select entity..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Owning Department</label>
                            <Autocomplete v-model="form.department_id" :options="departmentOptions" placeholder="Select department..." />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assigned QA</label>
                            <Autocomplete v-model="form.qa_lead_id" :options="userOptions" placeholder="Search user..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assigned Dev</label>
                            <Autocomplete v-model="form.dev_lead_id" :options="userOptions" placeholder="Search user..." />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Start Date</label>
                            <input v-model="form.start_date" type="date"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Target Sign-off</label>
                            <input v-model="form.target_signoff_date" type="date"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <InputError :message="errors.target_signoff_date" class="mt-1" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Go-Live</label>
                            <input v-model="form.go_live_date" type="date"
                                   class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Environment Links</label>
                            <button type="button" @click="addLink" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">+ Add link</button>
                        </div>
                        <div v-for="(link, index) in form.links" :key="index" class="mb-2 flex items-center gap-2">
                            <input v-model="link.label" type="text" placeholder="Label (e.g. Front-end)"
                                   class="w-1/3 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <input v-model="link.url" type="url" placeholder="https://..."
                                   class="flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <button type="button" @click="form.links.splice(index, 1)" title="Remove link"
                                    class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900 dark:hover:bg-red-900/30">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <p v-if="!form.links.length" class="text-xs text-gray-400 dark:text-gray-500">
                            The URLs testers should open — they appear at the top of every tester's screen.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                        <textarea v-model="form.description" rows="2" placeholder="Scope of this round, what changed since the last cycle..."
                                  class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <input v-model="form.signoff_requires_all" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Require every approver</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Final sign-off stays locked until all nominated approvers accept.</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <input v-model="form.gate_on_critical_only" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Gate on critical items only</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Non-critical cases are reported but never block go-live.</span>
                            </span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="closeModal"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="processing"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                            {{ processing ? 'Creating...' : 'Create Cycle' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

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
import InputError from '@/Components/InputError.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    cycles: Object,
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
const processing = ref(false)
const errors = ref({})

const emptySummary = { passed: 0, failed: 0, blocked: 0, pending: 0, total: 0, open_findings: 0 }

const form = reactive({
    title: '',
    system_name: '',
    description: '',
    cycle_no: 1,
    environment: 'Web',
    links: [],
    company_id: null,
    department_id: null,
    qa_lead_id: null,
    dev_lead_id: null,
    status: 'draft',
    start_date: '',
    target_signoff_date: '',
    go_live_date: '',
    signoff_requires_all: true,
    gate_on_critical_only: true,
})

const duplicateModal = reactive({ open: false, cycle: null })
const duplicateForm = reactive({ title: '', cycle_no: 2, copy_participants: true })

const filters = reactive({
    status: props.filters?.status ?? null,
    company_id: props.filters?.company_id ?? 'active',
})

const statusFilterOptions = computed(() => [{ label: 'All statuses', value: null }, ...props.statuses])

const entityFilterOptions = computed(() => [
    { label: 'Active entity', value: 'active' },
    { label: 'All entities', value: 'all' },
    ...props.companies.map(c => ({ label: c.label, value: String(c.value) })),
])

const companyOptions = computed(() => [{ label: '—', value: null }, ...props.companies])
const departmentOptions = computed(() => [{ label: '—', value: null }, ...props.departments])
const userOptions = computed(() => [{ label: '—', value: null }, ...props.users])

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
    errors.value = {}
    Object.assign(form, {
        title: '', system_name: '', description: '', cycle_no: 1, environment: 'Web', links: [],
        company_id: null, department_id: null, qa_lead_id: null, dev_lead_id: null,
        status: 'draft', start_date: '', target_signoff_date: '', go_live_date: '',
        signoff_requires_all: true, gate_on_critical_only: true,
    })
    showModal.value = true
}

const closeModal = () => { showModal.value = false }

const addLink = () => form.links.push({ label: '', url: '' })

const submitForm = () => {
    processing.value = true
    errors.value = {}

    post('/uat', {
        ...form,
        // Blank rows would otherwise fail the url rule on an empty string.
        links: form.links.filter(l => l.url),
        start_date: form.start_date || null,
        target_signoff_date: form.target_signoff_date || null,
        go_live_date: form.go_live_date || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false },
        onError: (e) => { errors.value = e },
        onFinish: () => { processing.value = false },
    })
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

    destroy(`/uat/${cycle.id}`, { preserveScroll: true })
}
</script>
