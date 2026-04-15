<template>
    <AppLayout title="Clusters">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Cluster Management"
                    subtitle="Manage cluster references in the system"
                    search-placeholder="Search clusters by code or name..."
                    empty-message="No clusters found. Create your first cluster to get started."
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
                            v-if="hasPermission('clusters.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Cluster</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cluster Name</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="cluster in data" :key="cluster.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                    {{ cluster.code }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-indigo-500 to-cyan-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M6 11h12M9 15h6M10 19h4" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ cluster.name }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ cluster.stores?.length || 0 }} assigned store{{ (cluster.stores?.length || 0) !== 1 ? 's' : '' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button
                                        v-if="hasPermission('clusters.edit')"
                                        @click="openAssignStoresModal(cluster)"
                                        class="p-2 text-emerald-600 hover:text-emerald-900 hover:bg-emerald-50 rounded-full transition-colors"
                                        title="Assign Stores"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5V4H2v16h5m10 0v-2a2 2 0 00-2-2H9a2 2 0 00-2 2v2m10 0H7m5-10a2 2 0 100-4 2 2 0 000 4z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('clusters.edit')"
                                        @click="editCluster(cluster)"
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Cluster"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('clusters.delete')"
                                        @click="deleteCluster(cluster)"
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Cluster"
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

        <div v-if="showAssignStoresModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeAssignStoresModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Assign Stores</h3>
                            <p class="text-sm text-gray-500">
                                {{ selectedClusterForStores?.name }} ({{ selectedClusterForStores?.code }})
                            </p>
                        </div>
                        <button @click="closeAssignStoresModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitAssignStores" class="space-y-5">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Stores</label>
                            <MultiAutocomplete
                                v-model="assignStoresForm.store_ids"
                                :options="storeOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select one or more stores..."
                            />
                            <p class="mt-2 text-xs text-gray-500">
                                Selected stores will be moved to this cluster and their `cluster_name` value will reflect this cluster.
                            </p>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button
                                type="button"
                                @click="closeAssignStoresModal"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all"
                            >
                                Assign Stores
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Cluster' : 'Create Cluster' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cluster Code</label>
                            <input
                                v-model="form.code"
                                type="text"
                                required
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                            >
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cluster Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                            >
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button
                                type="button"
                                @click="closeModal"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all"
                            >
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    clusters: Object,
    stores: {
        type: Array,
        default: () => [],
    },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.clusters, 'clusters.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const showAssignStoresModal = ref(false)
const isEditing = ref(false)
const currentCluster = ref(null)
const selectedClusterForStores = ref(null)

const form = reactive({
    code: '',
    name: '',
})

const assignStoresForm = reactive({
    store_ids: [],
})

const storeOptions = computed(() => {
    return props.stores.map(store => ({
        id: store.id,
        name: `${store.name} (${store.code})${store.cluster?.name ? ` - ${store.cluster.name}` : ''}`,
    }))
})

onMounted(() => {
    pagination.updateData(props.clusters)
})

watch(() => props.clusters, (newClusters) => {
    pagination.updateData(newClusters)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentCluster.value = null
    form.code = ''
    form.name = ''
    showModal.value = true
}

const editCluster = (cluster) => {
    isEditing.value = true
    currentCluster.value = cluster
    form.code = cluster.code
    form.name = cluster.name
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const openAssignStoresModal = (cluster) => {
    selectedClusterForStores.value = cluster
    assignStoresForm.store_ids = (cluster.stores || []).map(store => store.id)
    showAssignStoresModal.value = true
}

const closeAssignStoresModal = () => {
    showAssignStoresModal.value = false
    selectedClusterForStores.value = null
    assignStoresForm.store_ids = []
}

const submitForm = () => {
    const url = isEditing.value ? `/clusters/${currentCluster.value.id}` : '/clusters'
    const requestMethod = isEditing.value ? put : post

    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Cluster updated successfully' : 'Cluster created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const submitAssignStores = () => {
    if (!selectedClusterForStores.value) return

    post(`/clusters/${selectedClusterForStores.value.id}/assign-stores`, assignStoresForm, {
        onSuccess: () => {
            closeAssignStoresModal()
            showSuccess('Stores assigned successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Unable to assign stores'
            showError(errorMessage)
        }
    })
}

const deleteCluster = async (cluster) => {
    const confirmed = await confirm({
        title: 'Delete Cluster',
        message: `Are you sure you want to delete "${cluster.name}"? This action cannot be undone.`
    })

    if (confirmed) {
        destroy(`/clusters/${cluster.id}`, {
            onSuccess: () => showSuccess('Cluster deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete cluster'
                showError(errorMessage)
            }
        })
    }
}
</script>
