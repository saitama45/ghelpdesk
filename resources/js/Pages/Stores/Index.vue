<template>
    <AppLayout title="Stores">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Store Management"
                    subtitle="Manage physical store locations and their details"
                    search-placeholder="Search stores by name, code, area..."
                    empty-message="No stores found. Create your first store to get started."
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
                        <div class="flex items-center space-x-2">
                            <button 
                                @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>Import</span>
                            </button>
                            <button 
                                v-if="hasPermission('stores.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>Create Store</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Store</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classification</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Techs</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Geofencing</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="store in data" :key="store.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ store.name }}</div>
                                        <div class="text-xs text-gray-500 font-mono tracking-tighter">{{ store.code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="space-y-1">
                                    <div class="flex items-center space-x-2">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-100">
                                            {{ store.area }}
                                        </span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-100">
                                            {{ store.brand }}
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="cluster in store.clusters" :key="cluster.id" class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-100">
                                            {{ cluster.name }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex -space-x-2 overflow-hidden">
                                    <div v-for="user in store.users" :key="user.id" 
                                         class="inline-block h-8 w-8 rounded-full ring-2 ring-white bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700"
                                         :title="user.name">
                                        {{ user.name.charAt(0) }}
                                    </div>
                                    <div v-if="store.users.length === 0" class="text-xs text-gray-400 italic">Unassigned</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="store.latitude && store.longitude" class="flex flex-col">
                                    <span class="text-xs font-medium text-gray-900 flex items-center">
                                        <svg class="w-3 h-3 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                        </svg>
                                        Active
                                    </span>
                                    <span class="text-[10px] text-gray-500">Radius: {{ store.radius_meters }}m</span>
                                </div>
                                <span v-else class="text-xs text-gray-400">Not set</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('stores.edit')"
                                        @click="editStore(store)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Store"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('stores.delete')"
                                        @click="deleteStore(store)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Store"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Store' : 'Create Store' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store Code</label>
                                <input v-model="form.code" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store Name</label>
                                <input v-model="form.name" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                                <input v-model="form.brand" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Area</label>
                                <input v-model="form.area" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sector</label>
                                <input v-model="form.sector" type="number" required min="1" max="8"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Class</label>
                                <select v-model="form.class" required
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option value="Regular">Regular</option>
                                    <option value="Kitchen">Kitchen</option>
                                    <option value="Office">Office</option>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Clusters</label>
                                <MultiAutocomplete
                                    v-model="form.cluster_ids"
                                    :options="clusters"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select one or more clusters..."
                                />
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assigned Technicians</label>
                                <MultiAutocomplete
                                    v-model="form.user_ids"
                                    :options="users"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select one or more technicians..."
                                />
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email Address</label>
                                <input v-model="form.email" type="email"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div class="p-4 bg-gray-50 rounded-xl border border-gray-100 md:col-span-2 space-y-4">
                                <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Geofencing (Optional)</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Latitude</label>
                                        <input v-model="form.latitude" type="number" step="0.00000001"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Longitude</label>
                                        <input v-model="form.longitude" type="number" step="0.00000001"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Radius (Meters)</label>
                                    <input v-model="form.radius_meters" type="number"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs"
                                           placeholder="Default: 150">
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center space-x-2">
                            <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <label for="is_active" class="text-sm text-gray-700 font-medium">Active Store</label>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                {{ isEditing ? 'Update Store' : 'Create Store' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showImportModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Import Stores</h3>
                        <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-6">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                            <h4 class="text-xs font-bold text-blue-700 uppercase tracking-wider mb-2">Instructions</h4>
                            <ul class="text-xs text-blue-600 space-y-1 list-disc pl-4">
                                <li>Download the template to ensure correct column mapping.</li>
                                <li>The "cluster" column can contain multiple cluster names/codes separated by semicolon (;).</li>
                                <li>The "users" column should contain technician emails separated by semicolon (;).</li>
                                <li>If a store code already exists, it will be updated.</li>
                            </ul>
                            <div class="mt-4">
                                <a :href="route('stores.template')" 
                                   class="text-xs font-black text-blue-700 underline hover:text-blue-800">
                                    Download Excel Template
                                </a>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block">
                                <span class="sr-only">Choose file</span>
                                <input type="file" @change="handleFileChange" accept=".xlsx,.csv"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer">
                            </label>

                            <div v-if="importResults" class="p-4 rounded-lg" :class="importResults.errors.length > 0 ? 'bg-amber-50' : 'bg-green-50'">
                                <p class="text-sm font-bold" :class="importResults.errors.length > 0 ? 'text-amber-800' : 'text-green-800'">
                                    Successfully imported {{ importResults.imported }} stores.
                                </p>
                                <div v-if="importResults.errors.length > 0" class="mt-2">
                                    <p class="text-xs font-black text-amber-700 uppercase mb-1">Issues encountered:</p>
                                    <ul class="text-[10px] text-amber-600 max-h-32 overflow-y-auto custom-scrollbar list-disc pl-4">
                                        <li v-for="(err, i) in importResults.errors" :key="i">{{ err }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showImportModal = false" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Close
                            </button>
                            <button @click="submitImport" :disabled="!selectedFile || importing"
                                    class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all disabled:opacity-50 flex items-center space-x-2">
                                <svg v-if="importing" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ importing ? 'Importing...' : 'Start Import' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    stores: Object,
    users: Array,
    clusters: Array,
    settings: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.stores, 'stores.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentStore = ref(null)
const importing = ref(false)
const selectedFile = ref(null)
const importResults = ref(null)

const form = reactive({
    code: '',
    name: '',
    brand: '',
    area: '',
    sector: 1,
    class: 'Regular',
    cluster_ids: [],
    user_ids: [],
    email: '',
    latitude: '',
    longitude: '',
    radius_meters: '',
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.stores)
})

watch(() => props.stores, (newStores) => {
    pagination.updateData(newStores)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentStore.value = null
    resetForm()
    showModal.value = true
}

const editStore = (store) => {
    isEditing.value = true
    currentStore.value = store
    form.code = store.code
    form.name = store.name
    form.brand = store.brand
    form.area = store.area
    form.sector = store.sector
    form.class = store.class
    form.cluster_ids = store.clusters ? store.clusters.map(c => c.id) : []
    form.user_ids = store.users ? store.users.map(u => u.id) : []
    form.email = store.email || ''
    form.latitude = store.latitude || ''
    form.longitude = store.longitude || ''
    form.radius_meters = store.radius_meters || ''
    form.is_active = !!store.is_active
    showModal.value = true
}

const resetForm = () => {
    form.code = ''
    form.name = ''
    form.brand = ''
    form.area = ''
    form.sector = 1
    form.class = 'Regular'
    form.cluster_ids = []
    form.user_ids = []
    form.email = ''
    form.latitude = ''
    form.longitude = ''
    form.radius_meters = ''
    form.is_active = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/stores/${currentStore.value.id}` : '/stores'
    const requestMethod = isEditing.value ? put : post

    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Store updated successfully' : 'Store created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteStore = async (store) => {
    const confirmed = await confirm({
        title: 'Delete Store',
        message: `Are you sure you want to delete "${store.name}"? This action cannot be undone.`
    })

    if (confirmed) {
        destroy(`/stores/${store.id}`, {
            onSuccess: () => showSuccess('Store deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete store'
                showError(errorMessage)
            }
        })
    }
}

const openImportModal = () => {
    selectedFile.value = null
    importResults.value = null
    showImportModal.value = true
}

const handleFileChange = (e) => {
    selectedFile.value = e.target.files[0]
}

const submitImport = async () => {
    if (!selectedFile.value) return

    importing.value = true
    importResults.value = null

    const formData = new FormData()
    formData.append('file', selectedFile.value)

    try {
        const response = await axios.post(route('stores.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        importResults.value = response.data
        if (response.data.imported > 0) {
            // Force reload stores to show new data
            post(route('stores.index'), {}, { preserveScroll: true, only: ['stores'] })
        }
    } catch (err) {
        showError(err.response?.data?.message || 'Import failed')
    } finally {
        importing.value = false
    }
}
</script>
