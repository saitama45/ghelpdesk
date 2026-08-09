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
});

const emit = defineEmits(['change-group']);

const { showError } = useToast();

const totals = computed(() => props.data?.totals || null);
const groups = computed(() => props.data?.groups || []);
const stores = computed(() => props.data?.stores || []);
const activeGroup = computed(() => props.data?.group || '');

// Only stores that actually hold deployed units get a matrix row — a store with
// nothing encoded yet has no health to report and would read as a false green.
const deployedStores = computed(() => stores.value.filter(s => s.total > 0));

// The board's fixed column axis, server-supplied in the reference sheet's order.
// Fixed (not "groups present"), so every row has the same shape and the board can
// be read straight down a column.
const columns = computed(() => props.data?.columns || []);
const legend = computed(() => props.data?.legend || []);

const groupOptions = computed(() => [
    { value: '', label: 'All Groups' },
    ...groups.value.map(g => ({ value: g.name, label: `${g.name} (${g.total})` })),
]);

const cellFor = (store, groupName) => (store.groups || {})[groupName] || null;

// The sheet's four fills. Solid, saturated, and identical in both themes so a
// printed/shared screenshot matches what the reference sheet looks like.
const BAND_FILL = {
    healthy: 'bg-green-600 text-white',
    warning: 'bg-yellow-300 text-yellow-950',
    at_risk: 'bg-orange-400 text-orange-950',
    critical: 'bg-red-600 text-white',
};

const BAND_LABEL = {
    healthy: 'Healthy',
    warning: 'Warning',
    at_risk: 'At Risk',
    critical: 'Critical',
};

const bandFill = (band) => BAND_FILL[band] || BAND_FILL.healthy;
const bandLabel = (band) => BAND_LABEL[band] || 'Healthy';

/** A group with no units at this store is left blank, exactly like the sheet. */
const cellClass = (cell) => {
    if (!cell || cell.total === 0) return 'bg-gray-100 text-gray-400 dark:bg-gray-900/60 dark:text-gray-600';
    return bandFill(cell.band);
};

// Colour is never the only signal: every filled cell states its counts, and the
// title/aria text spells the band out for screen readers.
const cellText = (cell) => {
    if (!cell || cell.total === 0) return '';
    return cell.impacted > 0 ? `${cell.impacted}/${cell.total}` : `${cell.total}`;
};

const cellTitle = (store, groupName, cell) => {
    if (!cell || cell.total === 0) return `${store.code || store.name} · ${groupName}: no deployed units`;
    return cell.impacted > 0
        ? `${store.code || store.name} · ${groupName}: ${bandLabel(cell.band)} — ${cell.impacted} of ${cell.total} units impacted`
        : `${store.code || store.name} · ${groupName}: Healthy — all ${cell.total} units operational`;
};

const statusLabel = (status) => (status === 'impacted' ? 'Impacted' : 'Operational');

const statusPill = (status) => status === 'impacted'
    ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'
    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';

const ticketStatusLabel = (status) => (status || '').replace(/_/g, ' ');

// --- Drill-down ---------------------------------------------------------------
const showDrill = ref(false);
const drillLoading = ref(false);
const drillTitle = ref('');
const drillSubtitle = ref('');
const drillUnits = ref([]);

const openDrill = async ({ title, subtitle, params }) => {
    drillTitle.value = title;
    drillSubtitle.value = subtitle;
    drillUnits.value = [];
    showDrill.value = true;
    drillLoading.value = true;

    try {
        const { data } = await axios.get(route('dashboard.asset-health.units'), {
            params: {
                ...(activeGroup.value ? { group: activeGroup.value } : {}),
                ...(props.entityIds.length ? { entity_ids: props.entityIds } : {}),
                ...params,
            },
        });
        drillUnits.value = data.units || [];
    } catch (e) {
        showError('Unable to load the unit breakdown.');
        showDrill.value = false;
    } finally {
        drillLoading.value = false;
    }
};

const openStoreDrill = (store, status = null) => openDrill({
    title: store.code ? `${store.code} — ${store.name}` : store.name,
    subtitle: status === 'impacted'
        ? `${store.impacted} impacted unit${store.impacted === 1 ? '' : 's'}`
        : `${store.total} deployed unit${store.total === 1 ? '' : 's'} · ${store.impacted} impacted`,
    params: { store_id: store.id, ...(status ? { status } : {}) },
});

const openCellDrill = (store, groupName, cell) => {
    if (!cell || cell.total === 0) return;
    openDrill({
        title: `${store.code || store.name} · ${groupName}`,
        subtitle: cell.impacted > 0
            ? `${cell.impacted} of ${cell.total} unit${cell.total === 1 ? '' : 's'} impacted`
            : `All ${cell.total} unit${cell.total === 1 ? '' : 's'} operational`,
        params: { store_id: store.id, group: groupName },
    });
};
</script>

<template>
    <div v-if="data">
        <!-- Header -->
        <div class="mb-4 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
            <div>
                <h3 class="text-xl font-black text-gray-900 dark:text-gray-100">Asset Operational Health</h3>
                <p class="text-[11px] text-gray-400 dark:text-gray-500">
                    Every deployed physical unit is <span class="font-bold">Operational</span> until it is tagged to an
                    active support ticket, and returns to Operational only when no active linked ticket remains.
                </p>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <label class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Group</label>
                <div class="w-full sm:w-72">
                    <Autocomplete
                        :model-value="activeGroup"
                        @update:modelValue="emit('change-group', $event || '')"
                        :options="groupOptions"
                        label-key="label"
                        value-key="value"
                        size="sm"
                        placeholder="All Groups"
                    />
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!totals || totals.units === 0" class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-12 text-center dark:bg-gray-800 dark:border-gray-700">
            <p class="text-sm font-bold text-gray-600 dark:text-gray-300">No deployed units in this scope.</p>
            <p class="text-xs text-gray-400 mt-1.5 dark:text-gray-500">
                This tab counts posted, serialized <span class="font-bold">Fixed</span> stock-in units at their current
                store. Widen the Entity filter, or stock in and allocate equipment to a store to populate it.
            </p>
        </div>

        <template v-else>
            <!-- Summary cards -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-blue-500 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.units }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Deployed Units</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-emerald-500 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-emerald-600 dark:text-emerald-400">{{ totals.operational }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Operational</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 border-l-4 border-l-red-500 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-red-600 dark:text-red-400">{{ totals.impacted }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Impacted</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.impacted_pct }}%</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Impacted %</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ totals.stores_impacted }}</p>
                    <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mt-1 dark:text-gray-400">Stores Affected</p>
                </div>
            </div>

            <!-- Group breakdown -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-6 dark:bg-gray-800 dark:border-gray-700">
                <h4 class="text-[11px] font-black text-gray-500 uppercase tracking-wider mb-3 dark:text-gray-400">Units by Group</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                    <button
                        v-for="group in groups"
                        :key="group.name"
                        @click="emit('change-group', activeGroup === group.name ? '' : group.name)"
                        class="rounded-lg border p-3 text-left transition hover:shadow"
                        :class="activeGroup === group.name
                            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 dark:border-blue-500'
                            : 'border-gray-200 hover:border-blue-300 dark:border-gray-700'"
                    >
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider truncate dark:text-gray-400" :title="group.name">
                            {{ group.name }}
                        </p>
                        <p class="text-2xl font-black text-gray-900 mt-0.5 dark:text-gray-100">{{ group.total }}</p>
                        <p class="text-[11px] font-bold mt-0.5" :class="group.impacted > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">
                            {{ group.impacted > 0 ? `${group.impacted} impacted` : 'All operational' }}
                        </p>
                    </button>
                </div>
            </div>

            <!-- Asset Monitoring Board — laid out like the reference Google Sheet:
                 LEGEND box, a GROUP header row over a Category sub-header row, then
                 one row per store ending in Active Issues / Owner / Next Action / ETA. -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                <div class="px-4 py-3 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3 dark:border-gray-700">
                    <h4 class="text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">
                        Asset Monitoring Board
                    </h4>

                    <!-- LEGEND -->
                    <div class="flex items-stretch border border-gray-400 dark:border-gray-600">
                        <div class="flex items-center px-2 bg-gray-50 border-r border-gray-400 text-[10px] font-black text-gray-600 dark:bg-gray-900/50 dark:border-gray-600 dark:text-gray-300">
                            LEGEND
                        </div>
                        <div>
                            <div
                                v-for="band in legend"
                                :key="band.key"
                                class="flex items-stretch border-b border-gray-300 last:border-b-0 dark:border-gray-700"
                            >
                                <span class="w-20 px-2 py-0.5 text-[10px] font-bold text-gray-700 dark:text-gray-300">{{ band.label }}</span>
                                <span class="w-20 border-l border-gray-300 dark:border-gray-700" :class="bandFill(band.key)"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm border-collapse">
                        <thead>
                            <!-- Row 1: GROUP -->
                            <tr class="bg-[#1f3864] text-white">
                                <th class="px-3 py-2 text-left text-[11px] font-black uppercase tracking-wider border border-white/30 whitespace-nowrap">
                                    Group
                                </th>
                                <th
                                    v-for="column in columns"
                                    :key="column.name"
                                    class="px-3 py-2 text-left text-[11px] font-black uppercase tracking-wider border border-white/30 whitespace-nowrap"
                                >
                                    {{ column.name }}
                                </th>
                                <th rowspan="2" class="px-3 py-2 text-center text-[11px] font-black uppercase tracking-wider border border-white/30 bg-[#31859c] whitespace-nowrap">
                                    Active Issues
                                </th>
                                <th rowspan="2" class="px-3 py-2 text-center text-[11px] font-black uppercase tracking-wider border border-white/30 bg-[#31859c] whitespace-nowrap">
                                    Owner
                                </th>
                                <th rowspan="2" class="px-3 py-2 text-center text-[11px] font-black uppercase tracking-wider border border-white/30 bg-[#31859c] whitespace-nowrap">
                                    Next Action
                                </th>
                                <th rowspan="2" class="px-3 py-2 text-center text-[11px] font-black uppercase tracking-wider border border-white/30 bg-[#31859c] whitespace-nowrap">
                                    ETA
                                </th>
                            </tr>
                            <!-- Row 2: Category — the real Category names mapped to each group -->
                            <tr class="bg-[#1f3864] text-white align-top">
                                <th class="px-3 py-2 text-left text-[11px] font-black uppercase tracking-wider border border-white/30 whitespace-nowrap">
                                    Category
                                </th>
                                <th
                                    v-for="column in columns"
                                    :key="column.name"
                                    class="px-3 py-2 text-left text-[10px] font-semibold border border-white/30 min-w-[7rem]"
                                >
                                    <span v-if="column.categories.length" class="block leading-snug">
                                        <span v-for="category in column.categories" :key="category" class="block">{{ category }}</span>
                                    </span>
                                    <span v-else class="italic text-white/50">unmapped</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="store in deployedStores" :key="store.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <!-- Store label, filled with the row's worst band like the sheet -->
                                <th scope="row" class="px-3 py-1 text-left border border-gray-300 dark:border-gray-600" :class="bandFill(store.band)">
                                    <button
                                        @click="openStoreDrill(store)"
                                        class="text-[11px] font-black hover:underline whitespace-nowrap"
                                        :title="`${store.name} — ${bandLabel(store.band)}`"
                                    >
                                        {{ store.code || store.name }}
                                    </button>
                                    <span class="sr-only">{{ bandLabel(store.band) }}</span>
                                </th>

                                <!-- One coloured cell per group -->
                                <td
                                    v-for="column in columns"
                                    :key="column.name"
                                    class="p-0 border border-gray-300 dark:border-gray-600"
                                >
                                    <button
                                        v-if="cellFor(store, column.name)"
                                        @click="openCellDrill(store, column.name, cellFor(store, column.name))"
                                        class="w-full h-full min-h-[1.75rem] px-2 text-[11px] font-black transition hover:ring-2 hover:ring-inset hover:ring-blue-500"
                                        :class="cellClass(cellFor(store, column.name))"
                                        :title="cellTitle(store, column.name, cellFor(store, column.name))"
                                    >
                                        {{ cellText(cellFor(store, column.name)) }}
                                        <span class="sr-only">{{ bandLabel(cellFor(store, column.name).band) }}</span>
                                    </button>
                                    <div v-else class="min-h-[1.75rem] bg-gray-100 dark:bg-gray-900/60" :title="`${store.code} · ${column.name}: no deployed units`"></div>
                                </td>

                                <!-- Action columns -->
                                <td class="px-3 py-1 text-center border border-gray-300 dark:border-gray-600">
                                    <button
                                        v-if="store.active_tickets > 0"
                                        @click="openStoreDrill(store, 'impacted')"
                                        class="font-black text-red-600 hover:underline dark:text-red-400"
                                    >
                                        {{ store.active_tickets }}
                                    </button>
                                    <span v-else class="text-gray-500 dark:text-gray-400">0</span>
                                </td>
                                <td class="px-3 py-1 text-center text-[11px] border border-gray-300 whitespace-nowrap dark:border-gray-600 dark:text-gray-300">
                                    <span v-if="store.owner" :class="store.is_partner ? 'font-bold text-indigo-700 dark:text-indigo-300' : ''">
                                        {{ store.owner }}
                                    </span>
                                    <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                </td>
                                <td class="px-3 py-1 text-center text-[11px] border border-gray-300 whitespace-nowrap dark:border-gray-600 dark:text-gray-300">
                                    {{ store.next_action || '—' }}
                                </td>
                                <td class="px-3 py-1 text-center text-[11px] border border-gray-300 whitespace-nowrap dark:border-gray-600 dark:text-gray-300">
                                    {{ store.eta || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="px-4 py-2 text-[10px] text-gray-400 border-t border-gray-200 dark:border-gray-700 dark:text-gray-500">
                    Cell colour = worst active ticket priority on that group's units (urgent → Critical, high → At Risk, medium/low → Warning).
                    Owner is the assigned partner or technician, Next Action follows the ticket's workflow state, and ETA is its SLA resolution target.
                </p>
            </div>
        </template>

        <!-- Unit drill-down -->
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

                <div v-if="drillLoading" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">Loading units…</div>
                <div v-else-if="!drillUnits.length" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">No units match this selection.</div>
                <div v-else class="max-h-[60vh] overflow-y-auto -mx-2 px-2">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900">
                            <tr>
                                <th class="px-3 py-2 text-left text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Unit</th>
                                <th class="px-3 py-2 text-left text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Group</th>
                                <th class="px-3 py-2 text-left text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Status</th>
                                <th class="px-3 py-2 text-left text-[11px] font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">Active Tickets</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="unit in drillUnits" :key="unit.id" class="align-top">
                                <td class="px-3 py-2.5">
                                    <div class="font-bold text-gray-900 dark:text-gray-100">{{ unit.serial_no || unit.barcode || `Unit #${unit.id}` }}</div>
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                        {{ [unit.item_code, unit.brand, unit.model].filter(Boolean).join(' · ') || '—' }}
                                    </div>
                                    <div class="text-[11px] text-gray-400 dark:text-gray-500">{{ unit.store_code || unit.store_name }}</div>
                                </td>
                                <td class="px-3 py-2.5 text-[11px] text-gray-600 dark:text-gray-300">
                                    <div class="font-bold">{{ unit.group }}</div>
                                    <div v-if="unit.sub_category" class="text-gray-400 dark:text-gray-500">{{ unit.sub_category }}</div>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-black" :class="statusPill(unit.status)">
                                        {{ statusLabel(unit.status) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div v-if="!unit.tickets?.length" class="text-[11px] text-gray-400 dark:text-gray-500">None</div>
                                    <!-- Every active linked ticket, not only the newest — a unit stays
                                         impacted until all of them are resolved or closed. -->
                                    <ul v-else class="space-y-1">
                                        <li v-for="ticket in unit.tickets" :key="ticket.id">
                                            <a :href="ticket.url" target="_blank" class="inline-flex items-center gap-1 text-blue-600 hover:underline dark:text-blue-400">
                                                <span class="font-bold text-[11px]">{{ ticket.key }}</span>
                                                <ArrowTopRightOnSquareIcon class="w-3 h-3" />
                                            </a>
                                            <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                                — {{ ticket.title }}
                                                <span class="capitalize">({{ ticketStatusLabel(ticket.status) }})</span>
                                            </span>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Modal>
    </div>
</template>
