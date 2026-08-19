<template>
    <AppLayout title="QAT Tracker" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">QAT Tracker</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-300">
                        Internal quality testing. Run the test script, log what is broken, then get your manager's
                        sign-off — a signed-off cycle is the one that can be promoted into a client-facing UAT.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button v-if="can('qat.create') && (uatCycles || []).length" @click="seedModal = true"
                            class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-medium text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15">
                        Re-test a UAT cycle
                    </button>
                    <button v-if="can('qat.create')" @click="createModal = true"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700">
                        New QAT cycle
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="mt-5 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="min-w-[16rem] flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Search</label>
                    <input v-model="search" type="search" placeholder="Title, code or system..." @keyup.enter="apply"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                </div>
                <div class="min-w-[12rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
                    <Autocomplete v-model="status" :options="statusOptions" placeholder="All statuses" />
                </div>
                <div class="min-w-[12rem]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Entity</label>
                    <Autocomplete v-model="companyId" :options="companyOptions" placeholder="All entities" />
                </div>
                <button @click="apply" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Apply
                </button>
            </div>

            <p v-if="hiddenByEntity > 0" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                {{ hiddenByEntity }} cycle(s) hidden by the entity filter.
            </p>

            <!-- Cards -->
            <div v-if="!cycles.data.length" class="mt-5 rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center dark:border-gray-600 dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">No QAT cycles yet.</p>
            </div>

            <div v-else class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="cycle in cycles.data" :key="cycle.id"
                     @click="open(cycle)"
                     class="cursor-pointer rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs text-gray-400">{{ cycle.code }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="STATUS_CHIPS[cycle.status]">
                                    {{ statusLabel(cycle.status) }}
                                </span>
                            </div>
                            <h3 class="mt-1 truncate font-bold text-gray-900 dark:text-gray-100">{{ cycle.title }}</h3>
                            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ cycle.system_name || '—' }} · {{ cycle.department?.name || 'Shared' }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1" @click.stop>
                            <QatIconBtn v-if="can('qat.edit')" kind="edit" title="Edit" @click="editing = cycle" />
                            <QatIconBtn v-if="can('qat.create')" kind="copy" title="Duplicate" @click="openDuplicate(cycle)" />
                            <QatIconBtn v-if="can('qat.delete')" kind="delete" title="Delete" @click="destroy(cycle)" />
                        </div>
                    </div>

                    <!-- Verdict bar -->
                    <div v-if="cycle.summary" class="mt-4">
                        <div class="flex h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="bg-emerald-500" :style="{ width: bar(cycle.summary.passed, cycle.summary.total) }"></div>
                            <div class="bg-rose-500" :style="{ width: bar(cycle.summary.failed, cycle.summary.total) }"></div>
                            <div class="bg-amber-500" :style="{ width: bar(cycle.summary.blocked, cycle.summary.total) }"></div>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ cycle.summary.passed }} passed</span>
                            <span>{{ cycle.summary.failed }} failed</span>
                            <span>{{ cycle.summary.pending }} pending</span>
                            <span v-if="cycle.summary.blocking_findings > 0" class="font-semibold text-rose-600 dark:text-rose-400">
                                {{ cycle.summary.blocking_findings }} blocking sign-off
                            </span>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-xs italic text-gray-400">
                        {{ cycle.cases_count }} case(s) · not started
                    </p>

                    <div v-if="cycle.promoted_uat_cycle" class="mt-3 inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                        → {{ cycle.promoted_uat_cycle.code }}
                    </div>
                </div>
            </div>

            <Pagination v-if="cycles.links?.length > 3" :links="cycles.links" class="mt-6" />
        </div>

        <CycleFormModal :show="createModal" :options="modalOptions" @close="createModal = false" />
        <CycleFormModal :show="editing !== null" :cycle="editing" :options="modalOptions" @close="editing = null" />

        <!-- Duplicate -->
        <Modal :show="duplicating !== null" @close="duplicating = null" maxWidth="lg">
            <form v-if="duplicating" @submit.prevent="submitDuplicate" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Duplicate cycle</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Copies the sections, test cases and roster into a fresh draft with empty verdicts.
                </p>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Title <span class="text-rose-600">*</span></label>
                        <input v-model="dupForm.title" type="text" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Round</label>
                        <input v-model.number="dupForm.cycle_no" type="number" min="1" max="99"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="dupForm.copy_participants" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Copy the tester roster too
                    </label>
                </div>
                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="duplicating = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" :disabled="dupForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Duplicate</button>
                </div>
            </form>
        </Modal>

        <!-- Seed from a UAT cycle -->
        <Modal :show="seedModal" @close="seedModal = false" maxWidth="lg">
            <form @submit.prevent="submitSeed" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Re-test a UAT cycle</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                    Copies a UAT cycle's sections and test cases into a new QAT cycle so the same script can be run
                    internally. Verdicts, findings and the stakeholder roster stay behind.
                </p>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Source UAT cycle <span class="text-rose-600">*</span></label>
                        <Autocomplete v-model="seedForm.uat_cycle_id" :options="uatCycles || []" placeholder="Select a UAT cycle..." />
                        <InputError :message="seedForm.errors.uat_cycle_id" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">New QAT cycle title <span class="text-rose-600">*</span></label>
                        <input v-model="seedForm.title" type="text" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="seedForm.errors.title" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Owning department</label>
                        <Autocomplete v-model="seedForm.department_id" :options="departments || []" placeholder="Same as the source" />
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="seedModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" :disabled="seedForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">Create</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import Pagination from '@/Components/Pagination.vue'
import CycleFormModal from './Partials/CycleFormModal.vue'
import QatIconBtn from './Partials/QatIconBtn.vue'
import { usePermission } from '@/Composables/usePermission'
import { STATUS_CHIPS } from './qatVerdict'

const props = defineProps({
    cycles: { type: Object, required: true },
    hiddenByEntity: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    environments: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    uatCycles: { type: Array, default: () => [] },
})

const route = window.route
const { hasPermission } = usePermission()
const can = (permission) => hasPermission(permission)

const search = ref(props.filters.search || '')
const status = ref(props.filters.status || '')
const companyId = ref(props.filters.company_id || 'all')

const createModal = ref(false)
const editing = ref(null)
const duplicating = ref(null)
const seedModal = ref(false)

const dupForm = useForm({ title: '', cycle_no: 2, copy_participants: true })
const seedForm = useForm({ uat_cycle_id: null, title: '', department_id: null })

const statusOptions = computed(() => [{ label: 'All statuses', value: '' }, ...props.statuses])

const companyOptions = computed(() => [
    { label: 'All entities', value: 'all' },
    { label: 'Active entity only', value: 'active' },
    ...props.companies,
])

const modalOptions = computed(() => ({
    statuses: props.statuses,
    environments: props.environments,
    companies: props.companies,
    departments: props.departments,
    users: props.users,
    uatCycles: props.uatCycles,
}))

const statusLabel = (value) => props.statuses.find(s => s.value === value)?.label || value

const bar = (value, total) => (total > 0 ? `${((value / total) * 100).toFixed(2)}%` : '0%')

const apply = () => {
    router.get(route('qat.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        company_id: companyId.value,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

const open = (cycle) => router.get(route('qat.show', cycle.id))

const openDuplicate = (cycle) => {
    dupForm.title = `${cycle.title} (round ${(cycle.cycle_no || 1) + 1})`
    dupForm.cycle_no = (cycle.cycle_no || 1) + 1
    dupForm.copy_participants = true
    duplicating.value = cycle
}

const submitDuplicate = () => {
    dupForm.post(route('qat.duplicate', duplicating.value.id), {
        onSuccess: () => { duplicating.value = null },
    })
}

const submitSeed = () => {
    seedForm.post(route('qat.from-uat'), {
        onSuccess: () => { seedModal.value = false },
    })
}

const destroy = (cycle) => {
    if (!window.confirm(`Delete ${cycle.code}? Its cases, verdicts and findings go with it.`)) return
    router.delete(route('qat.destroy', cycle.id), { preserveScroll: true })
}
</script>
