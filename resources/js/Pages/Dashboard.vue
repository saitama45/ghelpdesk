<script setup>
import { Head, usePage, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    recentTickets: Array,
    recentActivity: Array,
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'bg-blue-100 text-blue-800';
        case 'in_progress': return 'bg-yellow-100 text-yellow-800';
        case 'closed': return 'bg-green-100 text-green-800';
        case 'waiting': return 'bg-gray-100 text-gray-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};

const getPriorityColor = (priority) => {
    switch (priority) {
        case 'high': return 'text-red-600 bg-red-50';
        case 'urgent': return 'text-red-700 bg-red-100';
        case 'medium': return 'text-yellow-600 bg-yellow-50';
        case 'low': return 'text-green-600 bg-green-50';
        default: return 'text-gray-600 bg-gray-50';
    }
};
</script>

<template>
    <Head title="Dashboard - Help Desk" />

    <AppLayout>
        <template #header>
            Dashboard
        </template>

        <!-- Welcome Section -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">Welcome back, {{ user.name }}!</h2>
                    <p class="text-gray-600 mt-1">Here's an overview of your help desk status.</p>
                </div>
                <div class="text-right">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                        {{ user.roles?.[0]?.name || 'User' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-sm p-6 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Tickets</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2">{{ stats.total }}</p>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-full">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6 border-l-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Open Tickets</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2">{{ stats.open }}</p>
                    </div>
                    <div class="p-3 bg-yellow-50 rounded-full">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6 border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">In Progress</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2">{{ stats.in_progress }}</p>
                    </div>
                    <div class="p-3 bg-purple-50 rounded-full">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6 border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Resolved</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2">{{ stats.closed }}</p>
                    </div>
                    <div class="p-3 bg-green-50 rounded-full">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Tickets -->
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-medium text-gray-900">Recent Tickets</h3>
                    <Link :href="route('tickets.index')" class="text-sm text-blue-600 hover:text-blue-800">View All</Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Key</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assignee</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="ticket in recentTickets" :key="ticket.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                    <Link :href="route('tickets.show', ticket.id)">{{ ticket.key }}</Link>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <Link :href="route('tickets.show', ticket.id)" class="hover:underline">{{ ticket.title }}</Link>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(ticket.status)]">
                                        {{ ticket.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getPriorityColor(ticket.priority)]">
                                        {{ ticket.priority }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ ticket.assignee }}
                                </td>
                            </tr>
                            <tr v-if="recentTickets.length === 0">
                                <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No tickets found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Recent Activity</h3>
                </div>
                <div class="p-6">
                    <div class="flow-root">
                        <ul class="-mb-8">
                            <li v-for="(activity, index) in recentActivity" :key="activity.id">
                                <div class="relative pb-8">
                                    <span v-if="index !== recentActivity.length - 1" class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                    <div class="relative flex space-x-3">
                                        <div>
                                            <span class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center ring-8 ring-white">
                                                <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                            <div>
                                                <p class="text-sm text-gray-500">
                                                    <span class="font-medium text-gray-900">{{ activity.user }}</span> 
                                                    {{ activity.action }} 
                                                    <Link :href="route('tickets.show', activity.ticket_key)" class="font-medium text-gray-900 hover:underline">{{ activity.ticket_key }}</Link>
                                                </p>
                                            </div>
                                            <div class="text-right text-sm whitespace-nowrap text-gray-500">
                                                <time :datetime="activity.time">{{ activity.time }}</time>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <li v-if="recentActivity.length === 0" class="text-center text-gray-500 text-sm py-4">
                                No recent activity.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>