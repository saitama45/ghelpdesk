<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { XMarkIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    data: { type: Object, default: null },
    // Effective Entity/Company ids from the dashboard filter. The tab is built from
    // this selection, so every drill-down has to carry it too.
    entityIds: { type: Array, default: () => [] },
    // Year/month from the dashboard filter bar, so a drill-down covers the same period.
    filters: { type: Object, default: () => ({}) },
});

const { showError } = useToast();

const partners = computed(() => props.data?.partners || []);
const brands = computed(() => props.data?.brands || []);
const totals = computed(() => props.data?.totals || null);
const agingRegister = computed(() => props.data?.aging_register || []);
const agingDays = computed(() => props.data?.aging_days ?? 3);

// --- Brand axis --------------------------------------------------------------
// 'all' or a brand id (as string). Picking a brand re-scopes every panel below.
const activeBrand = ref('all');

const brandOptions = computed(() => [
    { value: 'all', label: 'All Brands' },
    ...brands.value.map(b => ({
        value: String(b.id),
        label: b.code ? `${b.name} (${b.code})` : b.name,
    })),
]);

const activeBrandRow = computed(() =>
    activeBrand.value === 'all'
        ? null
        : brands.value.find(b => String(b.id) === String(activeBrand.value)) || null
);

// Scope label + the metric block the KPI tiles read.
const scopeLabel = computed(() => activeBrandRow.value ? activeBrandRow.value.name : 'All Brands');
const scopeMetrics = computed(() => activeBrandRow.value || totals.value);

// Partner rows for the current brand scope. Under a brand, the brand row already
// carries its own per-partner split — the same metric shape, so the table is identical.
const partnerRows = computed(() => activeBrandRow.value ? (activeBrandRow.value.partners || []) : partners.value);

// The brand id a drill-down should carry (0 = the "No Brand" bucket → 'none').
const brandParam = computed(() => {
    if (!activeBrandRow.value) return null;
    return activeBrandRow.value.id === 0 ? 'none' : String(activeBrandRow.value.id);
});

// Aging register, narrowed to the selected brand.
const agingRows = computed(() => activeBrandRow.value
    ? agingRegister.value.filter(r => r.brand === activeBrandRow.value.name)
    : agingRegister.value);

// --- Partner × Brand matrix ---------------------------------------------------
// Built entirely from partners[].brands, so it costs no extra payload.
const matrixBrands = computed(() => brands.value);
const matrixRows = computed(() => partners.value.map(p => ({
    partner: p,
    cells: matrixBrands.value.map(b => (p.brands || []).find(pb => pb.id === b.id) || null),
})));

const KPI_TILES = [
    { key: 'total', label: 'Escalations', accent: 'border-l-blue-500', state: 'all' },
    { key: 'open', label: 'Open', accent: 'border-l-amber-500', state: 'open' },
    { key: 'closed', label: 'Closed', accent: 'border-l-emerald-500', state: 'closed' },
    { key: 'aging_open', label: 'Aging Open', accent: 'border-l-red-500', state: 'aging' },
];

const daysLabel = (value) => (value === null || value === undefined ? '—' : `${value}d`);

// SLA colour: green when the partner mostly closes inside target, red when it doesn't.
const slaClass = (rate) => {
    if (rate === null || rate === undefined) return 'text-gray-400 dark:text-gray-500';
    if (rate >= 90) return 'text-emerald-600 dark:text-emerald-400';
    if (rate >= 70) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
};

const statusLabel = (status) => (status || '').replace(/_/g, ' ');

const statusClass = (status) => ({
    open: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    for_schedule: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    waiting_service_provider: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    waiting_client_feedback: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    resolved: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
    closed: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
}[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');

// --- Drill-down modal ---------------------------------------------------------
const showDrill = ref(false);
const drillLoading = ref(false);
const drillTitle = ref('');
const drillSubtitle = ref('');
const drillTickets = ref([]);
const drillMetrics = ref(null);

const openDrill = async ({ title, subtitle, params }) => {
    drillTitle.value = title;
    drillSubtitle.value = subtitle;
    drillTickets.value = [];
    drillMetrics.value = null;
    drillLoading.value = true;
    showDrill.value = true;

    try {
        const { data } = await axios.get(route('dashboard.partner-performance.tickets', {}, false), {
            params: {
                ...(brandParam.value ? { brand_id: brandParam.value } : {}),
                ...(props.filters?.year ? { year: props.filters.year } : {}),
                ...(props.filters?.month ? { month: props.filters.month } : {}),
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
                ...params,
            },
        });
        drillTickets.value = data.tickets || [];
        drillMetrics.value = data.metrics || null;
    } catch (e) {
        showError('Unable to load the escalation breakdown.');
        showDrill.value = false;
    } finally {
        drillLoading.value = false;
    }
};

const stateNoun = (state, count) => {
    const plural = count === 1 ? '' : 's';
    return {
        open: `${count} open escalation${plural}`,
        closed: `${count} closed escalation${plural}`,
        aging: `${count} escalation${plural} open beyond ${agingDays.value} days`,
        breached: `${count} SLA-breached escalation${plural}`,
    }[state] || `${count} escalation${plural}`;
};

const openTotalsDrill = (tile) => openDrill({
    title: `${tile.label} — ${scopeLabel.value}`,
    subtitle: stateNoun(tile.state, scopeMetrics.value?.[tile.key] ?? 0),
    params: { state: tile.state },
});

const openPartnerDrill = (row, state = 'all') => openDrill({
    title: row.name,
    subtitle: `${scopeLabel.value} — ${stateNoun(state, state === 'open' ? row.open : state === 'closed' ? row.closed : row.total)}`,
    params: { vendor_id: row.id, state },
});

const openBrandDrill = (row, state = 'all') => openDrill({
    title: row.name,
    subtitle: stateNoun(state, state === 'open' ? row.open : state === 'closed' ? row.closed : row.total),
    // Explicit brand id — the matrix/brand table is clickable from the "All Brands" view too.
    params: { brand_id: row.id === 0 ? 'none' : String(row.id), state },
});

const openCellDrill = (partner, brand, cell) => {
    if (!cell) return;
    openDrill({
        title: `${partner.name} · ${brand.name}`,
        subtitle: stateNoun('all', cell.total),
        params: { vendor_id: partner.id, brand_id: brand.id === 0 ? 'none' : String(brand.id), state: 'all' },
    });
};
</script>

<template>
    <div v-if="data">
        <!-- Header -->
        <div class="mb-4 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
            <div>
                <h3 class="text-xl font-black text-gray-900 dark:text-gray-100">Partner Performance</h3>
                <p class="text-[11px] text-gray-400 dark:text-gray-500">
                    Tickets escalated to partners via <span class="font-bold">Escalate to Partner</span> child tickets —
                    {{ data.period_label }} · as of {{ data.as_of }}
                </p>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <label class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Brand</label>
                <div class="w-full sm:w-72">
                    <Autocomplete
                        :model-value="activeBrand"
                        @update:modelValue="activeBrand = $event || 'all'"
                        :options="brandOptions"
                        label-key="label"
                        value-key="value"
                        size="sm"
                        placeholder="All Brands"
                    />
                </div>
            </div>
        </div>

        <!-- Brand pills -->
        <div v-if="brands.length" class="mb-6 flex flex-wrap items-center gap-2">
            <button
                @click="activeBrand = 'all'"
                class="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-wider transition-colors border"
                :class="activeBrand === 'all'
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-gray-500 border-gray-200 hover:text-gray-700 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'"
            >
                All Brands
            </button>
            <button
                v-for="brand in brands"
                :key="brand.id"
                @click="activeBrand = String(brand.id)"
                class="px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-wider transition-colors border flex items-center gap-1.5"
                :class="String(activeBrand) === String(brand.id)
                    ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                    : 'bg-white text-gray-500 border-gray-200 hover:text-gray-700 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'"
            >
                {{ brand.code || brand.name }}
                <span
                    v-if="brand.open > 0"
                    class="inline-flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full text-[10px] font-black"
                    :class="String(activeBrand) === String(brand.id) ? 'bg-white/25 text-white' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'"
                >
                    {{ brand.open }}
                </span>
            </button>
        </div>

        <!-- Empty state -->
        <div v-if="!partners.length" class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-12 text-center dark:bg-gray-800 dark:border-gray-700">
            <p class="text-sm font-bold text-gray-600 dark:text-gray-300">No partner escalations in this scope.</p>
            <p class="text-xs text-gray-400 mt-1.5 dark:text-gray-500">
                This tab counts child tickets created with <span class="font-bold">Escalate to Partner</span> on a ticket.
                Widen the Entity filter or the period above if you expect to see some.
            </p>
        </div>

        <template v-else>
            <!-- KPI tiles -->
            <div class="grid grid-cols-2 lg:grid-cols-7 gap-3 mb-6">
                <button
                    v-for="tile in KPI_TILES"
                    :key="tile.key"
                    @click="openTotalsDrill(tile)"
                    class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 text-left border-l-4 hover:border-blue-400 hover:shadow transition dark:bg-gray-800 dark:border-gray-700"
                    :class="tile.accent"
                >
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ scopeMetrics?.[tile.key] ?? 0 }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">{{ tile.label }}</p>
                </button>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ scopeMetrics?.closure_rate ?? 0 }}%</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Closure Rate</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ daysLabel(scopeMetrics?.avg_days) }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Avg Turnaround</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black" :class="slaClass(scopeMetrics?.sla_rate)">
                        {{ scopeMetrics?.sla_rate === null || scopeMetrics?.sla_rate === undefined ? '—' : scopeMetrics.sla_rate + '%' }}
                    </p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Closed On Time</p>
                </div>
            </div>

            <!-- Partner scorecard -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6 dark:bg-gray-800 dark:border-gray-700">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between dark:border-gray-700">
                    <div>
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Partner Scorecard</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ scopeLabel }} — click any number to see the escalations behind it</p>
                    </div>
                    <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-500">{{ partnerRows.length }} partner{{ partnerRows.length === 1 ? '' : 's' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                <th class="px-5 py-2.5 text-left">Partner</th>
                                <th class="px-3 py-2.5 text-right">Escalations</th>
                                <th class="px-3 py-2.5 text-right">Open</th>
                                <th class="px-3 py-2.5 text-right">Closed</th>
                                <th class="px-3 py-2.5 text-left w-40">Closure</th>
                                <th class="px-3 py-2.5 text-right">Avg TAT</th>
                                <th class="px-3 py-2.5 text-right">On Time</th>
                                <th class="px-3 py-2.5 text-right">Aging</th>
                                <th class="px-5 py-2.5 text-right">Oldest Open</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="row in partnerRows" :key="row.id" class="hover:bg-blue-50/60 dark:hover:bg-gray-700/40">
                                <td class="px-5 py-2.5">
                                    <p class="font-bold text-gray-800 dark:text-gray-100">{{ row.name }}</p>
                                    <p class="text-[11px] text-gray-400 dark:text-gray-500">
                                        <span v-if="row.code">{{ row.code }} · </span>{{ row.vendor_type || 'Partner' }}
                                        <span v-if="row.is_active === false" class="text-red-500"> · inactive</span>
                                    </p>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <button @click="openPartnerDrill(row, 'all')" class="font-black text-gray-900 tabular-nums hover:text-blue-600 hover:underline dark:text-gray-100">{{ row.total }}</button>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <button @click="openPartnerDrill(row, 'open')" class="font-black tabular-nums hover:underline" :class="row.open > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500'">{{ row.open }}</button>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <button @click="openPartnerDrill(row, 'closed')" class="font-black tabular-nums hover:underline" :class="row.closed > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-gray-500'">{{ row.closed }}</button>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden dark:bg-gray-700">
                                            <div class="h-full rounded-full bg-emerald-500" :style="{ width: row.closure_rate + '%' }"></div>
                                        </div>
                                        <span class="text-[11px] font-bold text-gray-500 tabular-nums dark:text-gray-400">{{ row.closure_rate }}%</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-right font-bold text-gray-700 tabular-nums dark:text-gray-200">{{ daysLabel(row.avg_days) }}</td>
                                <td class="px-3 py-2.5 text-right font-black tabular-nums" :class="slaClass(row.sla_rate)">
                                    {{ row.sla_rate === null ? '—' : row.sla_rate + '%' }}
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <button v-if="row.aging_open > 0" @click="openPartnerDrill(row, 'aging')" class="font-black text-red-600 tabular-nums hover:underline dark:text-red-400">{{ row.aging_open }}</button>
                                    <span v-else class="text-gray-400 tabular-nums dark:text-gray-500">0</span>
                                </td>
                                <td class="px-5 py-2.5 text-right font-bold tabular-nums" :class="row.oldest_open_days >= agingDays ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'">
                                    {{ daysLabel(row.oldest_open_days) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Per-brand roll-up + Partner × Brand matrix -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-6">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Escalations by Brand</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">Owning company of the store the escalation sits on</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-5 py-2.5 text-left">Brand</th>
                                    <th class="px-3 py-2.5 text-right">Partners</th>
                                    <th class="px-3 py-2.5 text-right">Total</th>
                                    <th class="px-3 py-2.5 text-right">Open</th>
                                    <th class="px-3 py-2.5 text-right">Closed</th>
                                    <th class="px-5 py-2.5 text-right">Avg TAT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="row in brands" :key="row.id" class="hover:bg-blue-50/60 dark:hover:bg-gray-700/40">
                                    <td class="px-5 py-2.5">
                                        <button @click="activeBrand = String(row.id)" class="font-bold text-gray-800 hover:text-blue-600 dark:text-gray-100">{{ row.name }}</button>
                                        <span v-if="row.code" class="ml-1.5 text-[11px] text-gray-400 dark:text-gray-500">{{ row.code }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-bold text-gray-600 tabular-nums dark:text-gray-300">{{ (row.partners || []).length }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        <button @click="openBrandDrill(row, 'all')" class="font-black text-gray-900 tabular-nums hover:text-blue-600 hover:underline dark:text-gray-100">{{ row.total }}</button>
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        <button @click="openBrandDrill(row, 'open')" class="font-black tabular-nums hover:underline" :class="row.open > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500'">{{ row.open }}</button>
                                    </td>
                                    <td class="px-3 py-2.5 text-right">
                                        <button @click="openBrandDrill(row, 'closed')" class="font-black tabular-nums hover:underline" :class="row.closed > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-gray-500'">{{ row.closed }}</button>
                                    </td>
                                    <td class="px-5 py-2.5 text-right font-bold text-gray-700 tabular-nums dark:text-gray-200">{{ daysLabel(row.avg_days) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Partner × Brand</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">open / closed per cell — click a cell for its escalations</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-5 py-2.5 text-left sticky left-0 bg-gray-50 dark:bg-gray-900/40">Partner</th>
                                    <th v-for="b in matrixBrands" :key="b.id" class="px-3 py-2.5 text-center whitespace-nowrap">{{ b.code || b.name }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="row in matrixRows" :key="row.partner.id" class="hover:bg-blue-50/40 dark:hover:bg-gray-700/30">
                                    <td class="px-5 py-2 font-bold text-gray-800 whitespace-nowrap sticky left-0 bg-white dark:bg-gray-800 dark:text-gray-100">{{ row.partner.name }}</td>
                                    <td v-for="(cell, i) in row.cells" :key="i" class="px-3 py-2 text-center">
                                        <button
                                            v-if="cell"
                                            @click="openCellDrill(row.partner, matrixBrands[i], cell)"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md hover:bg-blue-100 dark:hover:bg-blue-900/40"
                                            :title="`${cell.total} escalation(s)`"
                                        >
                                            <span class="font-black tabular-nums" :class="cell.open > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-300 dark:text-gray-600'">{{ cell.open }}</span>
                                            <span class="text-gray-300 dark:text-gray-600">/</span>
                                            <span class="font-black tabular-nums" :class="cell.closed > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-300 dark:text-gray-600'">{{ cell.closed }}</span>
                                        </button>
                                        <span v-else class="text-gray-200 dark:text-gray-700">·</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Aging register: what to chase -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between dark:border-gray-700">
                    <div>
                        <h4 class="text-sm font-black text-gray-700 uppercase tracking-wider dark:text-gray-200">Longest Waiting Escalations</h4>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ scopeLabel }} — open with a partner, oldest first (alarm at {{ agingDays }} days)</p>
                    </div>
                </div>
                <div v-if="agingRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                <th class="px-5 py-2.5 text-left">Escalation</th>
                                <th class="px-3 py-2.5 text-left">Partner</th>
                                <th class="px-3 py-2.5 text-left">Brand / Store</th>
                                <th class="px-3 py-2.5 text-left">Status</th>
                                <th class="px-3 py-2.5 text-right">Age</th>
                                <th class="px-5 py-2.5 text-right">Open</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="row in agingRows" :key="row.id" class="hover:bg-blue-50/60 dark:hover:bg-gray-700/40">
                                <td class="px-5 py-2.5">
                                    <p class="font-bold text-gray-800 dark:text-gray-100">
                                        <span class="text-blue-600 dark:text-blue-400">{{ row.key }}</span> — {{ row.title }}
                                    </p>
                                    <p v-if="row.parent_key" class="text-[11px] text-gray-400 dark:text-gray-500">from {{ row.parent_key }} · escalated {{ row.created_at }}</p>
                                </td>
                                <td class="px-3 py-2.5 font-bold text-gray-700 dark:text-gray-200">{{ row.partner }}</td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">
                                    {{ row.brand }}
                                    <span v-if="row.store" class="block text-[11px] text-gray-400 dark:text-gray-500">{{ row.store }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-black tabular-nums" :class="row.is_aging ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'">{{ daysLabel(row.age_days) }}</td>
                                <td class="px-5 py-2.5 text-right">
                                    <a :href="row.url" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-blue-600 bg-blue-50 hover:bg-blue-100 transition dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50" title="Open escalation">
                                        <ArrowTopRightOnSquareIcon class="w-4 h-4" />
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-5 py-10 text-center text-sm text-gray-400 dark:text-gray-500">Nothing is currently open with a partner.</p>
            </div>
        </template>

        <!-- Drill-down modal -->
        <Modal :show="showDrill" max-width="5xl" @close="showDrill = false">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-black text-gray-900 dark:text-gray-100">{{ drillTitle }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ drillSubtitle }}</p>
                    </div>
                    <button @click="showDrill = false" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>

                <div v-if="drillLoading" class="py-16 text-center text-sm text-gray-400">
                    <span class="inline-block w-5 h-5 mr-2 align-middle border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></span> Loading…
                </div>

                <template v-else>
                    <div v-if="drillMetrics" class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-4">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                            <p class="text-xl font-black text-gray-900 dark:text-gray-100">{{ drillMetrics.total }}</p>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Escalations</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-3 dark:bg-amber-900/20">
                            <p class="text-xl font-black text-amber-700 dark:text-amber-300">{{ drillMetrics.open }}</p>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Open</p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-900/20">
                            <p class="text-xl font-black text-emerald-700 dark:text-emerald-300">{{ drillMetrics.closed }}</p>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Closed</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                            <p class="text-xl font-black text-gray-900 dark:text-gray-100">{{ daysLabel(drillMetrics.avg_days) }}</p>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Avg TAT</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                            <p class="text-xl font-black" :class="slaClass(drillMetrics.sla_rate)">{{ drillMetrics.sla_rate === null ? '—' : drillMetrics.sla_rate + '%' }}</p>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">On Time</p>
                        </div>
                    </div>

                    <div v-if="drillTickets.length" class="max-h-[55vh] overflow-auto rounded-lg border border-gray-100 dark:border-gray-700">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900">
                                <tr class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                    <th class="px-4 py-2 text-left">Escalation</th>
                                    <th class="px-3 py-2 text-left">Partner</th>
                                    <th class="px-3 py-2 text-left">Brand / Store</th>
                                    <th class="px-3 py-2 text-left">Status</th>
                                    <th class="px-3 py-2 text-right">Age / TAT</th>
                                    <th class="px-4 py-2 text-right">Open</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="t in drillTickets" :key="t.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-4 py-2">
                                        <p class="font-bold text-gray-800 dark:text-gray-100">
                                            <span class="text-blue-600 dark:text-blue-400">{{ t.key }}</span> — {{ t.title }}
                                        </p>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500">
                                            <span v-if="t.parent_key">from {{ t.parent_key }} · </span>escalated {{ t.created_at }}<span v-if="t.closed_at"> · closed {{ t.closed_at }}</span>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ t.partner }}</td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                        {{ t.brand }}
                                        <span v-if="t.store" class="block text-[11px] text-gray-400 dark:text-gray-500">{{ t.store }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider" :class="statusClass(t.status)">{{ statusLabel(t.status) }}</span>
                                        <span v-if="t.is_breached" class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-black bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">SLA</span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-bold tabular-nums" :class="t.is_aging ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'">
                                        {{ t.is_closed ? daysLabel(t.days_to_close) : daysLabel(t.age_days) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <a :href="t.url" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-blue-600 bg-blue-50 hover:bg-blue-100 transition dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-900/50" title="Open escalation">
                                            <ArrowTopRightOnSquareIcon class="w-4 h-4" />
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-12 text-center text-sm text-gray-400 dark:text-gray-500">No escalations in this slice.</p>
                </template>
            </div>
        </Modal>
    </div>
</template>
