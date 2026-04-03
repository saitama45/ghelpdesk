<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    sapRequests: Object,
    filters: Object,
})

const { hasPermission } = usePermission()
const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')

function applyFilter() {
    router.get(route('sap-requests.index'), { search: search.value, status: status.value }, { preserveState: true, replace: true })
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
                    <Link v-if="hasPermission('sap_requests.create')" :href="route('sap-requests.create')"
                        class="flex items-center gap-2 px-6 py-3 bg-teal-600 text-white rounded-2xl font-black text-sm shadow-lg shadow-teal-100 hover:bg-teal-700 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        New SAP Request
                    </Link>
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
                                    <td class="px-6 py-4">
                                        <Link :href="route('sap-requests.show', r.id)"
                                            class="text-xs font-black text-indigo-600 hover:text-indigo-800 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition-all">
                                            View
                                        </Link>
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
