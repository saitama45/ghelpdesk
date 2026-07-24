<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from '@/Composables/useToast.js';

const props = defineProps({
    canManage: { type: Boolean, default: false },
    assetRequests: { type: Array, default: () => [] },
    assetMaster: { type: Object, default: () => ({ total: 0, route: 'assets.index', rows: [] }) },
    stockIn: { type: Object, default: () => ({ total: 0, route: 'stock-ins.index', rows: [] }) },
    assetMovement: { type: Object, default: () => ({ total: 0, route: 'stock-transfers.index', rows: [] }) },
    receivingStock: { type: Object, default: () => ({ total: 0, route: 'stock-receivings.index', rows: [] }) },
    assetManagement: { type: Object, default: () => ({ total_locations: 0, route: 'reports.inventory', rows: [] }) },
});

const page = usePage();
const { showSuccess, showError } = useToast();

const tabs = [
    { id: 'requests', label: 'Asset Requests' },
    { id: 'master', label: 'Asset Master' },
    { id: 'stockin', label: 'Stock In' },
    { id: 'movement', label: 'Asset Movement' },
    { id: 'receiving', label: 'Receiving Stock' },
    { id: 'management', label: 'Asset Management' },
];
const tab = ref('requests');

const accent = computed(() => page.props.departmentContext?.accent || '#0b948c');

const statusClass = (s) => ({
    'Pending Approval': 'bg-amber-100 text-amber-800',
    'Stock Available': 'bg-emerald-100 text-emerald-800',
    'Incoming': 'bg-blue-100 text-blue-800',
    'Approved': 'bg-blue-100 text-blue-800',
    'Received': 'bg-indigo-100 text-indigo-800',
    'For Setup': 'bg-indigo-100 text-indigo-800',
    'Deployed': 'bg-emerald-100 text-emerald-800',
}[s] || 'bg-gray-100 text-gray-700');

const actionLabel = (a) => ({
    approve: 'Approve',
    reserve: 'Reserve & release',
    receive: 'Accept & add to stock',
    setup: 'Start setup',
    deploy: 'Deploy',
}[a] || '');

const busy = ref(null);
const advance = (row) => {
    if (!row.action || busy.value) return;
    busy.value = row.id;
    router.post(route('inventory-workspace.advance', row.id), { action: row.action }, {
        preserveScroll: true,
        onSuccess: () => showSuccess('Request updated.'),
        onError: () => showError('Could not update the request.'),
        onFinish: () => { busy.value = null; },
    });
};

const summaryTabs = computed(() => ({
    master: props.assetMaster,
    stockin: props.stockIn,
    movement: props.assetMovement,
    receiving: props.receivingStock,
}));
</script>

<template>
    <AppLayout title="Inventory Management" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <Head title="Inventory Management" />

        <div class="py-6 space-y-5">
            <!-- Header -->
            <div>
                <Link :href="route('hub.show', 'services')" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">← Services</Link>
                <div class="mt-2 text-[10px] font-black uppercase tracking-[0.2em]" :style="{ color: accent }">TAS Work Tool</div>
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Inventory Management</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">One workspace for asset governance and end-to-end stock movement.</p>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 overflow-x-auto border-b border-gray-200 no-scrollbar dark:border-gray-700">
                <button
                    v-for="t in tabs"
                    :key="t.id"
                    type="button"
                    @click="tab = t.id"
                    :style="tab === t.id ? { color: accent, borderColor: accent } : {}"
                    :class="['-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-bold transition-colors',
                        tab === t.id ? '' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200']"
                >{{ t.label }}</button>
            </div>

            <!-- Asset Requests: the procurement queue -->
            <div v-show="tab === 'requests'" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <div class="text-sm font-bold text-gray-900 dark:text-white">Asset Request &amp; Stock Validation</div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Ticket and health-check requests validated against stock before approval and fulfilment.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-[10px] font-black uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                <th class="px-4 py-2">Source Ticket</th>
                                <th class="px-4 py-2">Store</th>
                                <th class="px-4 py-2">Asset</th>
                                <th class="px-4 py-2">Stock</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in assetRequests" :key="r.id" class="border-b border-gray-50 hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-700/30">
                                <td class="px-4 py-2">
                                    <Link v-if="r.ticket_id" :href="route('tickets.edit', r.ticket_id)" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">{{ r.ticket_key }}</Link>
                                    <span v-else class="text-xs text-gray-400">—</span>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ r.store || '—' }}</td>
                                <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ r.asset }}</td>
                                <td class="px-4 py-2">
                                    <span :class="r.soh === 0 ? 'font-black text-red-600 dark:text-red-400' : 'font-bold text-gray-700 dark:text-gray-200'">{{ r.soh }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="statusClass(r.status)">{{ r.status }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <button
                                        v-if="r.action && canManage"
                                        type="button"
                                        @click="advance(r)"
                                        :disabled="busy === r.id"
                                        class="rounded-lg px-2.5 py-1 text-[11px] font-black uppercase tracking-wider text-white disabled:opacity-60"
                                        :style="{ backgroundColor: accent }"
                                    >{{ busy === r.id ? '…' : actionLabel(r.action) }}</button>
                                    <span v-else-if="r.action" class="text-[10px] font-semibold text-gray-400">Awaiting TAS</span>
                                    <span v-else class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400">✓ {{ r.status }}</span>
                                </td>
                            </tr>
                            <tr v-if="!assetRequests.length">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">No purchase requests in the queue.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Generic summary tabs (Asset Master, Stock In, Asset Movement, Receiving Stock) -->
            <div v-for="(data, key) in summaryTabs" :key="key" v-show="tab === key" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <div class="text-sm font-bold text-gray-900 dark:text-white">
                        {{ tabs.find(t => t.id === key)?.label }}
                        <span class="ml-1 text-xs font-semibold text-gray-400">· {{ data.total }} total</span>
                    </div>
                    <Link :href="route(data.route)" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">Open full module →</Link>
                </div>
                <ul class="divide-y divide-gray-50 dark:divide-gray-700/50">
                    <li v-for="row in data.rows" :key="row.id" class="flex items-center gap-3 px-4 py-2.5">
                        <span class="font-mono text-xs font-bold text-gray-700 dark:text-gray-200 shrink-0">{{ row.code }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-600 dark:text-gray-300">{{ row.label || '—' }}</span>
                        <span v-if="row.meta" class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ row.meta }}</span>
                    </li>
                    <li v-if="!data.rows.length" class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">No records yet.</li>
                </ul>
            </div>

            <!-- Asset Management: inventory by location -->
            <div v-show="tab === 'management'" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                    <div class="text-sm font-bold text-gray-900 dark:text-white">Inventory by Location <span class="ml-1 text-xs font-semibold text-gray-400">· {{ assetManagement.total_locations }} locations</span></div>
                    <Link :href="route(assetManagement.route)" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">Open full report →</Link>
                </div>
                <div class="grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="loc in assetManagement.rows" :key="loc.location" class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-700">
                        <span class="truncate text-sm text-gray-700 dark:text-gray-200">{{ loc.location }}</span>
                        <span class="shrink-0 font-black text-gray-900 dark:text-white">{{ loc.soh }}</span>
                    </div>
                    <div v-if="!assetManagement.rows.length" class="col-span-full px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">No stock on hand.</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { height: 0; }
.no-scrollbar { scrollbar-width: none; }
</style>
