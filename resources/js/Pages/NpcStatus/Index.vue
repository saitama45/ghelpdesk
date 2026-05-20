<template>
    <AppLayout title="NPC Status">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-5 rounded-2xl border border-gray-200 bg-white/90 px-4 py-3 shadow-sm">
                    <div class="flex justify-center overflow-x-auto custom-scrollbar">
                        <div class="inline-flex min-w-max items-center gap-2 rounded-xl bg-gray-100 p-1.5">
                            <button
                                v-for="tab in statusTabs"
                                :key="tab.value || 'all'"
                                type="button"
                                @click="selectStatus(tab.value)"
                                :class="[
                                    'group min-w-[118px] rounded-lg px-4 py-2.5 text-center transition-all duration-200',
                                    selectedStatus === tab.value
                                        ? 'bg-blue-600 text-white shadow-md shadow-blue-100'
                                        : 'bg-transparent text-gray-500 hover:bg-white hover:text-gray-900 hover:shadow-sm'
                                ]"
                            >
                                <div class="text-[10px] font-black uppercase tracking-widest">{{ tab.label }}</div>
                                <div class="mt-1 flex items-baseline justify-center gap-1">
                                    <span class="text-xl font-black leading-none">{{ tab.stores }}</span>
                                    <span class="text-[9px] font-black uppercase tracking-wider opacity-75">Stores</span>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <DataTable
                    title="NPC Status Per Entity"
                    subtitle="Track yearly NPC validity, store assignments, and DPO attachments."
                    search-placeholder="Search entity or status..."
                    empty-message="No entities found for this filter."
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
                        <div class="flex items-center gap-2">
                            <label class="text-xs font-black uppercase tracking-widest text-gray-500">Year</label>
                            <input
                                v-model.number="selectedYear"
                                type="number"
                                min="2000"
                                max="2100"
                                class="w-24 rounded-lg border-gray-300 text-sm font-bold focus:border-blue-500 focus:ring-blue-500"
                                @change="refreshYear"
                            >
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stores Assigned</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Validity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">DPO Seal</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">DPO Registration</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="company in data" :key="company.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-slate-600 to-blue-700 rounded-full flex items-center justify-center shadow-sm">
                                        <span class="text-white text-xs font-black">{{ company.code?.slice(0, 2) || 'NP' }}</span>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-bold text-gray-900">{{ company.name }}</div>
                                        <div class="flex items-center gap-2 text-xs text-gray-500">
                                            <span class="font-mono">{{ company.code }}</span>
                                            <span :class="company.is_active ? 'text-green-600' : 'text-red-600'" class="font-bold">
                                                {{ company.is_active ? 'Active Entity' : 'Inactive Entity' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <button
                                    type="button"
                                    @click="openStoreModal(company)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-black text-blue-700 transition-colors hover:bg-blue-100"
                                >
                                    <span>{{ company.store_count }}</span>
                                    <span class="text-[10px] uppercase tracking-widest">Stores</span>
                                </button>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="company.npc_status" class="text-sm">
                                    <div class="font-bold text-gray-900">
                                        {{ formatDate(company.npc_status.validity_from) }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        to {{ formatDate(company.npc_status.validity_to) }}
                                    </div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400">Not set</span>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    v-if="company.npc_status"
                                    :class="statusBadgeClass(company.npc_status.status)"
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-black"
                                >
                                    {{ company.npc_status.status }}
                                </span>
                                <span v-else class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-black text-gray-500">
                                    No Record
                                </span>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <a
                                    v-if="company.npc_status?.dpo_seal"
                                    :href="company.npc_status.dpo_seal.url"
                                    class="text-sm font-bold text-blue-600 hover:text-blue-800 hover:underline"
                                >
                                    {{ company.npc_status.dpo_seal.name || 'Download Seal' }}
                                </a>
                                <span v-else class="text-xs font-bold text-gray-400">No file</span>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <a
                                    v-if="company.npc_status?.dpo_registration"
                                    :href="company.npc_status.dpo_registration.url"
                                    class="text-sm font-bold text-blue-600 hover:text-blue-800 hover:underline"
                                >
                                    {{ company.npc_status.dpo_registration.name || 'Download Registration' }}
                                </a>
                                <span v-else class="text-xs font-bold text-gray-400">No file</span>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="canSaveRecord(company)"
                                        type="button"
                                        @click="openStatusModal(company)"
                                        class="rounded-full p-2 text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-900"
                                        :title="company.npc_status ? 'Edit NPC Status' : 'Create NPC Status'"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="company.npc_status && hasPermission('npc_status.delete')"
                                        type="button"
                                        @click="deleteRecord(company)"
                                        class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900"
                                        title="Delete NPC Status"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <div v-if="showStatusModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeStatusModal"></div>
                <div class="relative w-full max-w-2xl rounded-xl border border-gray-100 bg-white p-6 shadow-2xl">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">{{ selectedCompany?.name }}</h3>
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">NPC Status {{ selectedYear }}</p>
                        </div>
                        <button @click="closeStatusModal" class="text-gray-400 transition-colors hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form class="space-y-5" @submit.prevent="submitStatus">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Validity From</label>
                                <input
                                    v-model="statusForm.validity_from"
                                    type="date"
                                    required
                                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Validity To</label>
                                <input
                                    v-model="statusForm.validity_to"
                                    type="date"
                                    required
                                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Status</label>
                                <select
                                    v-model="statusForm.status"
                                    required
                                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">DPO Seal</label>
                                <input
                                    :key="fileInputKey + '-seal'"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-full file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100"
                                    @change="setFile('dpo_seal', $event)"
                                >
                                <p v-if="selectedCompany?.npc_status?.dpo_seal" class="mt-1 truncate text-xs font-medium text-gray-500">
                                    Current: {{ selectedCompany.npc_status.dpo_seal.name }}
                                </p>
                                <p class="mt-1 text-xs font-medium text-gray-400">PDF/image, max 1GB.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">DPO Registration</label>
                                <input
                                    :key="fileInputKey + '-registration'"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-full file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100"
                                    @change="setFile('dpo_registration', $event)"
                                >
                                <p v-if="selectedCompany?.npc_status?.dpo_registration" class="mt-1 truncate text-xs font-medium text-gray-500">
                                    Current: {{ selectedCompany.npc_status.dpo_registration.name }}
                                </p>
                                <p class="mt-1 text-xs font-medium text-gray-400">PDF/image, max 1GB.</p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 border-t pt-6">
                            <button type="button" @click="closeStatusModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-200">
                                Cancel
                            </button>
                            <button type="submit" :disabled="isSavingStatus" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md transition-all hover:bg-blue-700 disabled:opacity-50">
                                {{ isSavingStatus ? 'Saving...' : 'Save NPC Status' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div v-if="showStoreModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeStoreModal"></div>
                <div class="relative flex max-h-[88vh] w-full max-w-4xl flex-col rounded-xl border border-gray-100 bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b p-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">{{ selectedCompany?.name }}</h3>
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">Assigned Stores for {{ selectedYear }}</p>
                        </div>
                        <button @click="closeStoreModal" class="text-gray-400 transition-colors hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="border-b bg-gray-50 p-4">
                        <input
                            v-model="storeSearch"
                            type="text"
                            placeholder="Search stores by name, code, area, or brand..."
                            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        <div class="mt-3 flex justify-center overflow-x-auto">
                            <div class="inline-flex min-w-max gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200">
                                <button
                                    v-for="tab in storeAssignmentTabs"
                                    :key="tab.value"
                                    type="button"
                                    @click="storeAssignmentTab = tab.value"
                                    :class="[
                                        'rounded-md px-3 py-1.5 text-xs font-black uppercase tracking-wider transition-colors',
                                        storeAssignmentTab === tab.value
                                            ? 'bg-blue-600 text-white shadow-sm'
                                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800'
                                    ]"
                                >
                                    {{ tab.label }}
                                    <span class="ml-1 rounded-full px-1.5 py-0.5 text-[10px]" :class="storeAssignmentTab === tab.value ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600'">
                                        {{ tab.count }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-4">
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                            <label
                                v-for="store in filteredStores"
                                :key="store.id"
                                class="flex items-start gap-3 rounded-lg border p-3 transition-colors"
                                :class="isStoreDisabled(store) ? 'border-gray-200 bg-gray-50 opacity-60' : 'border-gray-200 bg-white hover:bg-blue-50'"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    :value="store.id"
                                    v-model="selectedStoreIds"
                                    :disabled="isStoreDisabled(store)"
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-bold text-gray-900">{{ store.name }}</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ store.code }} - {{ store.area }} - {{ store.brand }}
                                    </span>
                                    <span v-if="isStoreDisabled(store)" class="mt-1 block text-[11px] font-bold text-amber-700">
                                        Assigned to {{ store.assigned_company_name }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <div v-if="filteredStores.length === 0" class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-10 text-center">
                            <p class="text-sm font-bold text-gray-500">No stores found for this tab.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t bg-white p-4">
                        <p class="text-sm font-bold text-gray-600">{{ selectedStoreIds.length }} selected</p>
                        <div class="flex gap-3">
                            <button type="button" @click="closeStoreModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-200">
                                Cancel
                            </button>
                            <button
                                type="button"
                                :disabled="isSavingStores || !hasPermission('npc_status.edit')"
                                @click="saveStores"
                                class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md transition-all hover:bg-blue-700 disabled:opacity-50"
                            >
                                {{ isSavingStores ? 'Saving...' : 'Save Stores' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    npcStatuses: Object,
    filters: Object,
    statuses: Array,
    statusCounts: Object,
    stores: Array,
})

const { confirm } = useConfirm()
const { hasPermission } = usePermission()
const { showSuccess, showError } = useToast()

const selectedYear = ref(props.filters?.year || new Date().getFullYear())
const selectedStatus = ref(props.filters?.status || '')
const pagination = usePagination(props.npcStatuses, 'npc-statuses.index', () => {
    const params = { year: selectedYear.value }

    if (selectedStatus.value) {
        params.status = selectedStatus.value
    }

    return params
})

const showStatusModal = ref(false)
const showStoreModal = ref(false)
const selectedCompany = ref(null)
const fileInputKey = ref(0)
const isSavingStatus = ref(false)
const isSavingStores = ref(false)
const storeSearch = ref('')
const storeAssignmentTab = ref('all')
const selectedStoreIds = ref([])

const statusForm = reactive({
    company_id: null,
    year: selectedYear.value,
    validity_from: '',
    validity_to: '',
    status: 'Pending',
    dpo_seal: null,
    dpo_registration: null,
})

onMounted(() => {
    pagination.perPage.value = props.filters?.per_page || props.npcStatuses?.per_page || 10
    pagination.search.value = props.filters?.search || ''
    pagination.updateData(props.npcStatuses)
})

watch(() => props.npcStatuses, (newData) => {
    pagination.updateData(newData)
}, { deep: true })

watch(() => props.filters?.year, (year) => {
    if (year) selectedYear.value = year
})

watch(() => props.filters?.status, (status) => {
    selectedStatus.value = status || ''
})

const statusTabs = computed(() => {
    const counts = props.statusCounts || {}
    const allStores = Object.values(counts).reduce((sum, count) => sum + Number(count?.stores || 0), 0)

    return [
        { label: 'All', value: '', stores: allStores },
        ...(props.statuses || []).map((status) => ({
            label: status,
            value: status,
            stores: Number(counts[status]?.stores || 0),
        })),
    ]
})

const selectStatus = (status) => {
    if (selectedStatus.value === status) return
    selectedStatus.value = status
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const refreshYear = () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const canSaveRecord = (company) => {
    return company.npc_status ? hasPermission('npc_status.edit') : hasPermission('npc_status.create')
}

const openStatusModal = (company) => {
    selectedCompany.value = company
    statusForm.company_id = company.id
    statusForm.year = selectedYear.value
    statusForm.validity_from = company.npc_status?.validity_from || ''
    statusForm.validity_to = company.npc_status?.validity_to || ''
    statusForm.status = company.npc_status?.status || 'Pending'
    statusForm.dpo_seal = null
    statusForm.dpo_registration = null
    fileInputKey.value++
    showStatusModal.value = true
}

const closeStatusModal = () => {
    showStatusModal.value = false
    selectedCompany.value = null
    statusForm.company_id = null
    statusForm.validity_from = ''
    statusForm.validity_to = ''
    statusForm.status = 'Pending'
    statusForm.dpo_seal = null
    statusForm.dpo_registration = null
    fileInputKey.value++
}

const setFile = (field, event) => {
    statusForm[field] = event.target.files?.[0] || null
}

const submitStatus = () => {
    if (!selectedCompany.value) return

    isSavingStatus.value = true
    const record = selectedCompany.value.npc_status
    const url = record ? route('npc-statuses.update', record.id) : route('npc-statuses.store')
    const payload = {
        company_id: statusForm.company_id,
        year: statusForm.year,
        validity_from: statusForm.validity_from,
        validity_to: statusForm.validity_to,
        status: statusForm.status,
        dpo_seal: statusForm.dpo_seal,
        dpo_registration: statusForm.dpo_registration,
    }

    if (record) {
        payload._method = 'put'
    }

    router.post(url, payload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeStatusModal()
            showSuccess('NPC Status saved successfully')
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => {
            isSavingStatus.value = false
        },
    })
}

const deleteRecord = async (company) => {
    if (!company.npc_status) return

    const ok = await confirm({
        title: 'Delete NPC Status',
        message: `Delete NPC Status for ${company.name} in ${selectedYear.value}? Store tags and uploaded files will be removed.`,
    })

    if (!ok) return

    router.delete(route('npc-statuses.destroy', company.npc_status.id), {
        preserveScroll: true,
        onSuccess: () => showSuccess('NPC Status deleted successfully'),
        onError: (errors) => showError(errorText(errors)),
    })
}

const openStoreModal = (company) => {
    if (!company.npc_status) {
        showError('Save validity dates and status before tagging stores.')
        return
    }

    selectedCompany.value = company
    selectedStoreIds.value = (props.stores || [])
        .filter((store) => store.assigned_npc_status_id === company.npc_status.id)
        .map((store) => store.id)
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    showStoreModal.value = true
}

const closeStoreModal = () => {
    showStoreModal.value = false
    selectedCompany.value = null
    selectedStoreIds.value = []
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
}

const saveStores = () => {
    if (!selectedCompany.value?.npc_status) return

    isSavingStores.value = true
    router.put(route('npc-statuses.stores.update', selectedCompany.value.npc_status.id), {
        store_ids: selectedStoreIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeStoreModal()
            showSuccess('Assigned stores updated successfully')
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => {
            isSavingStores.value = false
        },
    })
}

const filteredStores = computed(() => {
    const search = storeSearch.value.trim().toLowerCase()
    const stores = props.stores || []

    return stores.filter((store) => {
        const isAssigned = selectedStoreIds.value.includes(store.id)

        if (storeAssignmentTab.value === 'assigned' && !isAssigned) {
            return false
        }

        if (storeAssignmentTab.value === 'unassigned' && isAssigned) {
            return false
        }

        if (!search) {
            return true
        }

        return [
            store.name,
            store.code,
            store.area,
            store.brand,
            store.assigned_company_name,
        ].filter(Boolean).some((value) => String(value).toLowerCase().includes(search))
    })
})

const storeAssignmentTabs = computed(() => {
    const stores = props.stores || []
    const assignedCount = stores.filter((store) => selectedStoreIds.value.includes(store.id)).length

    return [
        { label: 'All', value: 'all', count: stores.length },
        { label: 'Checked', value: 'assigned', count: assignedCount },
        { label: 'Unchecked', value: 'unassigned', count: Math.max(0, stores.length - assignedCount) },
    ]
})

const isStoreDisabled = (store) => {
    const currentRecordId = selectedCompany.value?.npc_status?.id
    return Boolean(store.assigned_npc_status_id && store.assigned_npc_status_id !== currentRecordId)
}

const formatDate = (value) => {
    if (!value) return 'Not set'

    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    })
}

const statusBadgeClass = (status) => {
    return {
        Active: 'bg-green-100 text-green-800',
        Inactive: 'bg-red-100 text-red-800',
        Approved: 'bg-blue-100 text-blue-800',
        Pending: 'bg-amber-100 text-amber-800',
        'For Payment': 'bg-purple-100 text-purple-800',
    }[status] || 'bg-gray-100 text-gray-700'
}

const errorText = (errors) => {
    return Object.values(errors || {}).flat().join(', ') || 'Unable to save changes.'
}
</script>
