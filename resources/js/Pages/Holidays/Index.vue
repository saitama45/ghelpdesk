<template>
    <AppLayout title="Holidays" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-8">
            <DataTable
                title="Philippine Holidays"
                subtitle="Regular, special and custom-declared holidays. Non-working dates are skipped when scheduling project timelines."
                search-placeholder="Search by holiday name or description..."
                empty-message="No holidays found for this filter."
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
                        <div class="w-40">
                            <Autocomplete v-model="filters.type" :options="typeFilterOptions" placeholder="All types" />
                        </div>
                        <div class="w-28">
                            <Autocomplete v-model="filters.year" :options="yearFilterOptions" placeholder="Year" />
                        </div>
                        <button
                            v-if="hasPermission('holidays.create')"
                            @click="generateYear"
                            :disabled="!selectedYearLabel"
                            :title="selectedYearLabel ? `Generate the movable holidays for ${selectedYearLabel}` : 'Pick a specific year first'"
                            class="bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm whitespace-nowrap inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span>Generate {{ selectedYearLabel || '' }}</span>
                        </button>
                        <button
                            v-if="hasPermission('holidays.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm whitespace-nowrap inline-flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Add Holiday</span>
                        </button>
                    </div>
                </template>

                <template #header>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Holiday</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Repeats</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="holiday in data" :key="holiday.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ holiday.name }}</div>
                            <div v-if="holiday.description" class="text-xs text-gray-500 dark:text-gray-300">{{ holiday.description }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(holiday) }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-400">{{ weekdayFor(holiday) }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-full" :class="typeClass(holiday.type)">
                                {{ typeLabel(holiday.type) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                            {{ holiday.is_recurring ? 'Every year' : 'This date only' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                  :class="holiday.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                {{ holiday.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="flex justify-end space-x-1">
                                <button v-if="hasPermission('holidays.edit')" @click="editHoliday(holiday)" title="Edit Holiday"
                                        class="p-2 rounded-full transition-colors text-blue-600 hover:text-blue-900 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button v-if="hasPermission('holidays.delete')" @click="deleteHoliday(holiday)" title="Delete Holiday"
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

        <Modal :show="showModal" @close="closeModal" maxWidth="lg">
            <div class="p-6">
                <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ isEditing ? 'Edit Holiday' : 'Add Holiday' }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                            Non-working holidays are skipped alongside weekends when project timelines are scheduled.
                        </p>
                    </div>
                    <button type="button" @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="mt-6 space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-200">Holiday Name</label>
                        <input v-model="form.name" type="text" required placeholder="e.g. Typhoon Suspension"
                               class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-200">Type</label>
                            <Autocomplete v-model="form.type" :options="typeOptions" placeholder="Select type..." />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-200">Date</label>
                            <input v-model="form.date" type="date" required
                                   class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-200">Description</label>
                        <input v-model="form.description" type="text" placeholder="Optional note — e.g. Proclamation No. 1234"
                               class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>

                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 cursor-pointer dark:border-gray-700">
                        <input v-model="form.is_recurring" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Repeats every year</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-300">
                                Tick for fixed-date holidays (Jan 1, Dec 25). Leave off for movable ones like Holy Week, Eid, or a one-off suspension.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-100">Active</span>
                    </label>

                    <div v-if="isSpecialWorking" class="rounded-lg bg-amber-50 p-3 text-xs font-medium text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                        Special <em>working</em> days stay ordinary working days — this one will NOT be skipped in project timelines.
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <button type="button" @click="closeModal"
                                class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            Cancel
                        </button>
                        <button type="submit"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            {{ isEditing ? 'Update Holiday' : 'Create Holiday' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    holidays: Object,
    filters: { type: Object, default: () => ({}) },
    holidayTypes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
})

const { showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.holidays, 'holidays.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentHoliday = ref(null)

const form = reactive({
    name: '',
    type: 'regular',
    date: '',
    is_recurring: false,
    is_active: true,
    description: '',
})

const typeOptions = computed(() => props.holidayTypes)
const typeFilterOptions = computed(() => [{ label: 'All types', value: null }, ...props.holidayTypes])

// Year values are strings so the 'all' opt-out can sit alongside real years.
const yearFilterOptions = computed(() => [
    { label: 'All years', value: 'all' },
    ...props.years.map(year => ({ label: year.label, value: String(year.value) })),
])

// The page opens on the current year — the controller defaults it, so
// props.filters.year is always populated.
const filters = reactive({
    type: props.filters?.type ?? null,
    year: props.filters?.year ? String(props.filters.year) : String(new Date().getFullYear()),
})

const selectedYearLabel = computed(() => (filters.year === 'all' ? null : filters.year))

const isSpecialWorking = computed(() => form.type === 'special_working')

onMounted(() => pagination.updateData(props.holidays))

watch(() => props.holidays, (value) => pagination.updateData(value), { deep: true })

watch(() => [filters.type, filters.year], () => {
    router.get('/holidays', {
        type: filters.type || undefined,
        year: filters.year || undefined,
        search: pagination.search.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
})

const typeLabel = (type) => props.holidayTypes.find(option => option.value === type)?.label || type

const typeClass = (type) => ({
    regular: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
    special_non_working: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    special_working: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
    custom: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
}[type] || 'bg-gray-100 text-gray-600')

// A recurring holiday's stored year is meaningless — show just the month/day.
const formatDate = (holiday) => {
    const date = parseDate(holiday.date)
    if (!date) return '-'

    return holiday.is_recurring
        ? date.toLocaleDateString(undefined, { month: 'long', day: 'numeric' })
        : date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}

const weekdayFor = (holiday) => {
    if (holiday.is_recurring) return 'Annual'
    const date = parseDate(holiday.date)
    return date ? date.toLocaleDateString(undefined, { weekday: 'long' }) : ''
}

const parseDate = (value) => {
    if (!value) return null
    const [year, month, day] = String(value).split('T')[0].split('-').map(Number)
    return new Date(year, month - 1, day)
}

const openCreateModal = () => {
    isEditing.value = false
    currentHoliday.value = null
    form.name = ''
    form.type = 'custom'
    form.date = ''
    form.is_recurring = false
    form.is_active = true
    form.description = ''
    showModal.value = true
}

const editHoliday = (holiday) => {
    isEditing.value = true
    currentHoliday.value = holiday
    form.name = holiday.name
    form.type = holiday.type
    form.date = String(holiday.date).split('T')[0]
    form.is_recurring = Boolean(holiday.is_recurring)
    form.is_active = Boolean(holiday.is_active)
    form.description = holiday.description || ''
    showModal.value = true
}

const closeModal = () => { showModal.value = false }

const submitForm = () => {
    const url = isEditing.value ? `/holidays/${currentHoliday.value.id}` : '/holidays'
    const requestMethod = isEditing.value ? put : post

    requestMethod(url, form, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
    })
}

// Fixed-date holidays already repeat every year on their own — only Holy Week
// and National Heroes Day move, and both are computable.
const generateYear = async () => {
    if (!selectedYearLabel.value) {
        showError('Pick a specific year first — "All years" has nothing to generate.')
        return
    }

    const year = selectedYearLabel.value
    const confirmed = await confirm({
        title: `Generate ${year} Holidays`,
        message: `Add Maundy Thursday, Good Friday, Black Saturday and National Heroes Day for ${year}. Fixed-date holidays like New Year and Christmas already repeat automatically. Existing rows are left alone; Eid'l Fitr, Eid'l Adha and Chinese New Year still need the official proclamation.`,
        confirmLabel: 'Generate',
        variant: 'primary',
    })

    if (!confirmed) return

    post('/holidays/generate', { year }, {
        preserveScroll: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
    })
}

const deleteHoliday = async (holiday) => {
    const confirmed = await confirm({
        title: 'Delete Holiday',
        message: `Delete "${holiday.name}"? Project timelines will start counting this date as a working day again.`,
        confirmLabel: 'Delete',
        variant: 'danger',
    })

    if (!confirmed) return

    destroy(`/holidays/${holiday.id}`, {
        preserveScroll: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
    })
}
</script>
