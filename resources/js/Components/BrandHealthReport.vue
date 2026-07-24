<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';

const props = defineProps({
    data: { type: Object, default: null },
});
const emit = defineEmits(['changed']);

const { showSuccess, showError } = useToast();
const { confirm } = useConfirm();

// 'summary' or a brand id (as string).
const activeView = ref('summary');
const acting = ref(null); // ticket id currently being actioned

const brands = computed(() => props.data?.brands || []);
const totals = computed(() => props.data?.totals || null);
const thresholds = computed(() => props.data?.thresholds || {});
const canClose = computed(() => !!props.data?.can_close);
const canReopen = computed(() => !!props.data?.can_reopen);

const activeBrand = computed(() =>
    activeView.value === 'summary'
        ? null
        : brands.value.find(b => String(b.id) === String(activeView.value)) || null
);

// Band visual metadata (colour + label), keyed green/yellow/orange/red.
const BAND_META = {
    green:  { dot: 'bg-emerald-500', bar: 'bg-emerald-500', fallback: 'Healthy' },
    yellow: { dot: 'bg-amber-400',   bar: 'bg-amber-400',   fallback: 'Warning' },
    orange: { dot: 'bg-orange-500',  bar: 'bg-orange-500',  fallback: 'At-risk' },
    red:    { dot: 'bg-red-500',     bar: 'bg-red-500',     fallback: 'Critical' },
};
const BAND_ORDER = ['green', 'yellow', 'orange', 'red'];

const bandLabel = (key) => thresholds.value?.[key]?.label || BAND_META[key].fallback;

// Turn a {green,yellow,orange,red} count object into stacked-bar segments.
const healthSegments = (health) => {
    const total = BAND_ORDER.reduce((sum, k) => sum + (health?.[k] || 0), 0);
    return BAND_ORDER.map(key => {
        const count = health?.[key] || 0;
        return {
            key,
            count,
            label: bandLabel(key),
            pct: total > 0 ? Math.round((count / total) * 100) : 0,
            ...BAND_META[key],
        };
    });
};

const healthTotal = (health) => BAND_ORDER.reduce((sum, k) => sum + (health?.[k] || 0), 0);

// Workflow lanes shown as cards, in confirmation-flow order.
const WORKFLOW_LANES = [
    { key: 'open', title: 'OPEN', caption: 'TAS action',        chip: 'text-blue-600 dark:text-blue-400',   ring: 'ring-blue-100 dark:ring-blue-900/40',   bg: 'bg-blue-50 dark:bg-blue-900/20' },
    { key: 'wcf',  title: 'WCF',  caption: 'Brand confirmation', chip: 'text-sky-600 dark:text-sky-400',     ring: 'ring-sky-100 dark:ring-sky-900/40',     bg: 'bg-sky-50 dark:bg-sky-900/20' },
    { key: 'wsp',  title: 'WSP',  caption: 'Provider follow-up', chip: 'text-amber-600 dark:text-amber-400', ring: 'ring-amber-100 dark:ring-amber-900/40', bg: 'bg-amber-50 dark:bg-amber-900/20' },
];

const workflowCards = (workflow) => WORKFLOW_LANES.map(lane => ({
    ...lane,
    count: workflow?.[lane.key] || 0,
}));

// --- WCF register actions ----------------------------------------------------
const runAction = async (row, kind) => {
    const isResolve = kind === 'resolve';
    const confirmed = await confirm({
        title: isResolve ? 'Confirm Resolved' : 'Mark Not Resolved',
        message: isResolve
            ? `Close ${row.key}? The brand has confirmed the issue is resolved.`
            : `Return ${row.key} to Open? The brand reported the issue is not resolved.`,
        confirmLabel: isResolve ? 'Resolve & Close' : 'Reopen',
    });
    if (!confirmed) return;

    acting.value = row.id;
    const url = isResolve
        ? route('dashboard.brand-health.wcf.resolve', row.id)
        : route('dashboard.brand-health.wcf.reopen', row.id);

    router.post(url, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showSuccess(isResolve ? `${row.key} closed.` : `${row.key} reopened.`);
            emit('changed');
        },
        onError: () => showError('Unable to update the ticket.'),
        onFinish: () => { acting.value = null; },
    });
};
</script>

<template>
    <div v-if="!data" class="py-16 text-center text-sm text-gray-400 dark:text-gray-500">
        Loading brand health…
    </div>

    <div v-else>
        <!-- Brand sub-tabs: Summary + one per brand -->
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <button
                @click="activeView = 'summary'"
                class="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-wider transition-colors border"
                :class="activeView === 'summary'
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-gray-500 border-gray-200 hover:text-gray-700 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'"
            >
                Summary
            </button>
            <button
                v-for="brand in brands"
                :key="brand.id"
                @click="activeView = String(brand.id)"
                class="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-wider transition-colors border flex items-center gap-1.5"
                :class="String(activeView) === String(brand.id)
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-gray-500 border-gray-200 hover:text-gray-700 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'"
            >
                {{ brand.code || brand.name }}
                <span
                    v-if="brand.priority_stores > 0"
                    class="inline-flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full text-[10px] font-black"
                    :class="String(activeView) === String(brand.id) ? 'bg-white/25 text-white' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                >
                    {{ brand.priority_stores }}
                </span>
            </button>
            <span v-if="!brands.length" class="text-xs text-gray-400 dark:text-gray-500">
                No brands found. Set a company's Type to “Brand” to track it here.
            </span>
        </div>

        <!-- ============================ SUMMARY ============================ -->
        <template v-if="activeView === 'summary'">
            <div v-if="totals" class="space-y-6">
                <!-- KPI tiles -->
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.brands }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Brands</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-blue-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.active_tickets }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Active Tickets</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-emerald-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.total_stores }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Total Stores</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-amber-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.stores_with_tickets }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Stores w/ Tickets</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-red-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.priority_stores }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Priority Stores</p>
                    </div>
                </div>

                <!-- Health distribution + workflow -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-5 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Store Health Distribution</h4>
                            <span class="text-xs text-gray-400 dark:text-gray-500">As of {{ data.as_of }}</span>
                        </div>
                        <!-- stacked health bar -->
                        <div>
                            <div class="flex w-full h-7 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700">
                                <div v-for="seg in healthSegments(totals.health)" :key="seg.key" v-show="seg.count > 0"
                                     :class="seg.bar" :style="{ width: seg.pct + '%' }"
                                     class="flex items-center justify-center text-[10px] font-black text-white/95 transition-all"
                                     :title="`${seg.label}: ${seg.count}`">
                                    <span v-if="seg.pct >= 8">{{ seg.count }}</span>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1.5 mt-3">
                                <div v-for="seg in healthSegments(totals.health)" :key="seg.key" class="flex items-center gap-1.5">
                                    <span :class="seg.dot" class="w-2.5 h-2.5 rounded-full"></span>
                                    <span class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ seg.count }} {{ seg.label }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ seg.pct }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 dark:bg-gray-800 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider mb-4 dark:text-gray-200">Workflow Status</h4>
                        <div class="grid grid-cols-3 gap-2">
                            <div
                                v-for="card in workflowCards(totals.workflow)"
                                :key="card.key"
                                class="rounded-lg p-3 text-center ring-1"
                                :class="[card.bg, card.ring]"
                            >
                                <p class="text-[11px] font-black tracking-widest" :class="card.chip">{{ card.title }}</p>
                                <p class="text-2xl font-black text-gray-900 mt-1 dark:text-gray-100">{{ card.count }}</p>
                                <p class="text-[10px] text-gray-500 mt-0.5 dark:text-gray-400">{{ card.caption }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Per-brand comparison table -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Brands at a Glance</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr class="text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-5 py-2.5">Brand</th>
                                    <th class="px-3 py-2.5 text-right">Stores</th>
                                    <th class="px-3 py-2.5 text-right">Active</th>
                                    <th class="px-5 py-2.5 w-56">Health</th>
                                    <th class="px-3 py-2.5 text-right">Open</th>
                                    <th class="px-3 py-2.5 text-right">WCF</th>
                                    <th class="px-3 py-2.5 text-right">WSP</th>
                                    <th class="px-3 py-2.5 text-right">Priority</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr
                                    v-for="brand in brands"
                                    :key="brand.id"
                                    @click="activeView = String(brand.id)"
                                    class="cursor-pointer hover:bg-blue-50/50 transition-colors dark:hover:bg-gray-700/40"
                                >
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="h-8 w-8 rounded-full bg-gray-100 overflow-hidden flex items-center justify-center flex-shrink-0 dark:bg-gray-700">
                                                <img v-if="brand.logo" :src="`/serve-storage/${brand.logo}`" :alt="brand.name" class="h-8 w-8 object-cover" />
                                                <span v-else class="text-[11px] font-black text-gray-400">{{ (brand.code || brand.name || '?').charAt(0) }}</span>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-bold text-gray-900 truncate dark:text-gray-100">{{ brand.name }}</p>
                                                <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ brand.code }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">{{ brand.total_stores }}</td>
                                    <td class="px-3 py-3 text-right font-black text-gray-900 dark:text-gray-100">{{ brand.active_tickets }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex w-full h-2.5 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-700">
                                            <div v-for="seg in healthSegments(brand.health)" :key="seg.key" v-show="seg.count > 0"
                                                 :class="seg.bar" :style="{ width: seg.pct + '%' }"
                                                 :title="`${seg.label}: ${seg.count}`"></div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-right text-blue-600 dark:text-blue-400 font-semibold">{{ brand.workflow.open }}</td>
                                    <td class="px-3 py-3 text-right text-sky-600 dark:text-sky-400 font-semibold">{{ brand.workflow.wcf }}</td>
                                    <td class="px-3 py-3 text-right text-amber-600 dark:text-amber-400 font-semibold">{{ brand.workflow.wsp }}</td>
                                    <td class="px-3 py-3 text-right">
                                        <span
                                            class="inline-flex items-center justify-center min-w-[1.5rem] px-1.5 py-0.5 rounded-full text-xs font-black"
                                            :class="brand.priority_stores > 0
                                                ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                                : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500'"
                                        >
                                            {{ brand.priority_stores }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>

        <!-- ========================= BRAND DETAIL ========================= -->
        <template v-else-if="activeBrand">
            <div class="space-y-6">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 rounded-full bg-gray-100 overflow-hidden flex items-center justify-center flex-shrink-0 dark:bg-gray-700">
                            <img v-if="activeBrand.logo" :src="`/serve-storage/${activeBrand.logo}`" :alt="activeBrand.name" class="h-12 w-12 object-cover" />
                            <span v-else class="text-lg font-black text-gray-400">{{ (activeBrand.code || activeBrand.name || '?').charAt(0) }}</span>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-gray-900 dark:text-gray-100">{{ activeBrand.name }} Brand Health</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ activeBrand.active_tickets }} active tickets across {{ activeBrand.total_stores }} stores;
                                {{ activeBrand.priority_stores }} require priority attention.
                            </p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400 dark:text-gray-500">As of {{ data.as_of }}</span>
                </div>

                <!-- KPI tiles -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-blue-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ activeBrand.active_tickets }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Active Tickets</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-emerald-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ activeBrand.total_stores }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Total Stores</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-amber-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ activeBrand.stores_with_tickets }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Stores w/ Tickets</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-red-500 dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ activeBrand.priority_stores }}</p>
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Priority Stores</p>
                    </div>
                </div>

                <!-- Health + workflow -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm p-5 dark:bg-gray-800 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider mb-4 dark:text-gray-200">Store Health Distribution</h4>
                        <div>
                            <div class="flex w-full h-7 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700">
                                <div v-for="seg in healthSegments(activeBrand.health)" :key="seg.key" v-show="seg.count > 0"
                                     :class="seg.bar" :style="{ width: seg.pct + '%' }"
                                     class="flex items-center justify-center text-[10px] font-black text-white/95 transition-all"
                                     :title="`${seg.label}: ${seg.count}`">
                                    <span v-if="seg.pct >= 8">{{ seg.count }}</span>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1.5 mt-3">
                                <div v-for="seg in healthSegments(activeBrand.health)" :key="seg.key" class="flex items-center gap-1.5">
                                    <span :class="seg.dot" class="w-2.5 h-2.5 rounded-full"></span>
                                    <span class="text-xs font-bold text-gray-700 dark:text-gray-300">{{ seg.count }} {{ seg.label }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ seg.pct }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 dark:bg-gray-800 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider mb-4 dark:text-gray-200">Workflow Status</h4>
                        <div class="grid grid-cols-3 gap-2">
                            <div
                                v-for="card in workflowCards(activeBrand.workflow)"
                                :key="card.key"
                                class="rounded-lg p-3 text-center ring-1"
                                :class="[card.bg, card.ring]"
                            >
                                <p class="text-[11px] font-black tracking-widest" :class="card.chip">{{ card.title }}</p>
                                <p class="text-2xl font-black text-gray-900 mt-1 dark:text-gray-100">{{ card.count }}</p>
                                <p class="text-[10px] text-gray-500 mt-0.5 dark:text-gray-400">{{ card.caption }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WCF confirmation register -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-5 py-3.5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-1 dark:border-gray-700">
                        <div>
                            <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Tickets Requiring {{ activeBrand.code || activeBrand.name }} Confirmation</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Brand confirms whether the solution worked — Resolved closes it, Not Resolved returns it to Open.</p>
                        </div>
                        <span class="self-start sm:self-auto inline-flex items-center px-2.5 py-1 rounded-full text-xs font-black bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                            {{ activeBrand.wcf_register.length }} awaiting
                        </span>
                    </div>

                    <div v-if="activeBrand.wcf_register.length" class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr class="text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-5 py-2.5">Ticket</th>
                                    <th class="px-3 py-2.5">Store</th>
                                    <th class="px-3 py-2.5">Issue</th>
                                    <th class="px-3 py-2.5 whitespace-nowrap">Waiting Since</th>
                                    <th class="px-3 py-2.5 text-right">WCF Age</th>
                                    <th class="px-5 py-2.5 text-right">Brand Response</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="row in activeBrand.wcf_register" :key="row.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <Link :href="row.url" class="font-black text-blue-600 hover:underline dark:text-blue-400">{{ row.key }}</Link>
                                    </td>
                                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ row.store || '—' }}</td>
                                    <td class="px-3 py-3 text-gray-600 max-w-xs truncate dark:text-gray-400" :title="row.title">{{ row.title }}</td>
                                    <td class="px-3 py-3 text-gray-500 whitespace-nowrap dark:text-gray-400">{{ row.entered_at }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold"
                                            :class="row.over_threshold
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                                : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                        >
                                            <svg v-if="row.over_threshold" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            {{ row.age_days }}d
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex justify-end gap-2">
                                            <button
                                                v-if="canClose"
                                                :disabled="acting === row.id"
                                                @click="runAction(row, 'resolve')"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 transition-colors disabled:opacity-50 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                                Resolved
                                            </button>
                                            <button
                                                v-if="canReopen"
                                                :disabled="acting === row.id"
                                                @click="runAction(row, 'reopen')"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-red-50 text-red-700 hover:bg-red-100 border border-red-200 transition-colors disabled:opacity-50 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                                Not Resolved
                                            </button>
                                            <span v-if="!canClose && !canReopen" class="text-xs text-gray-400 dark:text-gray-500">View only</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="px-5 py-10 text-center">
                        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">No tickets awaiting confirmation.</p>
                        <p class="text-xs text-gray-400 mt-1 dark:text-gray-500">When no concerns are raised, this brand is clear for the week.</p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
