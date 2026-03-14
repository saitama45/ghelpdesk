<script setup>
import { ref } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { FunnelIcon, ChartBarIcon, CheckCircleIcon, XCircleIcon, ClockIcon, DocumentArrowDownIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
    reportData: Array,
    users: Array,
    subUnits: Array,
    filters: Object
});

const filterForm = ref({
    user_id: props.filters.user_id,
    sub_unit: props.filters.sub_unit,
    start_date: props.filters.start_date,
    end_date: props.filters.end_date
});

const applyFilters = () => {
    router.get(route('reports.sla-performance'), filterForm.value, {
        preserveState: true,
        preserveScroll: true
    });
};

const exportPDF = () => {
    const params = new URLSearchParams(filterForm.value).toString();
    window.open(route('reports.sla-performance.pdf') + '?' + params, '_blank');
};

const showTicketsModal = ref(false);
const modalLoading = ref(false);
const selectedTickets = ref([]);
const modalTitle = ref('');

const fetchTickets = async (userId, userName, type, status) => {
    modalLoading.value = true;
    showTicketsModal.value = true;
    modalTitle.value = `${userName}: ${type.charAt(0).toUpperCase() + type.slice(1)} ${status.toUpperCase()}`;
    
    try {
        const response = await axios.get(route('reports.sla-performance.tickets', undefined, false), {
            params: { 
                user_id: userId,
                type: type,
                status: status,
                start_date: filterForm.value.start_date,
                end_date: filterForm.value.end_date
            }
        });
        selectedTickets.value = response.data.tickets;
    } catch (error) {
        console.error('Error fetching tickets:', error);
    } finally {
        modalLoading.value = false;
    }
};

const getPercentageColor = (percentage) => {
    if (percentage >= 95) return 'text-green-600';
    if (percentage >= 85) return 'text-yellow-600';
    return 'text-red-600';
};

const getProgressColor = (percentage) => {
    if (percentage >= 95) return 'bg-green-500';
    if (percentage >= 85) return 'bg-yellow-500';
    return 'bg-red-500';
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for clients feedback?';
        default: return status ? status.replace('_', ' ') : '';
    }
};
</script>

<template>
    <Head title="SLA Performance Report" />

    <AppLayout>
        <template #header>
            SLA Performance Report
        </template>

        <div class="space-y-6">
            <!-- Filters Card -->
            <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Sub-Unit</label>
                        <select v-model="filterForm.sub_unit" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all">All Sub-Units</option>
                            <option v-for="unit in subUnits" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">User</label>
                        <select v-model="filterForm.user_id" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all">All Users</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Start Date</label>
                        <input type="date" v-model="filterForm.start_date" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">End Date</label>
                        <input type="date" v-model="filterForm.end_date" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div class="md:col-span-4 flex justify-end space-x-2">
                        <button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors">
                            <FunnelIcon class="w-4 h-4 mr-2" />
                            Generate Report
                        </button>
                        <button @click="exportPDF" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors border border-gray-200">
                            <DocumentArrowDownIcon class="w-4 h-4 mr-2" />
                            Export PDF
                        </button>
                    </div>
                </div>
            </div>

            <!-- Performance Overview -->
            <div v-if="reportData.length > 0" class="grid grid-cols-1 gap-6">
                <div v-for="user in reportData" :key="user.user_id" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">{{ user.user_name }}</h3>
                            <p class="text-xs text-gray-500 font-bold uppercase">{{ user.sub_unit || 'No Sub-Unit' }}</p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-black text-blue-600">{{ user.total_tickets }}</span>
                            <p class="text-[10px] text-gray-400 font-black uppercase">Total Tickets</p>
                        </div>
                    </div>
                    
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Response Performance -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <h4 class="text-sm font-black text-gray-700 uppercase tracking-widest flex items-center">
                                    <ClockIcon class="w-4 h-4 mr-2 text-blue-500" />
                                    Target Response
                                </h4>
                                <span class="text-2xl font-black" :class="getPercentageColor(user.response.percentage)">
                                    {{ user.response.percentage }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden shadow-inner">
                                <div 
                                    class="h-full transition-all duration-1000 shadow-sm"
                                    :class="getProgressColor(user.response.percentage)"
                                    :style="{ width: user.response.percentage + '%' }"
                                ></div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'response', 'met')"
                                    class="bg-green-50 p-2 rounded-lg border border-green-100 text-center hover:bg-green-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-green-600 uppercase group-hover:underline">Met</p>
                                    <p class="text-sm font-bold text-green-700">{{ user.response.met }}</p>
                                </button>
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'response', 'breached')"
                                    class="bg-red-50 p-2 rounded-lg border border-red-100 text-center hover:bg-red-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-red-600 uppercase group-hover:underline">Breached</p>
                                    <p class="text-sm font-bold text-red-700">{{ user.response.breached }}</p>
                                </button>
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'response', 'pending')"
                                    class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-center hover:bg-blue-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-blue-600 uppercase group-hover:underline">Pending</p>
                                    <p class="text-sm font-bold text-blue-700">{{ user.response.pending }}</p>
                                </button>
                            </div>
                        </div>

                        <!-- Resolution Performance -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <h4 class="text-sm font-black text-gray-700 uppercase tracking-widest flex items-center">
                                    <CheckCircleIcon class="w-4 h-4 mr-2 text-purple-500" />
                                    Target Resolution
                                </h4>
                                <span class="text-2xl font-black" :class="getPercentageColor(user.resolution.percentage)">
                                    {{ user.resolution.percentage }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden shadow-inner">
                                <div 
                                    class="h-full transition-all duration-1000 shadow-sm"
                                    :class="getProgressColor(user.resolution.percentage)"
                                    :style="{ width: user.resolution.percentage + '%' }"
                                ></div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'resolution', 'met')"
                                    class="bg-green-50 p-2 rounded-lg border border-green-100 text-center hover:bg-green-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-green-600 uppercase group-hover:underline">Met</p>
                                    <p class="text-sm font-bold text-green-700">{{ user.resolution.met }}</p>
                                </button>
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'resolution', 'breached')"
                                    class="bg-red-50 p-2 rounded-lg border border-red-100 text-center hover:bg-red-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-red-600 uppercase group-hover:underline">Breached</p>
                                    <p class="text-sm font-bold text-red-700">{{ user.resolution.breached }}</p>
                                </button>
                                <button 
                                    @click="fetchTickets(user.user_id, user.user_name, 'resolution', 'pending')"
                                    class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-center hover:bg-blue-100 transition-colors group"
                                >
                                    <p class="text-[10px] font-black text-blue-600 uppercase group-hover:underline">Pending</p>
                                    <p class="text-sm font-bold text-blue-700">{{ user.resolution.pending }}</p>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="bg-white p-12 rounded-lg shadow-sm border border-gray-200 text-center">
                <ChartBarIcon class="w-12 h-12 text-gray-300 mx-auto mb-4" />
                <h3 class="text-lg font-medium text-gray-900">No performance data found</h3>
                <p class="text-gray-500">Try adjusting your filters or date range.</p>
            </div>
        </div>

        <!-- Tickets Modal -->
        <Modal :show="showTicketsModal" @close="showTicketsModal = false" maxWidth="3xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6 border-b pb-4">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        {{ modalTitle }}
                        <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full" v-if="!modalLoading">
                            {{ selectedTickets.length }}
                        </span>
                    </h2>
                    <button @click="showTicketsModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div v-if="modalLoading" class="flex flex-col items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600 mb-4"></div>
                    <p class="text-gray-500 text-sm">Loading tickets...</p>
                </div>

                <div v-else class="max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <table class="min-w-full divide-y divide-gray-200" v-if="selectedTickets.length > 0">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket #</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Subject/Title</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Created</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="ticket in selectedTickets" :key="ticket.id" class="hover:bg-blue-50 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-600">
                                    <Link :href="route('tickets.edit', ticket.id)" class="hover:underline">
                                        {{ ticket.ticket_key }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900">
                                    <Link :href="route('tickets.edit', ticket.id)" class="hover:underline line-clamp-1">
                                        {{ ticket.title }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-full" 
                                          :class="{
                                              'bg-green-100 text-green-700': ticket.status === 'open',
                                              'bg-blue-100 text-blue-700': ticket.status === 'in_progress',
                                              'bg-green-100 text-green-800': ticket.status === 'resolved',
                                              'bg-gray-100 text-gray-700': ticket.status === 'closed',
                                              'bg-yellow-100 text-yellow-700': ticket.status === 'waiting_service_provider',
                                              'bg-blue-100 text-blue-700': ticket.status === 'waiting_client_feedback'
                                          }">
                                        {{ getStatusLabel(ticket.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ new Date(ticket.created_at).toLocaleDateString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="text-center py-12 text-gray-500 italic">
                        No tickets found for this selection.
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button @click="showTicketsModal = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors text-sm font-medium">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
