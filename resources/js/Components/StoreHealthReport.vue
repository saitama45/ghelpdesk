<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { FunnelIcon, XMarkIcon, DocumentArrowDownIcon } from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
    reportData: Array,
    summary: Object,
    thresholds: Object,
    thresholdBands: {
        type: Array,
        default: () => []
    },
    entityHealth: {
        type: Array,
        default: () => []
    },
    // Effective Entity/Company ids from the dashboard's filter. Passed to the ticket
    // drill-down endpoints so the modal scopes by store ownership like the cards.
    // Empty on the standalone report page (keeps its active-entity scope).
    entityIds: {
        type: Array,
        default: () => []
    },
    showFilters: {
        type: Boolean,
        default: true
    },
    // Only needed if showFilters is true
    users: Array,
    stores: Array,
    subUnits: Array,
    hierarchicalDepartments: {
        type: Array,
        default: () => []
    },
    filters: Object
});

const emit = defineEmits(['filter']);
const page = usePage();

const filterNodeId = ref(
    props.filters?.department_node_id
        ? props.filters.department_node_id
        : (props.filters?.department_id ? `dept-${props.filters.department_id}` : '')
);

const filterForm = ref({
    user_id: props.filters?.user_id || 'all',
    store_id: props.filters?.store_id || 'all',
    as_of_date: props.filters?.as_of_date || new Date().toISOString().split('T')[0]
});

const rawHierarchicalDepartments = computed(() => {
    if (props.hierarchicalDepartments?.length) {
        return props.hierarchicalDepartments;
    }

    return page.props.hierarchicalDepartments || [];
});

const hierarchicalOptions = computed(() =>
    rawHierarchicalDepartments.value.map(dept => ({
        ...dept,
        id: String(dept.id).startsWith('dept-') ? dept.id : `dept-${dept.id}`,
        children: dept.children || dept.nodes || [],
    }))
);

const deptFilterParams = computed(() => {
    const nodeId = filterNodeId.value;

    if (!nodeId) {
        return {};
    }

    if (typeof nodeId === 'string' && nodeId.startsWith('dept-')) {
        return { department_id: nodeId.replace('dept-', '') };
    }

    return { department_node_id: nodeId };
});

const filterPayload = () => ({
    ...filterForm.value,
    ...deptFilterParams.value,
    ...(filterNodeId.value === '' ? { skip_default_department: 1 } : {}),
});

const usersWithLabel = computed(() => {
    const list = (props.users || []).map(user => ({
        ...user,
        display_name: user.name,
    }));

    return [{ id: 'all', display_name: 'All Users' }, ...list];
});

const storesWithLabel = computed(() => {
    const list = (props.stores || []).map(store => ({
        ...store,
        display_name: `[${store.code}] ${store.name}`,
    }));

    return [{ id: 'all', display_name: 'All Stores' }, ...list];
});

const showTicketsModal = ref(false);
const modalLoading = ref(false);
const selectedStoreTickets = ref([]);
const selectedStoreName = ref('');

const fetchTickets = async (storeId) => {
    modalLoading.value = true;
    showTicketsModal.value = true;
    try {
        const response = await axios.get(route('reports.store-health.tickets', storeId, false), {
            params: {
                as_of_date: filterForm.value.as_of_date,
                user_id: filterForm.value.user_id,
                ...deptFilterParams.value,
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
            }
        });
        selectedStoreTickets.value = response.data.tickets;
        selectedStoreName.value = response.data.store_name;
    } catch (error) {
        console.error('Error fetching tickets:', error);
    } finally {
        modalLoading.value = false;
    }
};

const fetchSectorTickets = async (sector) => {
    modalLoading.value = true;
    showTicketsModal.value = true;
    try {
        const response = await axios.get(route('reports.store-health.sector-tickets', sector, false), {
            params: {
                as_of_date: filterForm.value.as_of_date,
                store_id: filterForm.value.store_id,
                user_id: filterForm.value.user_id,
                ...deptFilterParams.value,
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
            }
        });
        selectedStoreTickets.value = response.data.tickets;
        selectedStoreName.value = response.data.store_name;
    } catch (error) {
        console.error('Error fetching sector tickets:', error);
    } finally {
        modalLoading.value = false;
    }
};

const applyFilters = () => {
    emit('filter', filterPayload());
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for clients feedback?';
        default: return status ? status.replace('_', ' ') : '';
    }
};

const exportPDF = () => {
    const params = new URLSearchParams(filterPayload()).toString();
    window.open(route('reports.store-health.pdf') + '?' + params, '_blank');
};

const BAND_CLASSES = {
    green: 'bg-green-500',
    yellow: 'bg-yellow-500',
    orange: 'bg-orange-500',
    red: 'bg-red-500',
};

const healthSummaryItems = computed(() => {
    if (props.thresholdBands?.length) {
        return props.thresholdBands.map(band => ({ ...band, class: BAND_CLASSES[band.key] }));
    }

    const s = props.thresholds || {};
    return [
        { key: 'green', label: s.threshold_green_label ?? 'Healthy', min: Number(s.threshold_green_min ?? 0), max: Number(s.threshold_green_max ?? 2), class: BAND_CLASSES.green },
        { key: 'yellow', label: s.threshold_yellow_label ?? 'Warning', min: Number(s.threshold_yellow_min ?? 3), max: Number(s.threshold_yellow_max ?? 3), class: BAND_CLASSES.yellow },
        { key: 'orange', label: s.threshold_orange_label ?? 'At-risk', min: Number(s.threshold_orange_min ?? 4), max: Number(s.threshold_orange_max ?? 4), class: BAND_CLASSES.orange },
        { key: 'red', label: s.threshold_red_label ?? 'Critical', min: Number(s.threshold_red_min ?? 5), max: null, class: BAND_CLASSES.red },
    ];
});

const bandRange = (band) => band.max === null
    ? `${band.min}+`
    : (band.min === band.max ? `${band.min}` : `${band.min}-${band.max}`);
const healthItem = (key) => healthSummaryItems.value.find(item => item.key === key) || healthSummaryItems.value[0];
const healthTicketCount = (item, key) => item.health_ticket_counts?.[key] ?? item.health_counts?.[key] ?? 0;
const healthStoreCount = (item, key) => item.health_store_counts?.[key] ?? 0;
// The status bar is a solid band indicator, not a fill gauge — the store's legend
// band carries the meaning, so every row reads full width in its band colour.
const healthBarTitle = (store) => {
    const band = healthItem(store.health_bucket);
    return `${band.label} · ${store.ticket_count} ticket${store.ticket_count === 1 ? '' : 's'}`;
};
const isCtMode = computed(() => Boolean(props.summary?.is_ct_mode));
const isOfficeMode = computed(() => Boolean(props.summary?.is_office_mode));

// The single health bucket an office store falls into (its own open-ticket count).
// Cards carry exactly one non-zero bucket; default to Healthy when none.
const officeBucket = (item) => {
    if (item.health_bucket) return healthItem(item.health_bucket);
    const counts = item.health_counts || {};
    for (const bucket of healthSummaryItems.value) {
        if ((counts[bucket.key] || 0) > 0) return bucket;
    }
    return healthSummaryItems.value[0];
};

// ── % Healthy ─────────────────────────────────────────────────────────
// A store counts as healthy when it sits in the green band; stores with no open
// tickets fold into green, so the denominator is every active store in scope.
const formatPct = (value) => {
    if (value === null || value === undefined) return '—';
    const n = Number(value);
    return (Number.isInteger(n) ? n : n.toFixed(1)) + '%';
};

const hasHealthyPct = (item) => item?.healthy_pct !== null && item?.healthy_pct !== undefined;

// Per-location % Health (corporate offices) is a resolution rate, so shade it on its
// own scale rather than the open-ticket legend bands.
const healthyPctTone = (pct) => {
    if (pct >= 90) return 'green';
    if (pct >= 75) return 'yellow';
    if (pct >= 50) return 'orange';
    return 'red';
};
const PCT_TEXT_CLASSES = {
    green: 'text-green-600',
    yellow: 'text-yellow-600',
    orange: 'text-orange-600',
    red: 'text-red-600',
};
const healthyPctClass = (pct) => PCT_TEXT_CLASSES[healthyPctTone(pct)];
const healthyPctBarClass = (pct) => BAND_CLASSES[healthyPctTone(pct)];

// Rollup across a list of sector cards (North / South area totals).
const areaHealth = (items) => {
    const rows = (items || []).filter(hasHealthyPct);
    const total = rows.reduce((sum, item) => sum + (item.total_stores || 0), 0);
    const healthy = rows.reduce((sum, item) => sum + (item.healthy_stores || 0), 0);

    return {
        total_stores: total,
        healthy_stores: healthy,
        healthy_pct: total > 0 ? Math.round((healthy / total) * 1000) / 10 : null,
    };
};

const officeTotals = computed(() => props.summary?.office_totals || null);

// ── Entity health heatmap ─────────────────────────────────────────────
const BUCKET_KEYS = ['green', 'yellow', 'orange', 'red'];
const CELL_RGB = { green: '34,197,94', yellow: '234,179,8', orange: '249,115,22', red: '239,68,68' };

// Shade each column independently so low-count severities (e.g. Critical) stay
// visible next to the much larger Healthy counts.
const entityColumnMax = computed(() => {
    const max = { green: 0, yellow: 0, orange: 0, red: 0 };
    (props.entityHealth || []).forEach((row) => {
        BUCKET_KEYS.forEach((k) => { max[k] = Math.max(max[k], row.counts?.[k] ?? 0); });
    });
    return max;
});

const entityCellAlpha = (count, key) => {
    if (!count) return 0;
    const max = entityColumnMax.value[key] || 1;
    return 0.18 + 0.82 * (count / max);
};

const entityCellStyle = (count, key) => {
    const alpha = entityCellAlpha(count, key);
    return alpha ? { backgroundColor: `rgba(${CELL_RGB[key]}, ${alpha})` } : {};
};

const entityCellTextClass = (count, key) => {
    if (!count) return 'text-gray-300 dark:text-gray-600';
    return entityCellAlpha(count, key) > 0.5 ? 'text-white' : 'text-gray-900 dark:text-gray-100';
};

const entityTotals = computed(() => {
    const totals = { total_stores: 0, open_tickets: 0, counts: { green: 0, yellow: 0, orange: 0, red: 0 } };
    (props.entityHealth || []).forEach((row) => {
        totals.total_stores += row.total_stores || 0;
        totals.open_tickets += row.open_tickets || 0;
        BUCKET_KEYS.forEach((k) => { totals.counts[k] += row.counts?.[k] ?? 0; });
    });
    return totals;
});

// Share of all stores in each health bucket (buckets are mutually exclusive, so
// the four percentages sum to 100%). Shown under the totals row.
const entityBucketPct = (key) => {
    const total = entityTotals.value.total_stores || 0;
    if (!total) return '0%';
    return Math.round((entityTotals.value.counts[key] / total) * 100) + '%';
};

const shouldCenterBoxes = computed(() => {
    if (!filterNodeId.value) return true;
    
    let code = null;
    const findNode = (nodes) => {
        for (const node of nodes) {
            const nodeId = String(node.id).startsWith('dept-') ? String(node.id) : `dept-${node.id}`;
            if (nodeId === String(filterNodeId.value)) {
                code = node.code;
                return true;
            }
            if (node.children && findNode(node.children)) return true;
            if (node.nodes && findNode(node.nodes)) return true;
        }
        return false;
    };
    findNode(hierarchicalOptions.value);
    
    return !['SD', 'SO'].includes(code);
});

const reportGridClass = computed(() => {
    const baseClass = 'gap-6 items-start';
    const count = props.reportData.length;
    
    if (!shouldCenterBoxes.value || count >= 4) {
        return `grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-4 ${baseClass}`;
    }
    
    if (count === 1) return `grid grid-cols-1 ${baseClass} max-w-2xl mx-auto w-full`;
    if (count === 2) return `grid grid-cols-1 lg:grid-cols-2 ${baseClass} max-w-5xl mx-auto w-full`;
    if (count === 3) return `grid grid-cols-1 lg:grid-cols-3 ${baseClass} max-w-7xl mx-auto w-full`;
    
    return `grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-4 ${baseClass}`;
});

const getAreaGridClass = (count, maxCols) => {
    if (!shouldCenterBoxes.value || count === 0 || count >= maxCols) {
        if (maxCols === 6) return 'grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-6 divide-x divide-y border-t border-gray-200';
        return 'grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 border-t border-gray-200';
    }
    return 'flex flex-wrap justify-center border-t border-gray-200 divide-x divide-y sm:divide-y-0';
};

const getAreaItemClass = (count, maxCols) => {
    if (!shouldCenterBoxes.value || count === 0 || count >= maxCols) {
        return 'flex flex-col';
    }
    return maxCols === 6 
        ? 'flex flex-col w-1/2 sm:w-1/4 xl:w-1/6' 
        : 'flex flex-col w-1/2 sm:w-1/4';
};
</script>

<template>
    <div class="space-y-6 print:space-y-0">
        <!-- Filters Card -->
        <div v-if="showFilters" class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 print:hidden dark:bg-gray-800 dark:border-gray-700">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1 dark:text-gray-300">Department</label>
                    <HierarchySelector
                        v-model="filterNodeId"
                        :nodes="hierarchicalOptions"
                        placeholder="All Departments"
                    />
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1 dark:text-gray-300">User</label>
                    <Autocomplete
                        v-model="filterForm.user_id"
                        :options="usersWithLabel"
                        label-key="display_name"
                        value-key="id"
                        placeholder="All Users"
                    />
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1 dark:text-gray-300">Store</label>
                    <Autocomplete
                        v-model="filterForm.store_id"
                        :options="storesWithLabel"
                        label-key="display_name"
                        value-key="id"
                        placeholder="All Stores"
                    />
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1 dark:text-gray-300">As of Date</label>
                    <input type="date" v-model="filterForm.as_of_date" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                </div>
                <div class="flex space-x-2">
                    <button @click="applyFilters" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center justify-center shadow-sm transition-colors">
                        <FunnelIcon class="w-4 h-4 mr-2" />
                        Generate
                    </button>
                    <button @click="exportPDF" class="bg-gray-100 hover:bg-gray-200 text-gray-700 p-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700" title="Export PDF">
                        <DocumentArrowDownIcon class="w-5 h-5" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Legend Section -->
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-6 text-[10px] sm:text-xs">
                <span class="font-black text-gray-700 uppercase tracking-widest dark:text-gray-300">Legend:</span>
                <div class="grid grid-cols-2 sm:flex sm:items-center gap-3 sm:gap-6">
                    <div v-for="band in healthSummaryItems" :key="band.key" class="flex items-center space-x-2">
                        <div class="w-3 h-3 sm:w-4 sm:h-4 rounded shadow-sm" :class="band.class"></div>
                        <span class="text-gray-600 font-bold dark:text-gray-300">
                            {{ bandRange(band) }} ({{ band.label }})
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Store Health by Entity (heatmap) -->
        <div v-if="entityHealth && entityHealth.length" class="bg-white p-4 sm:p-5 rounded-lg shadow-sm border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 mb-4">
                <h3 class="text-sm font-black text-gray-800 uppercase tracking-widest dark:text-gray-100">Store Health by Entity</h3>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">All active stores bucketed by open tickets &middot; darker = more stores (shaded per column)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm border-separate border-spacing-0">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-400">
                            <th class="text-left font-black px-3 py-2 sticky left-0 bg-white dark:bg-gray-800">Entity</th>
                            <th class="text-right font-black px-3 py-2">Stores</th>
                            <th class="text-right font-black px-3 py-2">Open</th>
                            <th v-for="col in healthSummaryItems" :key="col.key" class="px-2 py-2 text-center font-black">
                                <div class="flex items-center justify-center gap-1.5">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="col.class"></span>
                                    <span>{{ col.label }} stores</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in entityHealth" :key="row.id ?? row.name" class="hover:bg-gray-50/60 dark:hover:bg-gray-700/30">
                            <td class="px-3 py-1.5 sticky left-0 bg-white dark:bg-gray-800">
                                <div class="font-bold text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ row.name }}</div>
                                <div v-if="row.code" class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">{{ row.code }}</div>
                            </td>
                            <td class="px-3 py-1.5 text-right font-black text-gray-700 dark:text-gray-200 tabular-nums">{{ row.total_stores }}</td>
                            <td class="px-3 py-1.5 text-right font-bold text-blue-600 tabular-nums">{{ row.open_tickets }}</td>
                            <td v-for="col in healthSummaryItems" :key="col.key" class="px-1.5 py-1.5">
                                <div
                                    class="mx-auto min-w-[48px] rounded-md text-center py-2 font-black tabular-nums border border-gray-100 transition-colors dark:border-gray-700/60"
                                    :style="entityCellStyle(row.counts?.[col.key] || 0, col.key)"
                                    :class="entityCellTextClass(row.counts?.[col.key] || 0, col.key)"
                                    :title="`${row.counts?.[col.key] || 0} ${col.label} store(s) in ${row.name}`"
                                >
                                    {{ row.counts?.[col.key] || 0 }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="text-gray-900 dark:text-gray-100">
                            <td class="px-3 pt-3 font-black uppercase text-[11px] tracking-wider text-gray-500 border-t-2 border-gray-200 sticky left-0 bg-white dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400">All Entities</td>
                            <td class="px-3 pt-3 text-right font-black tabular-nums border-t-2 border-gray-200 dark:border-gray-600">{{ entityTotals.total_stores }}</td>
                            <td class="px-3 pt-3 text-right font-black text-blue-600 tabular-nums border-t-2 border-gray-200 dark:border-gray-600">{{ entityTotals.open_tickets }}</td>
                            <td v-for="col in healthSummaryItems" :key="col.key" class="px-2 pt-3 text-center border-t-2 border-gray-200 dark:border-gray-600">
                                <div class="font-black tabular-nums">{{ entityTotals.counts[col.key] }}</div>
                                <div class="text-[10px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ entityBucketPct(col.key) }}</div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Area Summary Section -->
        <div class="space-y-6 sm:space-y-8 mb-8">
            <!-- Corporate Technology -->
            <div v-if="isCtMode" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gray-800 py-2.5 text-center">
                    <span class="text-xs sm:text-sm font-black text-white tracking-[0.3em] sm:tracking-[0.5em] uppercase">C O R P O R A T E &nbsp;&nbsp; T E C H N O L O G Y</span>
                </div>
                <div v-if="summary.ct?.length" :class="getAreaGridClass(summary.ct.length, 6)">
                    <div v-for="item in summary.ct" :key="item.store_id" :class="getAreaItemClass(summary.ct.length, 6)">
                        <div class="bg-gray-50 py-1.5 px-2 text-center border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                            <span class="text-[9px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-300">{{ item.store_code }}</span>
                        </div>
                        <div class="p-2 text-center h-10 flex items-center justify-center">
                            <span class="text-[10px] font-bold text-blue-600 truncate px-1" :title="item.store_name">{{ item.store_name }}</span>
                        </div>
                        <button
                            @click="item.total_tickets > 0 ? fetchTickets(item.store_id) : null"
                            class="py-3 px-3 transition-all shadow-inner text-center w-full bg-white border-gray-200 text-gray-900 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"
                            :class="item.total_tickets > 0 ? 'hover:bg-blue-50 cursor-pointer' : 'cursor-default'"
                        >
                            <span class="grid grid-cols-2 gap-2 text-center">
                                <span class="rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-gray-400 dark:text-gray-400">Affected Stores</span>
                                    <span class="block text-xl sm:text-2xl font-black text-gray-900 leading-tight dark:text-gray-100">{{ item.store_count ?? 0 }}</span>
                                </span>
                                <span class="rounded-lg border border-blue-200 bg-blue-50 px-2 py-2 shadow-sm">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-blue-500">Tickets</span>
                                    <span class="block text-2xl sm:text-3xl font-black text-blue-700 leading-tight">{{ item.total_tickets ?? 0 }}</span>
                                </span>
                            </span>
                            <span v-if="hasHealthyPct(item)" class="mt-3 block">
                                <span class="flex items-center justify-between text-[9px] font-black uppercase tracking-wider">
                                    <span class="text-gray-400 dark:text-gray-400">% Healthy</span>
                                    <span class="text-green-600 tabular-nums">{{ formatPct(item.healthy_pct) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 w-full rounded-full bg-gray-100 overflow-hidden dark:bg-gray-900">
                                    <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, item.healthy_pct) + '%' }"></span>
                                </span>
                                <span class="mt-1 block text-right text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ item.healthy_stores }} of {{ item.total_stores }} stores</span>
                            </span>
                            <span class="mt-3 grid grid-cols-2 gap-1.5 text-left">
                                <span
                                    v-for="health in healthSummaryItems"
                                    :key="health.key"
                                    class="flex items-center justify-between gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-1 dark:bg-gray-900/50 dark:border-gray-700"
                                    :title="health.label"
                                >
                                    <span class="flex items-center gap-1 min-w-0">
                                        <span class="w-2 h-2 rounded-full shrink-0" :class="health.class"></span>
                                        <span class="truncate text-[9px] font-bold text-gray-500 dark:text-gray-300">{{ health.label }}</span>
                                    </span>
                                    <span class="text-right text-[9px] font-black leading-tight text-gray-900 dark:text-gray-100">
                                        <span class="block">{{ healthStoreCount(item, health.key) }} stores</span>
                                        <span class="block text-blue-600">{{ healthTicketCount(item, health.key) }} tickets</span>
                                    </span>
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
                <div v-else class="border-t border-gray-200 py-8 text-center text-sm italic text-gray-500 dark:text-gray-300 dark:border-gray-700">
                    No Corporate Technology store tickets found for this period.
                </div>
            </div>

            <!-- Corporate Office -->
            <div v-if="isOfficeMode" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gray-800 py-2.5 text-center">
                    <span class="text-xs sm:text-sm font-black text-white tracking-[0.3em] sm:tracking-[0.5em] uppercase">C O R P O R A T E &nbsp;&nbsp; O F F I C E</span>
                </div>
                <div v-if="officeTotals && officeTotals.healthy_pct !== null" class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                    <span class="text-[9px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Offices in Healthy Band</span>
                    <span class="flex items-center gap-2">
                        <span class="block h-1.5 w-20 sm:w-40 rounded-full bg-gray-200 overflow-hidden dark:bg-gray-700">
                            <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, officeTotals.healthy_pct) + '%' }"></span>
                        </span>
                        <span class="text-[11px] font-black text-green-600 tabular-nums">{{ formatPct(officeTotals.healthy_pct) }}</span>
                        <span class="text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ officeTotals.healthy_stores }}/{{ officeTotals.total_stores }}</span>
                    </span>
                </div>
                <div v-if="summary.office?.length" :class="getAreaGridClass(summary.office.length, 6)">
                    <div v-for="item in summary.office" :key="item.store_id" :class="getAreaItemClass(summary.office.length, 6)">
                        <div class="bg-gray-50 py-1.5 px-2 text-center border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                            <span class="text-[9px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-300">{{ item.store_code }}</span>
                        </div>
                        <div class="px-2 pt-2 text-center">
                            <span class="block text-[11px] font-bold text-blue-600 truncate" :title="item.store_name">{{ item.store_name }}</span>
                            <span class="block text-[9px] font-semibold text-gray-400 truncate dark:text-gray-500" :title="item.team">{{ item.team }}</span>
                        </div>
                        <button
                            @click="item.total_tickets > 0 ? fetchTickets(item.store_id) : null"
                            class="py-4 px-3 mt-1 w-full flex flex-col items-center justify-center transition-all shadow-inner text-center bg-white border-gray-200 text-gray-900 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"
                            :class="item.total_tickets > 0 ? 'hover:bg-blue-50 cursor-pointer' : 'cursor-default'"
                        >
                            <span class="block text-[9px] font-black uppercase tracking-wider text-blue-500">Tickets</span>
                            <span class="block text-3xl font-black text-blue-700 leading-tight">{{ item.total_tickets ?? 0 }}</span>
                            <span v-if="hasHealthyPct(item)" class="mt-3 block w-full">
                                <span class="flex items-center justify-between text-[9px] font-black uppercase tracking-wider">
                                    <span class="text-gray-400 dark:text-gray-400">% Health</span>
                                    <span class="tabular-nums" :class="healthyPctClass(item.healthy_pct)">{{ formatPct(item.healthy_pct) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 w-full rounded-full bg-gray-100 overflow-hidden dark:bg-gray-900">
                                    <span class="block h-full rounded-full" :class="healthyPctBarClass(item.healthy_pct)" :style="{ width: Math.min(100, item.healthy_pct) + '%' }"></span>
                                </span>
                                <span class="mt-1 block text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">
                                    {{ item.all_tickets ? `${item.closed_tickets} of ${item.all_tickets} cleared` : 'No tickets raised' }}
                                </span>
                            </span>
                            <span class="mt-2 inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 dark:bg-gray-900/50 dark:border-gray-700">
                                <span class="w-2 h-2 rounded-full shrink-0" :class="officeBucket(item).class"></span>
                                <span class="text-[9px] font-black uppercase tracking-wider text-gray-600 dark:text-gray-300">{{ officeBucket(item).label }}</span>
                            </span>
                        </button>
                    </div>
                </div>
                <div v-else class="border-t border-gray-200 py-8 text-center text-sm italic text-gray-500 dark:text-gray-300 dark:border-gray-700">
                    No active corporate office stores found for this scope.
                </div>
            </div>

            <!-- North Area -->
            <div v-if="!isCtMode && !isOfficeMode" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gray-800 py-2.5 text-center">
                    <span class="text-xs sm:text-sm font-black text-white tracking-[0.3em] sm:tracking-[0.5em] uppercase">N O R T H &nbsp;&nbsp; A R E A</span>
                </div>
                <div v-if="areaHealth(summary.north).healthy_pct !== null" class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                    <span class="text-[9px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">% Healthy Stores</span>
                    <span class="flex items-center gap-2">
                        <span class="block h-1.5 w-20 sm:w-40 rounded-full bg-gray-200 overflow-hidden dark:bg-gray-700">
                            <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, areaHealth(summary.north).healthy_pct) + '%' }"></span>
                        </span>
                        <span class="text-[11px] font-black text-green-600 tabular-nums">{{ formatPct(areaHealth(summary.north).healthy_pct) }}</span>
                        <span class="text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ areaHealth(summary.north).healthy_stores }}/{{ areaHealth(summary.north).total_stores }}</span>
                    </span>
                </div>
                <div :class="getAreaGridClass(summary.north?.length || 0, 4)">
                    <div v-for="item in summary.north" :key="item.sector" :class="getAreaItemClass(summary.north?.length || 0, 4)">
                        <div class="bg-gray-50 py-1.5 px-2 text-center border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                            <span class="text-[9px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-300">Sector {{ item.sector }}</span>
                        </div>
                        <div class="p-2 text-center h-10 flex items-center justify-center">
                            <span class="text-[10px] font-bold text-blue-600 truncate px-1" :title="item.user">{{ item.user }}</span>
                        </div>
                        <button 
                            @click="item.total_tickets > 0 ? fetchSectorTickets(item.sector) : null"
                            class="py-3 px-3 transition-all shadow-inner text-center w-full bg-white border-gray-200 text-gray-900 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"
                            :class="item.total_tickets > 0 ? 'hover:bg-blue-50 cursor-pointer' : 'cursor-default'"
                        >
                            <span class="grid grid-cols-2 gap-2 text-center">
                                <span class="rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-gray-400 dark:text-gray-400">Affected Stores</span>
                                    <span class="block text-xl sm:text-2xl font-black text-gray-900 leading-tight dark:text-gray-100">{{ item.store_count ?? 0 }}</span>
                                </span>
                                <span class="rounded-lg border border-blue-200 bg-blue-50 px-2 py-2 shadow-sm">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-blue-500">Tickets</span>
                                    <span class="block text-2xl sm:text-3xl font-black text-blue-700 leading-tight">{{ item.total_tickets ?? 0 }}</span>
                                </span>
                            </span>
                            <span v-if="hasHealthyPct(item)" class="mt-3 block">
                                <span class="flex items-center justify-between text-[9px] font-black uppercase tracking-wider">
                                    <span class="text-gray-400 dark:text-gray-400">% Healthy</span>
                                    <span class="text-green-600 tabular-nums">{{ formatPct(item.healthy_pct) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 w-full rounded-full bg-gray-100 overflow-hidden dark:bg-gray-900">
                                    <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, item.healthy_pct) + '%' }"></span>
                                </span>
                                <span class="mt-1 block text-right text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ item.healthy_stores }} of {{ item.total_stores }} stores</span>
                            </span>
                            <span class="mt-3 grid grid-cols-2 gap-1.5 text-left">
                                <span
                                    v-for="health in healthSummaryItems"
                                    :key="health.key"
                                    class="flex items-center justify-between gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-1 dark:bg-gray-900/50 dark:border-gray-700"
                                    :title="health.label"
                                >
                                    <span class="flex items-center gap-1 min-w-0">
                                        <span class="w-2 h-2 rounded-full shrink-0" :class="health.class"></span>
                                        <span class="truncate text-[9px] font-bold text-gray-500 dark:text-gray-300">{{ health.label }}</span>
                                    </span>
                                    <span class="text-right text-[9px] font-black leading-tight text-gray-900 dark:text-gray-100">
                                        <span class="block">{{ healthStoreCount(item, health.key) }} stores</span>
                                        <span class="block text-blue-600">{{ healthTicketCount(item, health.key) }} tickets</span>
                                    </span>
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- South Area -->
            <div v-if="!isCtMode && !isOfficeMode" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gray-800 py-2.5 text-center">
                    <span class="text-xs sm:text-sm font-black text-white tracking-[0.3em] sm:tracking-[0.5em] uppercase">S O U T H &nbsp;&nbsp; A R E A</span>
                </div>
                <div v-if="areaHealth(summary.south).healthy_pct !== null" class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                    <span class="text-[9px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">% Healthy Stores</span>
                    <span class="flex items-center gap-2">
                        <span class="block h-1.5 w-20 sm:w-40 rounded-full bg-gray-200 overflow-hidden dark:bg-gray-700">
                            <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, areaHealth(summary.south).healthy_pct) + '%' }"></span>
                        </span>
                        <span class="text-[11px] font-black text-green-600 tabular-nums">{{ formatPct(areaHealth(summary.south).healthy_pct) }}</span>
                        <span class="text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ areaHealth(summary.south).healthy_stores }}/{{ areaHealth(summary.south).total_stores }}</span>
                    </span>
                </div>
                <div :class="getAreaGridClass(summary.south?.length || 0, 4)">
                    <div v-for="item in summary.south" :key="item.sector" :class="getAreaItemClass(summary.south?.length || 0, 4)">
                        <div class="bg-gray-50 py-1.5 px-2 text-center border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                            <span class="text-[9px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-300">Sector {{ item.sector }}</span>
                        </div>
                        <div class="p-2 text-center h-10 flex items-center justify-center">
                            <span class="text-[10px] font-bold text-blue-600 truncate px-1" :title="item.user">{{ item.user }}</span>
                        </div>
                        <button 
                            @click="item.total_tickets > 0 ? fetchSectorTickets(item.sector) : null"
                            class="py-3 px-3 transition-all shadow-inner text-center w-full bg-white border-gray-200 text-gray-900 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"
                            :class="item.total_tickets > 0 ? 'hover:bg-blue-50 cursor-pointer' : 'cursor-default'"
                        >
                            <span class="grid grid-cols-2 gap-2 text-center">
                                <span class="rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-gray-400 dark:text-gray-400">Affected Stores</span>
                                    <span class="block text-xl sm:text-2xl font-black text-gray-900 leading-tight dark:text-gray-100">{{ item.store_count ?? 0 }}</span>
                                </span>
                                <span class="rounded-lg border border-blue-200 bg-blue-50 px-2 py-2 shadow-sm">
                                    <span class="block text-[9px] font-black uppercase tracking-wider text-blue-500">Tickets</span>
                                    <span class="block text-2xl sm:text-3xl font-black text-blue-700 leading-tight">{{ item.total_tickets ?? 0 }}</span>
                                </span>
                            </span>
                            <span v-if="hasHealthyPct(item)" class="mt-3 block">
                                <span class="flex items-center justify-between text-[9px] font-black uppercase tracking-wider">
                                    <span class="text-gray-400 dark:text-gray-400">% Healthy</span>
                                    <span class="text-green-600 tabular-nums">{{ formatPct(item.healthy_pct) }}</span>
                                </span>
                                <span class="mt-1 block h-1.5 w-full rounded-full bg-gray-100 overflow-hidden dark:bg-gray-900">
                                    <span class="block h-full rounded-full bg-green-500" :style="{ width: Math.min(100, item.healthy_pct) + '%' }"></span>
                                </span>
                                <span class="mt-1 block text-right text-[9px] font-bold text-gray-400 tabular-nums dark:text-gray-500">{{ item.healthy_stores }} of {{ item.total_stores }} stores</span>
                            </span>
                            <span class="mt-3 grid grid-cols-2 gap-1.5 text-left">
                                <span
                                    v-for="health in healthSummaryItems"
                                    :key="health.key"
                                    class="flex items-center justify-between gap-1 rounded border border-gray-200 bg-gray-50 px-1.5 py-1 dark:bg-gray-900/50 dark:border-gray-700"
                                    :title="health.label"
                                >
                                    <span class="flex items-center gap-1 min-w-0">
                                        <span class="w-2 h-2 rounded-full shrink-0" :class="health.class"></span>
                                        <span class="truncate text-[9px] font-bold text-gray-500 dark:text-gray-300">{{ health.label }}</span>
                                    </span>
                                    <span class="text-right text-[9px] font-black leading-tight text-gray-900 dark:text-gray-100">
                                        <span class="block">{{ healthStoreCount(item, health.key) }} stores</span>
                                        <span class="block text-blue-600">{{ healthTicketCount(item, health.key) }} tickets</span>
                                    </span>
                                </span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Content -->
        <div v-if="reportData.length > 0" :class="reportGridClass">
            <div v-for="userData in reportData" :key="userData.id" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden break-inside-avoid dark:bg-gray-800 dark:border-gray-700">
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ userData.name }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Store Code</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">IT Area</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Ticket Count</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-1/3 dark:text-gray-300">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                            <tr v-for="store in userData.stores" :key="store.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-blue-600">{{ store.code }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{{ store.area }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-center">
                                    <button 
                                        v-if="store.ticket_count > 0"
                                        @click="fetchTickets(store.id)"
                                        class="text-blue-600 hover:text-blue-800 hover:underline px-2 py-1 rounded hover:bg-blue-50 transition-colors"
                                    >
                                        {{ store.ticket_count }}
                                    </button>
                                    <!-- Quiet store: listed so the sector is complete, muted so problem stores still stand out. -->
                                    <span v-else class="text-gray-300 dark:text-gray-600">{{ store.ticket_count }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div
                                        class="w-full h-4 rounded-full shadow-sm transition-colors duration-500"
                                        :class="healthItem(store.health_bucket).class"
                                        :title="healthBarTitle(store)"
                                    ></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tickets Modal -->
        <Modal :show="showTicketsModal" @close="showTicketsModal = false" maxWidth="3xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6 border-b pb-4">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center dark:text-gray-100">
                        Tickets for {{ selectedStoreName }}
                        <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full" v-if="!modalLoading">
                            {{ selectedStoreTickets.length }}
                        </span>
                    </h2>
                    <button @click="showTicketsModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div v-if="modalLoading" class="flex flex-col items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600 mb-4"></div>
                    <p class="text-gray-500 text-sm dark:text-gray-300">Loading tickets...</p>
                </div>

                <div v-else class="max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" v-if="selectedStoreTickets.length > 0">
                        <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Ticket #</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Store</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Subject/Title</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Assignee</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Created</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                            <tr v-for="ticket in selectedStoreTickets" :key="ticket.id" class="hover:bg-blue-50 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-600">
                                    <Link :href="route('tickets.edit', ticket.id)" class="hover:underline">
                                        {{ ticket.ticket_key }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium dark:text-gray-100">
                                    {{ ticket.store ? ticket.store.code : 'N/A' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <Link :href="route('tickets.edit', ticket.id)" class="hover:underline line-clamp-1">
                                        {{ ticket.title }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    {{ ticket.assignee ? ticket.assignee.name : 'Unassigned' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-full" 
                                          :class="{
                                              'bg-green-100 text-green-700': ticket.status === 'open',
                                              'bg-blue-100 text-blue-700': ticket.status === 'in_progress',
                                              'bg-gray-100 text-gray-700': ticket.status === 'closed',
                                              'bg-yellow-100 text-yellow-700': ticket.status === 'waiting_service_provider',
                                              'bg-blue-100 text-blue-700': ticket.status === 'waiting_client_feedback'
                                          }">
                                        {{ getStatusLabel(ticket.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-300">
                                    {{ new Date(ticket.created_at).toLocaleDateString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="text-center py-12 text-gray-500 italic dark:text-gray-300">
                        No tickets found for this period.
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button @click="showTicketsModal = false" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors text-sm font-medium dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
