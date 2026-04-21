<script setup>
import { ref, onMounted, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'

const props = defineProps({
    posRequests: Object,
    filters: Object,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { destroy: performDelete } = useErrorHandler()
const status = ref(props.filters?.status ?? '')
const pagination = usePagination(props.posRequests, 'pos-requests.index', () => ({
    status: status.value
}))
const { hasPermission } = usePermission()

onMounted(() => {
    pagination.updateData(props.posRequests)
})

watch(status, () => {
    pagination.goToPage(1)
})

watch(() => props.posRequests, (newVal) => {
    pagination.updateData(newVal)
}, { deep: true })

const deleteRequest = async (request) => {
    const confirmed = await confirm({
        title: 'Delete POS Request',
        message: `Are you sure you want to delete request #${request.id}? This action cannot be undone.`
    })
    
    if (confirmed) {
        performDelete(route('pos-requests.destroy', request.id), {
            onSuccess: () => showSuccess('POS Request deleted successfully'),
            onError: () => showError('Cannot delete this request')
        })
    }
}

const getStatusLabel = (request) => {
    const status = request.ticket ? request.ticket.status : request.status
    switch (status) {
        case 'open': return 'Ticket: Open'
        case 'for_schedule': return 'For Schedule'
        case 'in_progress': return 'In Progress'
        case 'resolved': return 'Resolved'
        case 'closed': return 'Closed'
        case 'waiting_service_provider': return 'Waiting for SP'
        case 'waiting_client_feedback': return 'Waiting for Client'
        default: return status.replace('_', ' ')
    }
}

const getStatusClass = (request) => {
    const status = request.ticket ? request.ticket.status : request.status
    if (status.startsWith('Approved Level')) return 'bg-blue-50 text-blue-700 border-blue-100'
    
    switch (status) {
        case 'Approved': return 'bg-emerald-100 text-emerald-800 border-emerald-200'
        case 'Open': 
        case 'open': return 'bg-blue-100 text-blue-800 border-blue-200'
        case 'Rejected': return 'bg-red-100 text-red-800 border-red-200'
        case 'Cancelled': return 'bg-rose-100 text-rose-800 border-rose-200'
        case 'for_schedule': return 'bg-teal-50 text-teal-700 border-teal-100'
        case 'in_progress': return 'bg-purple-50 text-purple-700 border-purple-100'
        case 'resolved': return 'bg-green-100 text-green-800 border-green-200'
        case 'closed': return 'bg-gray-100 text-gray-600 border-gray-200'
        case 'waiting_service_provider': return 'bg-orange-50 text-orange-700 border-orange-100'
        case 'waiting_client_feedback': return 'bg-blue-50 text-blue-700 border-blue-100'
        default: return 'bg-amber-100 text-amber-800 border-amber-200'
    }
}

const getStageDisplay = (request) => {
    const requestStatus = request.status ?? ''
    const totalLevels = Number(request.request_type?.approval_levels ?? 0)

    if (requestStatus === 'Rejected') {
        return { label: 'Rejected', class: 'text-[10px] font-black text-red-600 uppercase tracking-widest' }
    }

    if (requestStatus === 'Cancelled') {
        return { label: 'Cancelled', class: 'text-[10px] font-black text-rose-600 uppercase tracking-widest' }
    }

    if (requestStatus === 'Approved' || request.ticket) {
        return { label: 'Completed', class: 'text-[10px] font-black text-emerald-600 uppercase tracking-widest' }
    }

    if (totalLevels > 0) {
        return {
            label: `${Number(request.current_approval_level ?? 0)} / ${totalLevels}`,
            class: 'text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-0.5 rounded-full border border-indigo-100',
            isBadge: true,
        }
    }

    return { label: 'N/A', class: 'text-[10px] font-black text-gray-300 uppercase' }
}
</script>

<template>
    <AppLayout title="POS Requests">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="POS Request Management"
                    subtitle="Track and manage point-of-sale configuration requests"
                    search-placeholder="Search by company or request type..."
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
                        <div class="flex items-center space-x-4">
                            <select v-model="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm font-bold text-gray-700 bg-white shadow-sm">
                                <option value="">All Statuses</option>
                                <option value="Open">Open</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="for_schedule">For Schedule</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>

                            <Link
                                v-if="hasPermission('pos_requests.create')"
                                :href="route('pos-requests.create')"
                                class="group relative inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white text-sm font-black rounded-xl hover:bg-indigo-700 transition-all duration-300 shadow-md hover:shadow-indigo-200 whitespace-nowrap"
                            >
                                <svg class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>New POS Request</span>
                            </Link>
                        </div>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Request Info</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Ticket#</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Requested By</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-400 uppercase tracking-widest">Company</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Requested Date</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Launch Date</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Stage</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-400 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-black text-gray-400 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="request in data" :key="request.id" class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/30 transition-all duration-300 border-b border-gray-100 last:border-0">
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-500">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-black text-gray-900 group-hover:text-indigo-600 transition-colors">{{ request.request_type.name }}</div>
                                        <div class="text-[10px] text-gray-400 font-bold uppercase tracking-tighter">ID: #{{ request.id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div v-if="request.ticket">
                                    <Link :href="route('tickets.edit', request.ticket.id)" class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-black hover:bg-blue-600 hover:text-white transition-all shadow-sm">
                                        <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                        {{ request.ticket.ticket_key }}
                                    </Link>
                                </div>
                                <span v-else class="text-[10px] font-black text-gray-300 uppercase italic">Pending</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-gray-900">{{ request.user ? request.user.name : (request.requester_name || 'Public Submission') }}</span>
                                    <span v-if="!request.user && request.requester_email" class="text-[10px] text-gray-400 font-medium">{{ request.requester_email }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <span class="text-sm font-bold text-gray-600">{{ request.company.name }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span class="text-xs font-bold text-gray-500">{{ new Date(request.created_at).toLocaleDateString() }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span class="text-sm font-mono font-bold text-gray-900">{{ request.launch_date }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span v-if="!getStageDisplay(request).isBadge" :class="getStageDisplay(request).class">
                                    {{ getStageDisplay(request).label }}
                                </span>
                                <div v-else class="inline-flex flex-col">
                                    <span :class="getStageDisplay(request).class">
                                        {{ getStageDisplay(request).label }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span :class="getStatusClass(request)" class="inline-flex items-center px-3 py-1 text-[10px] font-black uppercase tracking-widest rounded-full border shadow-sm">
                                    {{ getStatusLabel(request) }}
                                </span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-right">
                                <div class="flex justify-end space-x-2">
                                    <Link
                                        :href="route('pos-requests.show', request.id)"
                                        class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="View Details"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </Link>
                                    
                                    <Link
                                        v-if="hasPermission('pos_requests.edit') && request.status === 'Open'"
                                        :href="route('pos-requests.edit', request.id)"
                                        class="p-2 text-amber-600 hover:text-white hover:bg-amber-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="Edit Request"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </Link>

                                    <button
                                        v-if="hasPermission('pos_requests.delete') && request.status === 'Open'"
                                        @click="deleteRequest(request)"
                                        class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="Delete Request"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
    </AppLayout>
</template>
