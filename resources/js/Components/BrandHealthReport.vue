<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast';
import { useConfirm } from '@/Composables/useConfirm';

const props = defineProps({
    data: { type: Object, default: null },
    // Effective Entity/Company ids from the dashboard filter. The tab is built from
    // this selection, so the drill-down has to carry it too or a click could reach
    // brands the filter excluded.
    entityIds: { type: Array, default: () => [] },
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

// --- Brand filter + Top 10 lists ---------------------------------------------
// The filter and the sub-tab pills are the same piece of state, so picking a brand
// either way scopes the Top 10 panels and the view below them together.
const brandOptions = computed(() => [
    { value: 'summary', label: 'All Brands' },
    ...brands.value.map(b => ({ value: String(b.id), label: b.code ? `${b.name} (${b.code})` : b.name })),
]);

// Whichever slice the Top 10 panels describe: one brand, or every brand combined.
const topScope = computed(() => activeBrand.value || totals.value);
const scopeLabel = computed(() => activeBrand.value ? (activeBrand.value.code || activeBrand.value.name) : 'All Brands');

// --- Top 10 concern / status filters -----------------------------------------
// The tab is built as an open-backlog read, so the props already answer the
// default slice (all concerns, open tickets) with no round trip. Any other slice
// is re-ranked server-side — closed tickets simply are not in the payload.
const CONCERN_OPTIONS = [
    { value: 'all', label: 'All Concerns' },
    { value: 'Incident', label: 'Incident' },
    { value: 'Service Request', label: 'Service Request' },
    { value: 'Problem', label: 'Problem' },
];
const STATUS_OPTIONS = [
    { value: 'open', label: 'Open Tickets' },
    { value: 'closed', label: 'Closed Tickets' },
    { value: 'all', label: 'All Tickets' },
];

const topConcern = ref('all');
const topStatus = ref('open');
const topFiltered = ref(null);   // server-ranked lists, or null while on the default slice
const topLoading = ref(false);

const topIsDefault = computed(() => topConcern.value === 'all' && topStatus.value === 'open');
const bucketLabel = computed(() => ({ open: 'Open', closed: 'Closed', all: 'All' }[topStatus.value]));
// Wording for the row subtitles and empty states, e.g. "3 closed tickets".
const bucketNoun = computed(() => ({ open: 'open', closed: 'closed', all: '' }[topStatus.value]));

const topSubcategories = computed(() => (topFiltered.value ? topFiltered.value.top_subcategories : topScope.value?.top_subcategories) || []);
const topStores = computed(() => (topFiltered.value ? topFiltered.value.top_stores : topScope.value?.top_stores) || []);

// Filter params shared by the lists and the drill-down, so a click always shows
// exactly the tickets the clicked row counted.
const topFilterParams = () => ({
    ...(topConcern.value !== 'all' ? { concern_type: topConcern.value } : {}),
    ...(topStatus.value !== 'open' ? { status: topStatus.value } : {}),
});

const loadTopLists = async () => {
    if (topIsDefault.value) {
        topFiltered.value = null;
        return;
    }

    topLoading.value = true;
    // Remember which slice this request is for: a fast click could otherwise let a
    // stale response overwrite a newer one.
    const requestedFor = `${activeView.value}|${topConcern.value}|${topStatus.value}`;

    try {
        const { data } = await axios.get(route('dashboard.brand-health.top-lists', {}, false), {
            params: {
                ...(activeBrand.value ? { brand_id: activeBrand.value.id } : {}),
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
                ...topFilterParams(),
            },
        });
        if (requestedFor === `${activeView.value}|${topConcern.value}|${topStatus.value}`) {
            topFiltered.value = data;
        }
    } catch (e) {
        showError('Unable to load the filtered Top 10 lists.');
    } finally {
        topLoading.value = false;
    }
};

// Brand switch, filter change or a fresh payload all re-rank the same two lists.
watch([topConcern, topStatus, activeView, () => props.data], loadTopLists);

// The tab follows the dashboard Entity filter, so an empty tab usually means the
// filter excluded every brand rather than that none are configured.
const excludedByFilter = computed(() => !!props.data?.entity_scoped && (props.data?.brands_outside_scope || 0) > 0);

// Bar width relative to the biggest row, so the #1 row always fills the track.
const barPct = (rows, count) => {
    const max = Math.max(...rows.map(r => r.count), 0);
    return max > 0 ? Math.max(4, Math.round((count / max) * 100)) : 0;
};

// --- Drill-down modal --------------------------------------------------------
const showDrill = ref(false);
const drillLoading = ref(false);
const drillTitle = ref('');
const drillSubtitle = ref('');
const drillItems = ref([]);
const drillTickets = ref([]);

const openDrill = async ({ title, subtitle, params }) => {
    drillTitle.value = title;
    drillSubtitle.value = subtitle;
    drillItems.value = [];
    drillTickets.value = [];
    drillLoading.value = true;
    showDrill.value = true;

    try {
        const { data } = await axios.get(route('dashboard.brand-health.tickets', {}, false), {
            params: {
                ...(activeBrand.value ? { brand_id: activeBrand.value.id } : {}),
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
                ...topFilterParams(),
                ...params,
            },
        });
        drillItems.value = data.items || [];
        drillTickets.value = data.tickets || [];
    } catch (e) {
        showError('Unable to load the ticket breakdown.');
        showDrill.value = false;
    } finally {
        drillLoading.value = false;
    }
};

// "3 closed tickets" / "3 tickets" — matches whatever the Top 10 filter is showing.
const ticketPhrase = (count) => {
    const noun = bucketNoun.value ? `${bucketNoun.value} ticket` : 'ticket';
    const concern = topConcern.value !== 'all' ? ` (${topConcern.value})` : '';
    return `${count} ${noun}${count === 1 ? '' : 's'}${concern}`;
};

const openSubCategoryDrill = (row) => openDrill({
    title: row.name,
    subtitle: `${scopeLabel.value} — ${ticketPhrase(row.count)} in this sub-category`,
    params: { sub_category_id: String(row.id) },
});

const openStoreDrill = (row) => openDrill({
    title: row.code ? `[${row.code}] ${row.name}` : row.name,
    subtitle: `${ticketPhrase(row.count)} at this store`,
    params: { store_id: row.id },
});

const statusLabel = (status) => (status || '').replace(/_/g, ' ');

const statusClass = (status) => ({
    open: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    for_schedule: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    waiting_service_provider: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    waiting_client_feedback: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
}[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');

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
        <!-- Brand filter — drives the Top 10 panels and the view below them -->
        <div class="mb-4 flex flex-col sm:flex-row sm:items-center gap-2">
            <label class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Brand</label>
            <div class="w-full sm:w-72">
                <Autocomplete
                    :model-value="activeView"
                    @update:modelValue="activeView = $event || 'summary'"
                    :options="brandOptions"
                    label-key="label"
                    value-key="value"
                    size="sm"
                    placeholder="All Brands"
                />
            </div>
            <p v-if="data.entity_scoped" class="text-[11px] text-gray-400 dark:text-gray-500">
                Scoped to the dashboard Entity filter — {{ brands.length }} brand{{ brands.length === 1 ? '' : 's' }} in view<span v-if="data.brands_outside_scope"> ({{ data.brands_outside_scope }} outside the selection)</span>.
            </p>
        </div>

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
        </div>

        <!-- Nothing to show: say whether the filter excluded the brands or none exist -->
        <div v-if="!brands.length" class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-12 text-center dark:bg-gray-800 dark:border-gray-700">
            <p class="text-sm font-bold text-gray-600 dark:text-gray-300">
                {{ excludedByFilter
                    ? 'No brands in the current entity selection.'
                    : 'No brands found.' }}
            </p>
            <p class="text-xs text-gray-400 mt-1.5 dark:text-gray-500">
                {{ excludedByFilter
                    ? `This tab counts the same stores as the other dashboard tabs, so it only covers Brand companies inside the Entity filter. ${data.brands_outside_scope} brand${data.brands_outside_scope === 1 ? '' : 's'} sit outside it — add them to the Entity filter above to monitor them here.`
                    : 'Set a company’s Type to “Brand” on /companies to track it here.' }}
            </p>
        </div>

        <!-- ===================== TOP 10 (brand-filtered) ===================== -->
        <!-- Concern type + ticket status apply to both Top 10 panels and to the
             drill-down behind them. -->
        <div v-if="brands.length" class="mb-3 flex flex-col sm:flex-row sm:items-center gap-2">
            <label class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Top 10 Filter</label>
            <div class="w-full sm:w-56">
                <Autocomplete
                    :model-value="topConcern"
                    @update:modelValue="topConcern = $event || 'all'"
                    :options="CONCERN_OPTIONS"
                    label-key="label"
                    value-key="value"
                    size="sm"
                    placeholder="All Concerns"
                />
            </div>
            <div class="w-full sm:w-48">
                <Autocomplete
                    :model-value="topStatus"
                    @update:modelValue="topStatus = $event || 'open'"
                    :options="STATUS_OPTIONS"
                    label-key="label"
                    value-key="value"
                    size="sm"
                    placeholder="Open Tickets"
                />
            </div>
            <span v-if="topLoading" class="text-[11px] font-bold text-gray-400 dark:text-gray-500">Re-ranking…</span>
            <button
                v-else-if="!topIsDefault"
                @click="topConcern = 'all'; topStatus = 'open'"
                class="text-[11px] font-bold text-blue-600 hover:underline dark:text-blue-400"
            >
                Reset
            </button>
        </div>

        <div v-if="brands.length" class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
            <!-- Top 10 Sub-categories -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between dark:border-gray-700">
                    <div>
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Top 10 Sub-categories</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ scopeLabel }} — click a row for its items and concerns</p>
                    </div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-500">
                        <span v-if="topConcern !== 'all'" class="text-blue-500 dark:text-blue-400">{{ topConcern }} · </span>{{ bucketLabel }}
                    </span>
                </div>
                <div v-if="topSubcategories.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <button
                        v-for="(row, index) in topSubcategories"
                        :key="row.id"
                        @click="openSubCategoryDrill(row)"
                        class="w-full text-left px-5 py-2.5 hover:bg-blue-50/60 transition-colors dark:hover:bg-gray-700/40"
                    >
                        <div class="flex items-center gap-3">
                            <span class="w-5 text-[11px] font-black text-gray-400 tabular-nums dark:text-gray-500">{{ index + 1 }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-800 truncate dark:text-gray-100">{{ row.name }}</p>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-blue-500" :style="{ width: barPct(topSubcategories, row.count) + '%' }"></div>
                                </div>
                            </div>
                            <span class="text-sm font-black text-gray-900 tabular-nums dark:text-gray-100">{{ row.count }}</span>
                        </div>
                    </button>
                </div>
                <p v-else class="px-5 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                    No {{ bucketNoun ? bucketNoun + ' ' : '' }}tickets<span v-if="topConcern !== 'all'"> of type {{ topConcern }}</span> for this brand.
                </p>
            </div>

            <!-- Top 10 Stores -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between dark:border-gray-700">
                    <div>
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Top 10 Stores</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ scopeLabel }} — click a store to view its issues</p>
                    </div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-500">
                        <span v-if="topConcern !== 'all'" class="text-blue-500 dark:text-blue-400">{{ topConcern }} · </span>{{ bucketLabel }}
                    </span>
                </div>
                <div v-if="topStores.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                    <button
                        v-for="(row, index) in topStores"
                        :key="row.id"
                        @click="openStoreDrill(row)"
                        class="w-full text-left px-5 py-2.5 hover:bg-blue-50/60 transition-colors dark:hover:bg-gray-700/40"
                    >
                        <div class="flex items-center gap-3">
                            <span class="w-5 text-[11px] font-black text-gray-400 tabular-nums dark:text-gray-500">{{ index + 1 }}</span>
                            <!-- The dot always reads the store's live open backlog, even when the list ranks closed tickets. -->
                            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="BAND_META[row.band]?.dot" :title="`${bandLabel(row.band)} (open backlog)`"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-800 truncate dark:text-gray-100">
                                    <span v-if="row.code" class="text-gray-400 dark:text-gray-500">[{{ row.code }}]</span> {{ row.name }}
                                </p>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500">
                                    <span v-if="activeView === 'summary' && row.brand">{{ row.brand }} · </span>
                                    <span v-for="(lane, i) in row.lanes" :key="lane.label">
                                        <span v-if="i"> · </span>{{ lane.label }} {{ lane.count }}
                                    </span>
                                </p>
                            </div>
                            <span class="text-sm font-black text-gray-900 tabular-nums dark:text-gray-100">{{ row.count }}</span>
                        </div>
                    </button>
                </div>
                <p v-else class="px-5 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                    No {{ bucketNoun ? bucketNoun + ' ' : '' }}tickets<span v-if="topConcern !== 'all'"> of type {{ topConcern }}</span> for this brand.
                </p>
            </div>
        </div>

        <!-- ============================ SUMMARY ============================ -->
        <template v-if="activeView === 'summary'">
            <div v-if="totals && brands.length" class="space-y-6">
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

        <!-- ===================== Top 10 drill-down modal ===================== -->
        <Modal :show="showDrill" @close="showDrill = false" maxWidth="5xl">
            <div class="p-6">
                <div class="flex items-start justify-between mb-5 border-b border-gray-100 pb-4 dark:border-gray-700">
                    <div>
                        <h2 class="text-lg font-black text-gray-900 dark:text-gray-100">{{ drillTitle }}</h2>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">{{ drillSubtitle }}</p>
                    </div>
                    <button @click="showDrill = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:hover:text-gray-200">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div v-if="drillLoading" class="flex flex-col items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600 mb-4"></div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Loading tickets…</p>
                </div>

                <div v-else-if="drillTickets.length" class="space-y-5">
                    <!-- Items & concerns roll-up for the clicked slice -->
                    <div>
                        <h3 class="text-[11px] font-black text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-400">Items &amp; Concerns</h3>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="item in drillItems"
                                :key="item.name"
                                class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg border border-gray-200 bg-gray-50 text-xs dark:bg-gray-900/40 dark:border-gray-700"
                            >
                                <span class="font-bold text-gray-800 dark:text-gray-100">{{ item.name }}</span>
                                <span v-if="item.concern_type" class="text-gray-400 dark:text-gray-500">{{ item.concern_type }}</span>
                                <span class="px-1.5 rounded-full bg-blue-100 text-blue-700 font-black tabular-nums dark:bg-blue-900/40 dark:text-blue-300">{{ item.count }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="max-h-[55vh] overflow-y-auto border border-gray-100 rounded-lg dark:border-gray-700">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900/60">
                                <tr class="text-left text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-4 py-2.5">Ticket</th>
                                    <th class="px-3 py-2.5">Store</th>
                                    <th class="px-3 py-2.5">Issue</th>
                                    <th class="px-3 py-2.5">Item</th>
                                    <th class="px-3 py-2.5">Concern</th>
                                    <th class="px-3 py-2.5">Assignee</th>
                                    <th class="px-3 py-2.5">Status</th>
                                    <th class="px-4 py-2.5 whitespace-nowrap">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="ticket in drillTickets" :key="ticket.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <Link :href="ticket.url" class="font-black text-blue-600 hover:underline dark:text-blue-400">{{ ticket.key }}</Link>
                                    </td>
                                    <td class="px-3 py-2.5 text-gray-700 whitespace-nowrap dark:text-gray-300">{{ ticket.store || '—' }}</td>
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
                    </div>
                </div>

                <p v-else class="py-12 text-center text-sm text-gray-400 dark:text-gray-500">
                    No {{ bucketNoun ? bucketNoun + ' ' : '' }}tickets for this selection.
                </p>

                <div class="mt-5 flex justify-end">
                    <button @click="showDrill = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors text-sm font-medium dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
