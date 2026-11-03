<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    tickets: { type: Array, default: () => [] },
    // Off inside one store's own list, where every row would repeat that store.
    showStore: { type: Boolean, default: true },
});

const statusLabel = (status) => (status || '').replace(/_/g, ' ');

const statusClass = (status) => ({
    open: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    for_schedule: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    waiting_service_provider: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    waiting_client_feedback: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
}[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');
</script>

<template>
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900/60">
            <tr class="text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">
                <th class="px-4 py-2.5">Ticket</th>
                <th v-if="showStore" class="px-3 py-2.5">Store</th>
                <th class="px-3 py-2.5">Issue</th>
                <th class="px-3 py-2.5">Item</th>
                <th class="px-3 py-2.5">Concern</th>
                <th class="px-3 py-2.5">Assignee</th>
                <th class="px-3 py-2.5">Status</th>
                <th class="px-4 py-2.5 whitespace-nowrap">Created</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-for="ticket in tickets" :key="ticket.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <Link :href="ticket.url" class="font-black text-blue-600 hover:underline dark:text-blue-400">{{ ticket.key }}</Link>
                </td>
                <td v-if="showStore" class="px-3 py-2.5 text-gray-700 whitespace-nowrap dark:text-gray-300">{{ ticket.store || '—' }}</td>
                <td class="px-3 py-2.5 text-gray-600 max-w-xs truncate dark:text-gray-400" :title="ticket.title">{{ ticket.title }}</td>
                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-400">{{ ticket.item }}</td>
                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-500">{{ ticket.concern_type || '—' }}</td>
                <td class="px-3 py-2.5 text-gray-600 whitespace-nowrap dark:text-gray-400">{{ ticket.assignee }}</td>
                <td class="px-3 py-2.5">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase whitespace-nowrap" :class="statusClass(ticket.status)">
                        {{ statusLabel(ticket.status) }}
                    </span>
                </td>
                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap dark:text-gray-400">{{ ticket.created_at }}</td>
            </tr>
        </tbody>
    </table>
</template>
