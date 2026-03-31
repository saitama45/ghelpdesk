<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    requestTypes: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy: deleteRequest } = useErrorHandler()
const pagination = usePagination(props.requestTypes, 'request-types.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentRequestType = ref(null)

const form = reactive({
    code: '',
    name: '',
    request_for: [],
    approval_levels: 0,
    cc_emails: '',
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.requestTypes)
})

watch(() => props.requestTypes, (newTypes) => {
    pagination.updateData(newTypes)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentRequestType.value = null
    form.code = ''
    form.name = ''
    form.request_for = ['SAP']
    form.approval_levels = 0
    form.cc_emails = ''
    form.is_active = true
    showModal.value = true
}

const editRequestType = (type) => {
    isEditing.value = true
    currentRequestType.value = type
    form.code = type.code
    form.name = type.name
    form.request_for = Array.isArray(type.request_for) ? [...type.request_for] : [type.request_for]
    form.approval_levels = type.approval_levels ?? 0
    form.cc_emails = type.cc_emails || ''
    form.is_active = type.is_active
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/request-types/${currentRequestType.value.id}` : '/request-types'
    const method = isEditing.value ? 'put' : 'post'
    
    const requestMethod = method === 'put' ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Request Type updated successfully' : 'Request Type created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteRequestType = async (type) => {
    const confirmed = await confirm({
        title: 'Delete Request Type',
        message: `Are you sure you want to delete "${type.name}"? This action cannot be undone.`
    })
    
    if (confirmed) {
        deleteRequest(`/request-types/${type.id}`, {
            onSuccess: () => showSuccess('Request Type deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete request type'
                showError(errorMessage)
            }
        })
    }
}

const getRequestForBadgeClass = (requestFor) => {
    switch (requestFor) {
        case 'SAP':
            return 'bg-blue-100 text-blue-800 border border-blue-200'
        case 'POS':
            return 'bg-purple-100 text-purple-800 border border-purple-200'
        default:
            return 'bg-gray-100 text-gray-800'
    }
}

const toggleSystem = (system) => {
    const index = form.request_for.indexOf(system)
    if (index === -1) {
        form.request_for.push(system)
    } else if (form.request_for.length > 1) {
        form.request_for.splice(index, 1)
    } else {
        showError('At least one system must be selected')
    }
}
</script>

<template>
    <AppLayout title="Request Types">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Request Type Management"
                    subtitle="Manage classification of ticket requests for SAP and POS systems"
                    search-placeholder="Search by code, name or system..."
                    empty-message="No request types found. Create your first one to get started."
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
                            v-if="hasPermission('request_types.create')"
                            @click="openCreateModal"
                            class="group relative inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-300 shadow-md hover:shadow-indigo-200 whitespace-nowrap"
                        >
                            <svg class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Add Request Type</span>
                        </button>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Code</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Request Type</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Systems</th>
                            <th class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-widest">Approvals</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <transition-group
                            enter-active-class="transition duration-500 ease-out"
                            enter-from-class="transform translate-y-4 opacity-0"
                            enter-to-class="transform translate-y-0 opacity-100"
                            leave-active-class="transition duration-300 ease-in"
                            leave-from-class="transform translate-y-0 opacity-100"
                            leave-to-class="transform translate-y-4 opacity-0"
                        >
                            <tr v-for="(type, index) in data" :key="type.id" 
                                :style="{ transitionDelay: `${index * 50}ms` }"
                                class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/50 transition-all duration-300 border-b border-gray-100 last:border-0"
                            >
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-gray-100 text-gray-700 border border-gray-200 group-hover:bg-indigo-50 group-hover:text-indigo-700 group-hover:border-indigo-100 transition-colors duration-300">
                                        {{ type.code }}
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-xl flex items-center justify-center shadow-sm group-hover:from-indigo-500 group-hover:to-indigo-600 transition-all duration-500">
                                            <svg class="w-5 h-5 text-indigo-600 group-hover:text-white transition-colors duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-bold text-gray-900 group-hover:text-indigo-600 transition-colors duration-300">{{ type.name }}</div>
                                            <div class="text-[10px] text-gray-400 font-medium truncate max-w-[150px]" v-if="type.cc_emails">CC: {{ type.cc_emails }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="system in (Array.isArray(type.request_for) ? type.request_for : [type.request_for])" 
                                              :key="system"
                                              :class="getRequestForBadgeClass(system)" 
                                              class="inline-flex items-center px-3 py-1 text-[10px] font-black rounded-full tracking-wider shadow-sm transition-transform duration-300 group-hover:scale-110">
                                            <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="system === 'SAP' ? 'bg-blue-500' : 'bg-purple-500'"></span>
                                            {{ system }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-center">
                                    <span v-if="type.approval_levels > 0" class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-orange-50 text-orange-700 border border-orange-100">
                                        {{ type.approval_levels }} Level{{ type.approval_levels > 1 ? 's' : '' }}
                                    </span>
                                    <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black bg-gray-100 text-gray-400 border border-gray-200">
                                        No Approval
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span :class="type.is_active ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-rose-100 text-rose-800 border border-rose-200'" 
                                          class="inline-flex items-center px-3 py-1 text-[10px] font-bold uppercase tracking-widest rounded-full shadow-sm">
                                        <span class="w-1 h-1 rounded-full mr-1.5" :class="type.is_active ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        {{ type.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-2 opacity-0 group-hover:opacity-100 transition-all duration-300 transform translate-x-2 group-hover:translate-x-0">
                                        <button 
                                            v-if="hasPermission('request_types.edit')"
                                            @click="editRequestType(type)" 
                                            class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm hover:shadow-indigo-200"
                                            title="Edit"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button 
                                            v-if="hasPermission('request_types.delete')"
                                            @click="deleteRequestType(type)" 
                                            class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300 shadow-sm hover:shadow-rose-200"
                                            title="Delete"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </transition-group>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <transition
            enter-active-class="duration-300 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="duration-200 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto overflow-x-hidden flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity" @click="closeModal"></div>
                
                <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-2xl p-8 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-2xl font-black text-gray-900 tracking-tight">
                                {{ isEditing ? 'Update Request Type' : 'New Request Type' }}
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Configure system type, approvals, and notifications.</p>
                        </div>
                        <button @click="closeModal" class="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-full transition-all duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Unique Code</label>
                                <input v-model="form.code" type="text" required placeholder="e.g. SAP-001"
                                       class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-bold transition-all duration-300">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Approval Levels</label>
                                <div class="flex items-center space-x-4 bg-gray-50 rounded-2xl p-1 border border-transparent focus-within:border-indigo-500 focus-within:bg-white transition-all duration-300">
                                    <button type="button" @click="form.approval_levels > 0 && form.approval_levels--" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                        </svg>
                                    </button>
                                    <div class="flex-1 text-center">
                                        <input v-model.number="form.approval_levels" type="number" required min="0"
                                               class="w-full text-center bg-transparent border-none focus:ring-0 text-sm font-black text-gray-900">
                                        <div class="text-[9px] font-black uppercase text-orange-500 mt-[-4px]" v-if="form.approval_levels === 0">No Approval</div>
                                    </div>
                                    <button type="button" @click="form.approval_levels++" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Display Name</label>
                            <input v-model="form.name" type="text" required placeholder="e.g. Master Data Update"
                                   class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-bold transition-all duration-300">
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-3 ml-1">System Type</label>
                            <div class="grid grid-cols-2 gap-4">
                                <button 
                                    type="button"
                                    @click="toggleSystem('SAP')"
                                    :class="form.request_for.includes('SAP') 
                                        ? 'bg-blue-600 text-white ring-4 ring-blue-100' 
                                        : 'bg-gray-50 text-gray-500 hover:bg-gray-100'"
                                    class="flex items-center justify-center p-4 rounded-2xl border border-transparent text-sm font-black transition-all duration-300"
                                >
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                                    </svg>
                                    SAP
                                </button>
                                <button 
                                    type="button"
                                    @click="toggleSystem('POS')"
                                    :class="form.request_for.includes('POS') 
                                        ? 'bg-purple-600 text-white ring-4 ring-purple-100' 
                                        : 'bg-gray-50 text-gray-500 hover:bg-gray-100'"
                                    class="flex items-center justify-center p-4 rounded-2xl border border-transparent text-sm font-black transition-all duration-300"
                                >
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    POS
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">CC Email Notifications (one per line)</label>
                            <textarea v-model="form.cc_emails" rows="3" placeholder="email1@example.com&#10;email2@example.com"
                                      class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-medium transition-all duration-300 custom-scrollbar"></textarea>
                            <p class="text-[10px] text-gray-400 mt-2 ml-1 italic">These addresses will be notified via CC on ticket updates.</p>
                        </div>

                        <div v-if="isEditing" class="flex items-center p-4 bg-gray-50 rounded-2xl border border-gray-100">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                <span class="ml-3 text-sm font-bold text-gray-700">Active Status</span>
                            </label>
                        </div>

                        <div class="flex space-x-4 pt-6">
                            <button type="button" @click="closeModal" 
                                    class="flex-1 px-6 py-3 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-all duration-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="flex-[2] px-6 py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-lg hover:shadow-indigo-200 transform hover:-translate-y-0.5 transition-all duration-300">
                                {{ isEditing ? 'Update Request Type' : 'Create Request Type' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
.font-black { font-weight: 900; }
.tracking-widest { letter-spacing: 0.15em; }
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}
</style>
