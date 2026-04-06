<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    sapRequests: Object,
    filters: Object,
    requestTypes: Array,
})

const { hasPermission } = usePermission()
const { confirm } = useConfirm()
const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')
const showCreateSection = ref(false)

function startRequest(typeId) {
    router.get(route('sap-requests.create'), { type_id: typeId })
}

function applyFilter() {
    router.get(route('sap-requests.index'), { search: search.value, status: status.value }, { preserveState: true, replace: true })
}

async function deleteRequest(id) {
    const confirmed = await confirm({
        title: 'Delete SAP Request',
        message: 'Are you sure you want to delete this SAP request? This action cannot be undone.'
    })

    if (confirmed) {
        router.delete(route('sap-requests.destroy', id), {
            preserveScroll: true,
        })
    }
}

const STATUS_COLORS = {
    'Open': 'bg-blue-100 text-blue-700',
    'Approved': 'bg-emerald-100 text-emerald-700',
    'Cancelled': 'bg-rose-100 text-rose-700',
    'In Progress': 'bg-amber-100 text-amber-700',
    'Resolved': 'bg-gray-100 text-gray-600',
}
function statusClass(s) {
    if (!s) return 'bg-gray-100 text-gray-500'
    if (s.startsWith('Approved Level')) return 'bg-indigo-100 text-indigo-700'
    return STATUS_COLORS[s] ?? 'bg-gray-100 text-gray-500'
}
</script>

<template>
    <AppLayout title="SAP Requests">
        <div class="py-10 bg-gray-50 min-h-screen">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">

                <!-- Header -->
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h1 class="text-3xl font-black text-gray-900 tracking-tight">SAP Requests</h1>
                        <p class="text-sm text-gray-500 font-medium mt-1">Manage and track all SAP data creation requests.</p>
                    </div>
                    <button v-if="hasPermission('sap_requests.create')" @click="showCreateSection = !showCreateSection"
                        :class="showCreateSection ? 'bg-gray-200 text-gray-700' : 'bg-teal-600 text-white shadow-lg shadow-teal-100 hover:bg-teal-700'"
                        class="flex items-center gap-2 px-6 py-3 rounded-2xl font-black text-sm transition-all">
                        <svg class="w-4 h-4 transition-transform" :class="showCreateSection ? 'rotate-45' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        {{ showCreateSection ? 'Close' : 'New SAP Request' }}
                    </button>
                </div>

                <!-- Create Section (Selection) -->
                <div v-if="showCreateSection" class="mb-10 animate-in fade-in slide-in-from-top-4 duration-300">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-px flex-1 bg-gray-200"></div>
                        <h2 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Select Request Type to Start</h2>
                        <div class="h-px flex-1 bg-gray-200"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <Link v-for="rt in requestTypes" :key="rt.id" :href="route('sap-requests.create', { type_id: rt.id })"
                            class="bg-white p-6 rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 text-left hover:border-teal-500 hover:shadow-teal-100/50 transition-all group">
                            <div class="w-12 h-12 bg-teal-50 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-teal-600 group-hover:text-white transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <h4 class="text-sm font-black text-gray-900 mb-1">{{ rt.name }}</h4>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">
                                {{ rt.approval_levels > 0 ? `${rt.approval_levels} Approval Steps` : 'No Approval Required' }}
                            </p>
                        </Link>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6 flex flex-wrap gap-4">
                    <input v-model="search" @keyup.enter="applyFilter" type="text" placeholder="Search by request type, company, or requester..."
                        class="flex-1 min-w-[220px] border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all" />
                    <select v-model="status" @change="applyFilter"
                        class="border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium focus:border-teal-500 focus:ring-0 transition-all">
                        <option value="">All Statuses</option>
                        <option>Open</option>
                        <option>Approved</option>
                        <option>In Progress</option>
                        <option>Resolved</option>
                        <option>Cancelled</option>
                    </select>
                    <button @click="applyFilter" class="px-5 py-2.5 bg-teal-600 text-white rounded-xl font-bold text-sm hover:bg-teal-700 transition-all">
                        Search
                    </button>
                </div>

                <!-- Table -->
                <div class="bg-white rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50/50">
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">#</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Request Type</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Entity</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Requester</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Status</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Ticket</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Submitted</th>
                                    <th class="px-6 py-4"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-if="!sapRequests.data.length">
                                    <td colspan="8" class="px-6 py-16 text-center text-sm text-gray-400 font-medium">No SAP requests found.</td>
                                </tr>
                                <tr v-for="r in sapRequests.data" :key="r.id" class="hover:bg-gray-50/50 transition-colors group">
                                    <td class="px-6 py-4 text-sm font-black text-gray-400">#{{ r.id }}</td>
                                    <td class="px-6 py-4">
                                        <span class="text-sm font-bold text-gray-900">{{ r.request_type?.name }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-700">{{ r.company?.name }}</td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-bold text-gray-800">{{ r.user?.name ?? r.requester_name }}</div>
                                        <div class="text-xs text-gray-400 font-medium">{{ r.user?.email ?? r.requester_email }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span :class="statusClass(r.status)" class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide">
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <Link v-if="r.ticket" :href="route('tickets.show', r.ticket.id)"
                                            class="text-xs font-black text-teal-600 hover:text-teal-800 font-mono">
                                            {{ r.ticket.ticket_key }}
                                        </Link>
                                        <span v-else class="text-xs text-gray-300 font-bold">—</span>
                                    </td>
                                    <td class="px-6 py-4 text-xs font-medium text-gray-400">{{ new Date(r.created_at).toLocaleDateString() }}</td>
                                    <td class="px-6 py-4 flex items-center justify-end gap-2">
                                        <Link :href="route('sap-requests.show', r.id)"
                                            class="text-xs font-black text-indigo-600 hover:text-indigo-800 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition-all">
                                            View
                                        </Link>
                                        <Link v-if="hasPermission('sap_requests.edit') && r.status === 'Open'" :href="route('sap-requests.edit', r.id)"
                                            class="text-xs font-black text-amber-600 hover:text-amber-800 px-3 py-1.5 rounded-lg hover:bg-amber-50 transition-all">
                                            Edit
                                        </Link>
                                        <button v-if="hasPermission('sap_requests.delete')" @click="deleteRequest(r.id)"
                                            class="text-xs font-black text-rose-600 hover:text-rose-800 px-3 py-1.5 rounded-lg hover:bg-rose-50 transition-all">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="sapRequests.last_page > 1" class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
                        <p class="text-xs text-gray-400 font-medium">
                            Showing {{ sapRequests.from }}–{{ sapRequests.to }} of {{ sapRequests.total }} requests
                        </p>
                        <div class="flex gap-1">
                            <Link v-for="link in sapRequests.links" :key="link.label" :href="link.url ?? '#'"
                                :class="[
                                    'px-3 py-1.5 rounded-lg text-xs font-bold transition-all',
                                    link.active ? 'bg-teal-600 text-white' : 'text-gray-500 hover:bg-gray-100',
                                    !link.url ? 'opacity-30 pointer-events-none' : ''
                                ]"
                                v-html="link.label" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
