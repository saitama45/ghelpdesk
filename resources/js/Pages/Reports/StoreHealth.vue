<script setup>
import { ref, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { FunnelIcon, PrinterIcon, XMarkIcon, DocumentArrowDownIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
    reportData: Array,
    summary: Object,
    users: Array,
    thresholds: Object,
    filters: Object
});

const filterForm = ref({
    user_id: props.filters.user_id,
    month_range: props.filters.month_range
});

const getSummaryBoxColor = (maxTickets) => {
    if (maxTickets === 0) return { class: 'bg-white border-gray-200', style: 'background-color: #ffffff' };
    
    const s = props.thresholds || {};
    const th = {
        green_max: parseInt(s.threshold_green_max) || 2,
        yellow_min: parseInt(s.threshold_yellow_min) || 3,
        orange_min: parseInt(s.threshold_orange_min) || 4,
        red_min: parseInt(s.threshold_red_min) || 5,
    };

    if (maxTickets >= th.red_min) return { class: 'bg-red-500 border-red-600 text-white', style: 'background-color: #ef4444' };
    if (maxTickets === th.orange_min) return { class: 'bg-orange-500 border-orange-600 text-white', style: 'background-color: #f97316' };
    if (maxTickets === th.yellow_min) return { class: 'bg-yellow-500 border-yellow-600 text-gray-900', style: 'background-color: #eab308' };
    if (maxTickets >= 1 && maxTickets <= th.green_max) return { class: 'bg-green-500 border-green-600 text-white', style: 'background-color: #22c55e' };
    
    return { class: 'bg-white border-gray-200', style: 'background-color: #ffffff' };
};

const showTicketsModal = ref(false);
const modalLoading = ref(false);
const selectedStoreTickets = ref([]);
const selectedStoreName = ref('');

const fetchTickets = async (storeId) => {
    modalLoading.value = true;
    showTicketsModal.value = true;
    try {
        const response = await axios.get(route('reports.store-health.tickets', storeId), {
            params: { month_range: props.filters.month_range }
        });
        selectedStoreTickets.value = response.data.tickets;
        selectedStoreName.value = response.data.store_name;
    } catch (error) {
        console.error('Error fetching tickets:', error);
    } finally {
        modalLoading.value = false;
    }
};

const applyFilters = () => {
    router.get(route('reports.store-health'), filterForm.value, {
        preserveState: true,
        preserveScroll: true
    });
};

const getHealthStatus = (ticketCount) => {
    const s = props.thresholds || {};
    
    const th = {
        green: { min: parseInt(s.threshold_green_min) || 1, max: parseInt(s.threshold_green_max) || 2, color: 'bg-green-500' },
        yellow: { min: parseInt(s.threshold_yellow_min) || 3, max: parseInt(s.threshold_yellow_max) || 3, color: 'bg-yellow-500' },
        orange: { min: parseInt(s.threshold_orange_min) || 4, max: parseInt(s.threshold_orange_max) || 4, color: 'bg-orange-500' },
        red: { min: parseInt(s.threshold_red_min) || 5, color: 'bg-red-500' }
    };

    if (ticketCount >= th.red.min) return th.red;
    if (ticketCount >= th.orange.min && (th.orange.max ? ticketCount <= th.orange.max : true)) return th.orange;
    if (ticketCount >= th.yellow.min && (th.yellow.max ? ticketCount <= th.yellow.max : true)) return th.yellow;
    if (ticketCount >= th.green.min && (th.green.max ? ticketCount <= th.green.max : true)) return th.green;
    
    return { color: 'bg-gray-200' };
};

const exportPDF = () => {
    const params = new URLSearchParams(filterForm.value).toString();
    window.open(route('reports.store-health.pdf') + '?' + params, '_blank');
};
</script>

<template>
    <Head title="Store Health Report" />

    <AppLayout>
        <template #header>
            Store Health Report
        </template>

        <div class="space-y-6 print:space-y-0">
            <!-- Filters Card -->
            <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 print:hidden">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Filter by User</label>
                        <select v-model="filterForm.user_id" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all">All Users</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Month Range</label>
                        <input type="month" v-model="filterForm.month_range" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div class="flex space-x-2">
                        <button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors">
                            <FunnelIcon class="w-4 h-4 mr-2" />
                            Generate
                        </button>
                        <button @click="exportPDF" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors border border-gray-200">
                            <DocumentArrowDownIcon class="w-4 h-4 mr-2" />
                            Export PDF
                        </button>
                    </div>
                </div>
            </div>

            <!-- Area Summary Section -->
            <div class="space-y-8 mb-8">
                <!-- North Area -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <table class="w-full border-collapse table-fixed">
                        <thead>
                            <tr class="bg-gray-800">
                                <th colspan="4" class="py-2.5 text-sm font-bold text-white tracking-[0.5em] text-center uppercase border-b border-gray-700">
                                    N O R T H &nbsp;&nbsp; A R E A
                                </th>
                            </tr>
                            <tr class="bg-gray-50">
                                <th v-for="item in summary.north" :key="item.sector" class="py-2 text-[10px] font-bold text-gray-600 uppercase tracking-wider border-r last:border-0 border-gray-200">
                                    Sector {{ item.sector }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td v-for="item in summary.north" :key="item.sector" class="py-3 px-2 text-center border-r last:border-0 border-gray-200 h-12 align-middle">
                                    <span class="text-xs font-bold text-blue-600 truncate block" :title="item.user">{{ item.user }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td v-for="item in summary.north" :key="item.sector" 
                                    class="py-8 px-2 text-center border-r last:border-0 border-gray-200 align-middle text-2xl font-black transition-all duration-500 shadow-inner"
                                    :class="getSummaryBoxColor(item.max_tickets).class"
                                    :style="getSummaryBoxColor(item.max_tickets).style"
                                >
                                    {{ item.max_tickets }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- South Area -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <table class="w-full border-collapse table-fixed">
                        <thead>
                            <tr class="bg-gray-800">
                                <th colspan="4" class="py-2.5 text-sm font-bold text-white tracking-[0.5em] text-center uppercase border-b border-gray-700">
                                    S O U T H &nbsp;&nbsp; A R E A
                                </th>
                            </tr>
                            <tr class="bg-gray-50">
                                <th v-for="item in summary.south" :key="item.sector" class="py-2 text-[10px] font-bold text-gray-600 uppercase tracking-wider border-r last:border-0 border-gray-200">
                                    Sector {{ item.sector }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td v-for="item in summary.south" :key="item.sector" class="py-3 px-2 text-center border-r last:border-0 border-gray-200 h-12 align-middle">
                                    <span class="text-xs font-bold text-blue-600 truncate block" :title="item.user">{{ item.user }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td v-for="item in summary.south" :key="item.sector" 
                                    class="py-8 px-2 text-center border-r last:border-0 border-gray-200 align-middle text-2xl font-black transition-all duration-500 shadow-inner"
                                    :class="getSummaryBoxColor(item.max_tickets).class"
                                    :style="getSummaryBoxColor(item.max_tickets).style"
                                >
                                    {{ item.max_tickets }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Report Content -->
            <div v-if="reportData.length > 0" class="space-y-8">
                <div v-for="userData in reportData" :key="userData.id" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden break-inside-avoid">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-bold text-gray-900">{{ userData.name }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Store Code</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Section #</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">IT Area</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket Count</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-1/3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="store in userData.stores" :key="store.id" class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-blue-600">{{ store.code }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">Sector {{ store.sector }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ store.area }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-center">
                                        <button 
                                            v-if="store.ticket_count > 0"
                                            @click="fetchTickets(store.id)"
                                            class="text-blue-600 hover:text-blue-800 hover:underline px-2 py-1 rounded hover:bg-blue-50 transition-colors"
                                        >
                                            {{ store.ticket_count }}
                                        </button>
                                        <span v-else>{{ store.ticket_count }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden shadow-inner">
                                            <div 
                                                class="h-full transition-all duration-500 shadow-sm"
                                                :class="getHealthStatus(store.ticket_count).color"
                                                :style="{ width: Math.min(100, (store.ticket_count / 10) * 100) + '%' }"
                                            ></div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="bg-white p-12 rounded-lg shadow-sm border border-gray-200 text-center">
                <ChartBarIcon class="w-12 h-12 text-gray-300 mx-auto mb-4" />
                <h3 class="text-lg font-medium text-gray-900">No data found</h3>
                <p class="text-gray-500">Try adjusting your filters to see results.</p>
            </div>
        </div>

        <!-- Tickets Modal -->
        <Modal :show="showTicketsModal" @close="showTicketsModal = false" maxWidth="3xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6 border-b pb-4">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        Tickets for {{ selectedStoreName }}
                        <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full" v-if="!modalLoading">
                            {{ selectedStoreTickets.length }}
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
                    <table class="min-w-full divide-y divide-gray-200" v-if="selectedStoreTickets.length > 0">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket #</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Subject/Title</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Created</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="ticket in selectedStoreTickets" :key="ticket.id" class="hover:bg-blue-50 transition-colors">
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
                                              'bg-gray-100 text-gray-700': ticket.status === 'closed',
                                              'bg-yellow-100 text-yellow-700': ticket.status === 'waiting'
                                          }">
                                        {{ ticket.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ new Date(ticket.created_at).toLocaleDateString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="text-center py-12 text-gray-500 italic">
                        No tickets found for this period.
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

<style>
@media print {
    body {
        background: white !important;
    }
    .print\:hidden {
        display: none !important;
    }
    .print\:space-y-0 {
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
    .shadow-sm, .shadow-inner {
        box-shadow: none !important;
        border: 1px solid #e5e7eb !important;
    }
}
</style>
