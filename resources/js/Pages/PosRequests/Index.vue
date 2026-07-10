<script setup>
import { ref, onMounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import CopyRecordModal from '@/Components/CopyRecordModal.vue'

const props = defineProps({
    posRequests: Object,
    companies: Array,
    filters: Object,
    isApprover: Boolean,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { destroy: performDelete } = useErrorHandler()
const status = ref(props.filters?.status ?? (props.isApprover ? 'for_my_approval' : ''))
const entityDeptId = ref(props.filters?.company_id ? String(props.filters.company_id) : '')
const pagination = usePagination(props.posRequests, 'pos-requests.index', () => ({
    status: status.value,
    company_id: entityDeptId.value,
}))
const { hasPermission } = usePermission()

const showCopyModal = ref(false)
const recordToCopy = ref(null)

const openCopyModal = (request) => {
    recordToCopy.value = request
    showCopyModal.value = true
}

onMounted(() => {
    pagination.updateData(props.posRequests)
})

watch([status, entityDeptId], () => {
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

// A fully-approved request (0-approver auto-approval OR all levels signed off)
// that never got its ticket — the rare "blue moon" miss that needs recovery.
// An archived ticket is excluded: it's restorable, so regenerating would duplicate it.
const needsTicket = (request) => request.status === 'Approved' && request.ticket_state === 'none'

const generatingId = ref(null)

const generateTicket = async (request) => {
    const confirmed = await confirm({
        title: 'Generate Ticket',
        message: `Request #${request.id} is approved but has no ticket yet. Generate its ticket now?`,
        confirmText: 'Generate',
    })

    if (!confirmed) return

    generatingId.value = request.id
    // No toasts here: the controller flashes success/error and AppLayout renders it.
    // Inertia treats a redirect carrying an error flash as onSuccess, so toasting
    // here would stack a bogus "generated" message on top of the real failure.
    router.post(route('pos-requests.generate-ticket', request.id), {}, {
        preserveScroll: true,
        onFinish: () => { generatingId.value = null },
    })
}

// Only a live ticket dictates the row status; an archived one is not the
// request's current state, so fall back to the request's own status.
const liveTicket = (request) => (request.ticket_state === 'live' ? request.ticket : null)

const getStatusLabel = (request) => {
    const status = liveTicket(request) ? request.ticket.status : request.status
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
    const status = liveTicket(request) ? request.ticket.status : request.status
    if (status.startsWith('Approved Level')) return 'bg-blue-50 text-blue-700 border-blue-100 dark:bg-blue-500/15 dark:text-blue-200 dark:border-blue-400/30'
    
    switch (status) {
        case 'Approved': return 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:border-emerald-400/30'
        case 'Open': 
        case 'open': return 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-500/15 dark:text-blue-200 dark:border-blue-400/30'
        case 'Rejected': return 'bg-red-100 text-red-800 border-red-200 dark:bg-red-500/15 dark:text-red-200 dark:border-red-400/30'
        case 'Cancelled': return 'bg-rose-100 text-rose-800 border-rose-200 dark:bg-rose-500/15 dark:text-rose-200 dark:border-rose-400/30'
        case 'for_schedule': return 'bg-teal-50 text-teal-700 border-teal-100 dark:bg-teal-500/15 dark:text-teal-200 dark:border-teal-400/30'
        case 'in_progress': return 'bg-purple-50 text-purple-700 border-purple-100 dark:bg-purple-500/15 dark:text-purple-200 dark:border-purple-400/30'
        case 'resolved': return 'bg-green-100 text-green-800 border-green-200 dark:bg-green-500/15 dark:text-green-200 dark:border-green-400/30'
        case 'closed': return 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600'
        case 'waiting_service_provider': return 'bg-orange-50 text-orange-700 border-orange-100 dark:bg-orange-500/15 dark:text-orange-200 dark:border-orange-400/30'
        case 'waiting_client_feedback': return 'bg-blue-50 text-blue-700 border-blue-100 dark:bg-blue-500/15 dark:text-blue-200 dark:border-blue-400/30'
        default: return 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:border-amber-400/30'
    }
}

const getStageDisplay = (request) => {
    const requestStatus = request.status ?? ''
    const totalLevels = Number(request.request_type?.approval_levels ?? 0)

    if (requestStatus === 'Rejected') {
        return { label: 'Rejected', class: 'text-[10px] font-black text-red-600 uppercase tracking-widest dark:text-red-300' }
    }

    if (requestStatus === 'Cancelled') {
        return { label: 'Cancelled', class: 'text-[10px] font-black text-rose-600 uppercase tracking-widest dark:text-rose-300' }
    }

    if (requestStatus === 'Approved' || liveTicket(request)) {
        return { label: 'Completed', class: 'text-[10px] font-black text-emerald-600 uppercase tracking-widest dark:text-emerald-300' }
    }

    if (totalLevels > 0) {
        return {
            label: `${Number(request.current_approval_level ?? 0)} / ${totalLevels}`,
            class: 'text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-0.5 rounded-full border border-indigo-100 dark:bg-indigo-500/15 dark:text-indigo-200 dark:border-indigo-400/30',
            isBadge: true,
        }
    }

    return { label: 'N/A', class: 'text-[10px] font-black text-gray-300 uppercase dark:text-slate-400' }
}
</script>

<template>
    <AppLayout title="POS Requests" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 bg-gray-50/50 min-h-screen dark:bg-slate-950">
                <DataTable
                    title="POS Request Management"
                    subtitle="Track and manage point-of-sale configuration requests"
                    search-placeholder="Search by entity/dept. or request type..."
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
                            <select v-model="entityDeptId" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm font-bold text-gray-700 bg-white shadow-sm dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700">
                                <option value="">All Entity/Dept.</option>
                                <option v-for="company in companies" :key="company.id" :value="String(company.id)">
                                    {{ company.name }}
                                </option>
                            </select>

                            <select v-model="status" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl text-sm font-bold text-gray-700 bg-white shadow-sm dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700">
                                <option value="">All Statuses</option>
                                <option v-if="props.isApprover" value="for_my_approval" class="font-bold text-indigo-700">For My Approval</option>
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
                        <tr class="bg-gray-50/80 backdrop-blur-sm dark:bg-slate-800/80">
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Request Info</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Ticket#</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Requested By</th>
                            <th class="px-6 py-4 text-left text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Entity/Dept.</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Requested Date</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Launch Date</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Stage</th>
                            <th class="px-6 py-4 text-center text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-black text-gray-500 uppercase tracking-widest dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="request in data" :key="request.id" class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/30 transition-all duration-300 border-b border-gray-100 last:border-0 dark:border-slate-700 dark:hover:bg-slate-800/70 dark:hover:shadow-black/20">
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-500 dark:bg-indigo-500/15 dark:text-indigo-200">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-black text-gray-900 group-hover:text-indigo-600 transition-colors dark:text-slate-100 dark:group-hover:text-indigo-200">{{ request.request_type.name }}</div>
                                        <div class="text-[10px] text-gray-500 font-bold uppercase tracking-tighter dark:text-slate-300">ID: #{{ request.id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <!-- Live ticket: clickable. -->
                                <div v-if="request.ticket_state === 'live'">
                                    <Link :href="route('tickets.edit', request.ticket.id)" class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-black hover:bg-blue-600 hover:text-white transition-all shadow-sm dark:bg-blue-500/15 dark:text-blue-200 dark:hover:bg-blue-600 dark:hover:text-white">
                                        <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                        {{ request.ticket.ticket_key }}
                                    </Link>
                                </div>
                                <!-- Archived: still show the number, but it isn't openable. -->
                                <div v-else-if="request.ticket_state === 'archived'" class="space-y-1">
                                    <span class="inline-flex items-center px-3 py-1.5 bg-rose-50 text-rose-700 rounded-lg text-xs font-black shadow-sm dark:bg-rose-500/15 dark:text-rose-200">
                                        <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" />
                                        </svg>
                                        {{ request.ticket.ticket_key }}
                                    </span>
                                    <div class="text-[9px] font-black text-rose-600 uppercase tracking-wide dark:text-rose-300">
                                        Archived{{ request.ticket.archiver ? ' by ' + request.ticket.archiver.name : '' }}
                                    </div>
                                </div>
                                <span v-else class="text-[10px] font-black text-gray-400 uppercase italic dark:text-slate-400">Pending</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-bold text-gray-900 dark:text-slate-100">{{ request.user ? request.user.name : (request.requester_name || 'Public Submission') }}</span>
                                    <span v-if="!request.user && request.requester_email" class="text-[10px] text-gray-500 font-medium dark:text-slate-300">{{ request.requester_email }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <span class="text-sm font-bold text-gray-600 dark:text-slate-200">{{ request.company.name }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span class="text-xs font-bold text-gray-500 dark:text-slate-300">{{ new Date(request.created_at).toLocaleDateString() }}</span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span class="text-sm font-mono font-bold text-gray-900 dark:text-slate-100">{{ request.launch_date }}</span>
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

                                    <button
                                        @click="openCopyModal(request)"
                                        class="p-2 text-blue-600 hover:text-white hover:bg-blue-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center"
                                        title="Copy to Module"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 011.414.293l4.414 4.414a1 1 0 01.293 1.414V17a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 01-2 2v2a2 2 0 002 2h10a2 2 0 002-2v-2" />
                                        </svg>
                                    </button>
                                    
                                    <button
                                        v-if="needsTicket(request) && hasPermission('pos_requests.approve')"
                                        @click="generateTicket(request)"
                                        :disabled="generatingId === request.id"
                                        class="p-2 text-emerald-600 hover:text-white hover:bg-emerald-600 rounded-xl transition-all duration-300 shadow-sm flex items-center justify-center disabled:opacity-50"
                                        title="Generate missing ticket"
                                    >
                                        <svg v-if="generatingId === request.id" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                        </svg>
                                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    </button>

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

        <CopyRecordModal
            :show="showCopyModal"
            :source-record="recordToCopy"
            source-type="pos"
            @close="showCopyModal = false; recordToCopy = null"
        />
    </AppLayout>
</template>
