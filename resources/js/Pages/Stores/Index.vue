<template>
    <AppLayout title="Stores">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Store Management"
                    subtitle="Manage store locations and assigned users"
                    search-placeholder="Search stores by name, code, area, brand, cluster or user..."
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
                        <button 
                            v-if="hasPermission('stores.create')"
                            @click="openCreateModal" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Store</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Store</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sector/Area</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Brand/Cluster</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Geofence</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Users</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ticket Health</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="store in data" :key="store.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-orange-500 to-orange-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ store.name }}</div>
                                        <div class="text-xs font-bold text-blue-600">CODE: {{ store.code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">Sector: <span class="font-bold">{{ store.sector }}</span></div>
                                <div class="text-xs text-gray-500">{{ store.area }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ store.brand }}</div>
                                <div class="text-xs text-gray-500">Cluster: {{ store.cluster }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="store.latitude" class="text-xs space-y-1">
                                    <div class="flex items-center text-blue-600 font-medium">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        {{ store.radius_meters }}m Radius
                                    </div>
                                    <div class="text-gray-400 font-mono">{{ store.latitude.toFixed(4) }}, {{ store.longitude.toFixed(4) }}</div>
                                </div>
                                <span v-else class="text-[10px] text-orange-500 font-bold bg-orange-50 px-2 py-0.5 rounded uppercase">No Geofence</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button 
                                    v-if="store.users?.length > 0"
                                    @click="viewAssignedUsers(store)"
                                    class="text-blue-600 hover:text-blue-800 text-xs font-medium flex items-center"
                                >
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    View {{ store.users.length }} assigned User{{ store.users.length > 1 ? 's' : '' }}
                                </button>
                                <span v-else class="text-xs text-gray-400 italic">Unassigned</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center space-x-2">
                                    <div :class="[getHealthStatus(store.tickets_count).bg, 'px-2.5 py-1 rounded-full border flex items-center space-x-1.5 shadow-sm']">
                                        <div :class="[getHealthStatus(store.tickets_count).dot, 'w-2 h-2 rounded-full shadow-sm']"></div>
                                        <span class="text-xs font-bold uppercase tracking-tight">{{ getHealthStatus(store.tickets_count).label }}</span>
                                        <span class="text-[10px] font-medium opacity-75">({{ store.tickets_count }})</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="store.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" 
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ store.is_active ? 'Active' : 'Inactive' }}
                                </span>
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
        <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">
                        {{ isEditing ? 'Edit Store' : 'Create Store' }}
                    </h3>
                    <form @submit.prevent="submitForm">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Store Code</label>
                                <input v-model="form.code" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                       placeholder="e.g. STR-001">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Store Name</label>
                                <input v-model="form.name" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Store Email</label>
                                <input v-model="form.email" type="email"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                       placeholder="e.g. store@example.com">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 border-b pb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Assign Users</label>
                                <MultiAutocomplete 
                                    v-model="form.user_ids"
                                    :options="users"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select users..."
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Sector (1-8)</label>
                                <input v-model="form.sector" type="number" min="1" max="8" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>

                        <!-- Geofencing Section -->
                        <div class="mb-4 bg-blue-50 p-4 rounded-lg border border-blue-100">
                            <h4 class="text-sm font-bold text-blue-800 mb-3 flex items-center">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                Geofence Settings
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Latitude</label>
                                    <input v-model="form.latitude" type="number" step="any"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Longitude</label>
                                    <input v-model="form.longitude" type="number" step="any"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Radius (Meters)</label>
                                    <input v-model="form.radius_meters" type="number" min="10"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                </div>
                            </div>
                            <div class="mt-3 flex justify-between items-center">
                                <button type="button" @click="getCurrentLocation" 
                                        class="text-xs font-bold text-blue-600 hover:text-blue-800 flex items-center">
                                    <svg v-if="!isLocating" class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    <svg v-else class="animate-spin h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    {{ isLocating ? 'Locating...' : 'Set to Current Location' }}
                                </button>
                                <p class="text-[10px] text-blue-500 italic">Determines the allowed vicinity for DTR logging.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Area</label>
                                <input v-model="form.area" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                                <input v-model="form.brand" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cluster</label>
                                <input v-model="form.cluster" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div class="flex items-end pb-2">
                                <label class="flex items-center">
                                    <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700 font-medium">Active Store</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                                {{ isEditing ? 'Update Store' : 'Create Store' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Assigned Users Modal -->
        <div v-if="showUsersModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showUsersModal = false"></div>
                <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 relative">
                    <div class="flex items-center justify-between mb-4 border-b pb-2">
                        <h3 class="text-lg font-bold text-gray-900 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            Assigned Users
                        </h3>
                        <button @click="showUsersModal = false" class="text-gray-400 hover:text-gray-500">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div class="max-h-[60vh] overflow-y-auto">
                        <div v-if="selectedStoreUsers.length > 0" class="grid grid-cols-1 gap-2">
                            <div v-for="user in selectedStoreUsers" :key="user.id" 
                                 class="flex items-center p-3 bg-gray-50 rounded-lg border border-gray-100 hover:bg-blue-50 transition-colors"
                            >
                                <div class="h-10 w-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-sm mr-3">
                                    <span class="text-sm font-medium text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-gray-900">{{ user.name }}</div>
                                    <div class="text-[10px] text-gray-500">{{ user.email }}</div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-8 text-gray-500 italic">
                            No users assigned to this store.
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button @click="showUsersModal = false" 
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors text-sm font-medium">
                            Close
                        </button>
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

const props = defineProps({
    stores: Object,
    users: Array,
    settings: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.stores, 'stores.index')
const { hasPermission } = usePermission()

const getHealthStatus = (ticketCount) => {
    const s = props.settings || {};
    
    // Default values if settings are not set
    const thresholds = {
        green: { min: parseInt(s.threshold_green_min) || 1, max: parseInt(s.threshold_green_max) || 2, label: s.threshold_green_label || 'Healthy', dot: 'bg-green-500', bg: 'bg-green-50 text-green-700 border-green-100' },
        yellow: { min: parseInt(s.threshold_yellow_min) || 3, max: parseInt(s.threshold_yellow_max) || 3, label: s.threshold_yellow_label || 'Warning', dot: 'bg-yellow-500', bg: 'bg-yellow-50 text-yellow-700 border-yellow-100' },
        orange: { min: parseInt(s.threshold_orange_min) || 4, max: parseInt(s.threshold_orange_max) || 4, label: s.threshold_orange_label || 'At-risk', dot: 'bg-orange-500', bg: 'bg-orange-50 text-orange-700 border-orange-100' },
        red: { min: parseInt(s.threshold_red_min) || 5, label: s.threshold_red_label || 'Critical', dot: 'bg-red-500', bg: 'bg-red-50 text-red-700 border-red-100' }
    };

    if (ticketCount >= thresholds.red.min) return thresholds.red;
    if (ticketCount >= thresholds.orange.min && (thresholds.orange.max ? ticketCount <= thresholds.orange.max : true)) return thresholds.orange;
    if (ticketCount >= thresholds.yellow.min && (thresholds.yellow.max ? ticketCount <= thresholds.yellow.max : true)) return thresholds.yellow;
    if (ticketCount >= thresholds.green.min && (thresholds.green.max ? ticketCount <= thresholds.green.max : true)) return thresholds.green;
    
    return { label: 'Clear', dot: 'bg-gray-300', bg: 'bg-gray-50 text-gray-600 border-gray-100' };
};

const showModal = ref(false)
const isEditing = ref(false)
const showUsersModal = ref(false)
const currentStore = ref(null)
const selectedStoreUsers = ref([])
const isLocating = ref(false)

const form = reactive({
    user_ids: [],
    code: '',
    name: '',
    email: '',
    sector: 1,
    area: '',
    brand: '',
    cluster: '',
    latitude: null,
    longitude: null,
    radius_meters: 150,
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
    Object.assign(form, {
        user_ids: [],
        code: '',
        name: '',
        email: '',
        sector: 1,
        area: '',
        brand: '',
        cluster: '',
        latitude: null,
        longitude: null,
        radius_meters: 150,
        is_active: true
    })
    showModal.value = true
}

const editStore = (store) => {
    isEditing.value = true
    currentStore.value = store
    Object.assign(form, {
        user_ids: store.users?.map(u => u.id) || [],
        code: store.code,
        name: store.name,
        email: store.email || '',
        sector: store.sector,
        area: store.area,
        brand: store.brand,
        cluster: store.cluster,
        latitude: store.latitude,
        longitude: store.longitude,
        radius_meters: store.radius_meters || 150,
        is_active: store.is_active
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const viewAssignedUsers = (store) => {
    selectedStoreUsers.value = store.users || []
    showUsersModal.value = true
}

const getCurrentLocation = () => {
    if (!navigator.geolocation) {
        showError('Geolocation is not supported by your browser.')
        return
    }

    isLocating.value = true
    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.latitude = position.coords.latitude
            form.longitude = position.coords.longitude
            isLocating.value = false
            showSuccess('Coordinates updated successfully.')
        },
        (error) => {
            isLocating.value = false
            showError('Error getting location: ' + error.message)
        },
        { enableHighAccuracy: true }
    )
}

const submitForm = () => {
    const url = isEditing.value ? `/stores/${currentStore.value.id}` : '/stores'
    const requestMethod = isEditing.value ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
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
            onSuccess: () => {},
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete store'
                showError(errorMessage)
            }
        })
    }
}
</script>
