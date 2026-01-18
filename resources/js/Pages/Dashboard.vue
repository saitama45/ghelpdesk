<script setup>
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    recentTickets: Array,
    myTickets: Array,
    recentActivity: Array,
    filters: Object,
    years: Array,
    months: Array,
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const filterForm = reactive({
    year: props.filters.year || '',
    month: props.filters.month || '',
});

const applyFilters = () => {
    router.get(route('dashboard'), {
        year: filterForm.year,
        month: filterForm.month,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
};

const clearFilters = () => {
    filterForm.year = '';
    filterForm.month = '';
    applyFilters();
};

watch(() => [filterForm.year, filterForm.month], () => {
    applyFilters();
});

const truncate = (text, length = 100) => {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'bg-blue-100 text-blue-800';
        case 'in_progress': return 'bg-purple-100 text-purple-800';
        case 'closed': return 'bg-gray-100 text-gray-800';
        case 'waiting': return 'bg-orange-100 text-orange-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

const getPriorityColor = (priority) => {
    switch (priority) {
        case 'urgent': return 'text-red-900 bg-red-100';
        case 'high': return 'text-red-800 bg-red-50';
        case 'medium': return 'text-yellow-800 bg-yellow-50';
        case 'low': return 'text-green-800 bg-green-50';
        default: return 'text-gray-600 bg-gray-50';
    }
};
</script>

<template>
    <Head title="Dashboard - Help Desk" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <span class="text-xl font-bold text-gray-900">Dashboard</span>
                
                <div class="flex items-center space-x-2">
                    <div class="flex items-center bg-white border border-gray-300 rounded-lg shadow-sm px-2">
                        <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 8.293A1 1 0 013 7.586V4z" /></svg>
                        
                        <select v-model="filterForm.year" class="border-0 focus:ring-0 text-sm py-1.5 bg-transparent">
                            <option value="">All Years</option>
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                        
                        <div class="w-px h-4 bg-gray-300 mx-1"></div>
                        
                        <select v-model="filterForm.month" class="border-0 focus:ring-0 text-sm py-1.5 bg-transparent">
                            <option value="">All Months</option>
                            <option v-for="m in months" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>

                        <button v-if="filterForm.year || filterForm.month" @click="clearFilters" class="ml-2 p-1 text-gray-400 hover:text-red-500 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Welcome Section -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl shadow-lg p-6 mb-8 text-white">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div v-if="user.profile_photo" class="w-16 h-16 rounded-full border-4 border-white/30 overflow-hidden shadow-inner">
                        <img :src="'/storage/' + user.profile_photo" class="w-full h-full object-cover" :alt="user.name">
                    </div>
                    <div v-else class="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center text-2xl font-bold border-4 border-white/30">
                        {{ user.name.charAt(0) }}
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold">Welcome back, {{ user.name }}!</h2>
                        <p class="text-blue-100 mt-0.5">You have {{ stats.open + stats.in_progress }} active tickets needing attention.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-5 border-b-4 border-blue-500 flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Total</p>
                    <div class="p-2 bg-blue-50 rounded-lg"><svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg></div>
                </div>
                <p class="text-3xl font-black text-gray-900 mt-2">{{ stats.total }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-5 border-b-4 border-yellow-500 flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Open</p>
                    <div class="p-2 bg-yellow-50 rounded-lg"><svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                </div>
                <p class="text-3xl font-black text-gray-900 mt-2">{{ stats.open }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-5 border-b-4 border-purple-500 flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">In Progress</p>
                    <div class="p-2 bg-purple-50 rounded-lg"><svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></div>
                </div>
                <p class="text-3xl font-black text-gray-900 mt-2">{{ stats.in_progress }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-5 border-b-4 border-red-500 flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Unassigned</p>
                    <div class="p-2 bg-red-50 rounded-lg"><svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg></div>
                </div>
                <p class="text-3xl font-black text-red-600 mt-2">{{ stats.unassigned }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-5 border-b-4 border-green-500 flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Resolved</p>
                    <div class="p-2 bg-green-50 rounded-lg"><svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>
                </div>
                <p class="text-3xl font-black text-gray-900 mt-2">{{ stats.closed }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-8">
                <!-- My Assigned Tickets -->
                <div v-if="myTickets.length > 0" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            <h3 class="text-lg font-bold text-gray-900">Assigned to Me</h3>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Priority</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Updated</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <tr v-for="ticket in myTickets" :key="ticket.id" class="hover:bg-blue-50/30 transition-colors cursor-pointer" @click="router.visit(route('tickets.edit', ticket.id))">
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-blue-600">{{ ticket.key }}</span>
                                            <span class="text-sm text-gray-900 line-clamp-1">{{ ticket.title }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ ticket.company_name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full border', getStatusColor(ticket.status)]">
                                            {{ ticket.status.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full capitalize', getPriorityColor(ticket.priority)]">
                                            {{ ticket.priority }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-400 font-medium">{{ ticket.updated_at }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Tickets -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                            <h3 class="text-lg font-bold text-gray-900">Recent Tickets</h3>
                        </div>
                        <Link :href="route('tickets.index')" class="text-sm font-bold text-blue-600 hover:text-blue-800 transition-colors">View All &rarr;</Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Assignee</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Priority</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <tr v-for="ticket in recentTickets" :key="ticket.id" class="hover:bg-gray-50/50 transition-colors cursor-pointer" @click="router.visit(route('tickets.edit', ticket.id))">
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col">
                                            <div class="flex items-center space-x-2">
                                                <span class="text-sm font-bold text-blue-600">{{ ticket.key }}</span>
                                                <span class="text-[10px] text-gray-400 font-bold uppercase">{{ ticket.company_name }}</span>
                                            </div>
                                            <span class="text-sm text-gray-900 font-medium line-clamp-1">{{ ticket.title }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">{{ ticket.assignee }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full border', getStatusColor(ticket.status)]">
                                            {{ ticket.status.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="['px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full capitalize', getPriorityColor(ticket.priority)]">
                                            {{ ticket.priority }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="recentTickets.length === 0">
                                    <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-400 italic">
                                        No tickets found in your current workspace.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col">
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-900">Activity Stream</h3>
                </div>
                <div class="p-6 flex-grow">
                    <div class="flow-root">
                        <ul class="-mb-8">
                            <li v-for="(activity, index) in recentActivity" :key="activity.type + '-' + activity.id">
                                <div class="relative pb-8">
                                    <span v-if="index !== recentActivity.length - 1" class="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-100" aria-hidden="true"></span>
                                    <div class="relative flex items-start space-x-3">
                                        <div class="relative">
                                            <div v-if="activity.user_photo" class="h-10 w-10 rounded-full border-2 border-white shadow-sm overflow-hidden bg-gray-100">
                                                <img :src="'/storage/' + activity.user_photo" class="h-full w-full object-cover" :alt="activity.user">
                                            </div>
                                            <div v-else class="h-10 w-10 rounded-full bg-blue-100 border-2 border-white shadow-sm flex items-center justify-center text-blue-600 font-bold text-sm">
                                                {{ activity.user.charAt(0) }}
                                            </div>
                                            <!-- Type Icon Badge -->
                                            <span class="absolute -bottom-1 -right-1 h-5 w-5 rounded-full border-2 border-white shadow-sm flex items-center justify-center" :class="activity.type === 'comment' ? 'bg-green-500' : 'bg-blue-500'">
                                                <svg v-if="activity.type === 'comment'" class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd" /></svg>
                                                <svg v-else class="h-2.5 w-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            </span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm">
                                                <span class="font-bold text-gray-900">{{ activity.user }}</span>
                                                <span class="text-gray-500 ml-1">{{ activity.action }}</span>
                                                <Link :href="route('tickets.edit', activity.ticket_id)" class="ml-1 font-bold text-blue-600 hover:underline">{{ activity.ticket_key }}</Link>
                                            </div>
                                            <p v-if="activity.type === 'comment'" class="mt-1 text-sm text-gray-600 bg-gray-50 p-2 rounded-lg border border-gray-100 italic line-clamp-2">
                                                "{{ truncate(activity.comment_text, 80) }}"
                                            </p>
                                            <div class="mt-1 flex items-center space-x-2 text-xs text-gray-400 font-medium">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                <span>{{ activity.time }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <li v-if="recentActivity.length === 0" class="text-center py-12">
                                <div class="bg-gray-50 rounded-full h-12 w-12 flex items-center justify-center mx-auto mb-3">
                                    <svg class="h-6 w-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                <p class="text-sm text-gray-400 italic">No recent activity detected.</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>