<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PuzzlePieceIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import BrandHealthReport from '@/Components/BrandHealthReport.vue';
import StoreHealthReport from '@/Components/StoreHealthReport.vue';
import { usePermission } from '@/Composables/usePermission.js';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import { MODULE_SECTIONS } from '@/Composables/useModuleRegistry.js';

const props = defineProps({
    section: { type: String, required: true },
    sectionData: { type: Object, default: null },
});

const kpiTone = {
    blue: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    red: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
};
const priorityClass = (p) => ({
    urgent: 'bg-red-100 text-red-800',
    high: 'bg-orange-100 text-orange-800',
    medium: 'bg-amber-100 text-amber-800',
    low: 'bg-gray-100 text-gray-700',
}[p] || 'bg-gray-100 text-gray-700');
const statusLabel = (s) => (s || '').replace(/_/g, ' ');
const isProvider = computed(() => props.sectionData?.accessView === 'provider');

// Services in-page sub-tabs (prototype parity): Service Catalog | Ticket Board | Inventory Management.
const serviceTab = ref('catalog');
const hasServiceTabs = computed(() => props.section === 'services' && !!props.sectionData?.department);
const openCount = computed(() =>
    (props.sectionData?.board || []).reduce((sum, lane) => sum + (lane.count || 0), 0)
);
const showCatalog = computed(() => !hasServiceTabs.value || serviceTab.value === 'catalog');
// Ticket Board opens the real /tickets page; Inventory Management opens its
// workspace. Service Catalog is the in-page view.
const selectServiceTab = (tab) => {
    if (tab === 'board') {
        router.visit(route('tickets.index'));
        return;
    }
    if (tab === 'inventory') {
        router.visit(route('inventory-workspace.index'));
        return;
    }
    serviceTab.value = tab;
};

const page = usePage();
const { hasPermission } = usePermission();
const { init: initSidebar, getSectionLabel, getChildLabel, getChildOrder, ensureDynamicFormChildren } = useSidebarOrder();

// Load saved order/labels so the hub matches the sidebar customisation layer.
initSidebar(page.props.sidebarLayout);

const section = computed(() => MODULE_SECTIONS[props.section] || null);

const sectionLabel = computed(() =>
    section.value ? getSectionLabel(section.value.id) : 'Hub'
);

/** Dynamic forms are Services children not present in the static registry. */
const dynamicFormTiles = computed(() => {
    if (props.section !== 'services') return [];
    return (page.props.dynamicForms || [])
        .filter((form) => hasPermission(form.slug + '.view'))
        .map((form) => {
            const childId = 'form-' + form.slug;
            const label = getChildLabel('services', childId);
            return {
                id: childId,
                icon: PuzzlePieceIcon,
                description: 'Dynamic form',
                routeName: 'dynamic-form.index',
                routeParams: [form.slug],
                resolvedLabel: label === childId ? form.name : label,
            };
        });
});

/** A permission entry may be a string or an array meaning "any of". */
const permitted = (permission) => {
    if (!permission) return true;
    if (Array.isArray(permission)) return permission.some((p) => hasPermission(p));
    return hasPermission(permission);
};

/** Visible children in the user's saved order, each with its resolved label. */
const tiles = computed(() => {
    if (!section.value) return [];
    const registryTiles = section.value.children
        .filter((child) => permitted(child.permission))
        .map((child) => {
            const label = getChildLabel(section.value.id, child.id);
            return {
                ...child,
                resolvedLabel: label === child.id ? child.label : label,
            };
        });
    return [...registryTiles, ...dynamicFormTiles.value]
        .sort((a, b) => getChildOrder(section.value.id, a.id) - getChildOrder(section.value.id, b.id));
});

onMounted(() => {
    ensureDynamicFormChildren(page.props.dynamicForms || []);
});

// Re-fetch just the section payload (e.g. after a Brand Health WCF action).
const reloadSection = () => router.reload({ only: ['sectionData'] });

// Service Exchange catalogue (what the viewed department offers).
const catalog = computed(() => props.sectionData?.catalog || []);
const startService = (svc) => {
    if (svc.route_name) {
        router.visit(route(svc.route_name));
    } else {
        // No fulfilling module — start a general request against this department.
        router.visit(route('tickets.index'));
    }
};

// Monitoring hub: Live Store Health (sectors vs corporate office sub-tabs).
const healthSubTab = ref('sectors');
const hasStoreHealth = computed(() => props.section === 'monitoring' && !!props.sectionData?.storeHealth);
const storeHealth = computed(() => props.sectionData?.storeHealth || {});

// Sections that render a generic top-level KPI summary (eyebrow + KPI strip).
const showGenericKpis = computed(() =>
    ['reports', 'adminTask', 'references'].includes(props.section) && !!props.sectionData?.kpis
);
</script>

<template>
    <AppLayout :title="sectionLabel" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <Head :title="sectionLabel" />

        <div class="py-6">
            <div v-if="section" class="space-y-6">
                <!-- Header: eyebrow verb + title + description -->
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-[10px] font-black uppercase tracking-[0.2em] text-blue-600 dark:text-blue-400">
                            {{ (section.verb || '').toUpperCase() }}
                        </div>
                        <h1 class="mt-1 text-2xl font-black tracking-tight text-gray-900 dark:text-white">
                            {{ sectionLabel }}
                        </h1>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ section.description }}
                        </p>
                    </div>
                    <span class="text-[11px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">
                        {{ tiles.length }} {{ tiles.length === 1 ? 'module' : 'modules' }}
                    </span>
                </div>

                <!-- Services in-page sub-tabs -->
                <div v-if="hasServiceTabs" class="flex items-center gap-1 border-b border-gray-200 dark:border-gray-700">
                    <button
                        type="button"
                        @click="selectServiceTab('catalog')"
                        :style="serviceTab === 'catalog' ? { color: 'var(--dept-accent)', borderColor: 'var(--dept-accent)' } : {}"
                        :class="['-mb-px border-b-2 px-4 py-2 text-sm font-bold transition-colors',
                            serviceTab === 'catalog' ? '' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200']"
                    >Service Catalog</button>
                    <button
                        type="button"
                        @click="selectServiceTab('board')"
                        :style="serviceTab === 'board' ? { color: 'var(--dept-accent)', borderColor: 'var(--dept-accent)' } : {}"
                        :class="['-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-bold transition-colors',
                            serviceTab === 'board' ? '' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200']"
                    >
                        Ticket Board
                        <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-black text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ openCount }}</span>
                    </button>
                    <button
                        v-if="sectionData.canInventory"
                        type="button"
                        @click="selectServiceTab('inventory')"
                        class="-mb-px border-b-2 border-transparent px-4 py-2 text-sm font-bold text-gray-500 transition-colors hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                    >Inventory Management</button>
                </div>

                <!-- Section content: real-data widgets (Services) — Service Catalog tab -->
                <template v-if="sectionData && sectionData.department && showCatalog">
                    <!-- KPI strip -->
                    <div v-if="sectionData.kpis.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div
                            v-for="kpi in sectionData.kpis"
                            :key="kpi.label"
                            class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ kpi.label }}</span>
                                <span class="h-2 w-2 rounded-full" :class="kpiTone[kpi.tone] || kpiTone.blue"></span>
                            </div>
                            <div class="mt-1 text-2xl font-black text-gray-900 dark:text-white">{{ kpi.value }}</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ kpi.note }}</div>
                        </div>
                    </div>

                    <!-- Provider desk: department request queue -->
                    <div v-if="isProvider" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: 'var(--dept-accent)' }">Service Provider Desk</div>
                                <div class="text-sm font-bold text-gray-900 dark:text-white">Requests assigned to {{ sectionData.department.name }}</div>
                            </div>
                            <Link :href="route('tickets.index')" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">View all →</Link>
                        </div>
                        <div v-if="sectionData.requests.length" class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-gray-100 text-[10px] font-black uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                        <th class="px-4 py-2">Ticket</th>
                                        <th class="px-4 py-2">Subject</th>
                                        <th class="px-4 py-2">Requester</th>
                                        <th class="px-4 py-2">Priority</th>
                                        <th class="px-4 py-2">Status</th>
                                        <th class="px-4 py-2">Owner</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in sectionData.requests" :key="r.id" class="border-b border-gray-50 hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-700/30">
                                        <td class="px-4 py-2">
                                            <Link :href="route('tickets.edit', r.id)" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">{{ r.key }}</Link>
                                        </td>
                                        <td class="px-4 py-2 max-w-[22rem] truncate text-gray-800 dark:text-gray-200">{{ r.title }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400 truncate max-w-[12rem]">{{ r.requester || '—' }}</td>
                                        <td class="px-4 py-2">
                                            <span class="rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="priorityClass(r.priority)">{{ r.priority }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-xs font-semibold capitalize text-gray-600 dark:text-gray-300">{{ statusLabel(r.status) }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ r.assignee || 'Unassigned' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">No open requests for this department.</div>
                    </div>

                    <!-- Internal Customer View header -->
                    <div v-else class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800/60 dark:bg-blue-900/20">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300">↗</span>
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-700 dark:text-blue-300">Internal Customer View</div>
                                    <h3 class="text-base font-black text-gray-900 dark:text-white">
                                        {{ sectionData.homeName || 'You' }} requesting services from {{ sectionData.department.name }}
                                    </h3>
                                    <p class="mt-0.5 text-xs text-blue-800/80 dark:text-blue-200/80">
                                        You are visiting another department. Submit a request from its catalogue and track fulfilment without seeing its internal work queue.
                                    </p>
                                </div>
                            </div>
                            <Link :href="route('tickets.index')" class="shrink-0 rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:bg-transparent dark:text-blue-300">View my requests</Link>
                        </div>

                        <!-- Requests-to strip -->
                        <div v-if="sectionData.requestsTo" class="mt-3 flex flex-wrap items-center gap-3 border-t border-blue-200 pt-3 dark:border-blue-800/60">
                            <div class="text-[10px] font-black uppercase tracking-widest text-blue-700/70 dark:text-blue-300/70">
                                {{ sectionData.homeName }} requests to {{ sectionData.department.name }}
                                <span class="ml-1 text-blue-900 dark:text-blue-100">· {{ sectionData.requestsTo.active }} active</span>
                            </div>
                            <Link
                                v-for="r in sectionData.requestsTo.latest"
                                :key="r.id"
                                :href="route('tickets.edit', r.id)"
                                class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-blue-800 hover:underline dark:bg-blue-900/40 dark:text-blue-200"
                            >
                                <span class="font-mono font-bold">{{ r.key }}</span> · {{ statusLabel(r.status) }}
                            </Link>
                            <span class="text-[11px] font-semibold text-blue-700/80 dark:text-blue-300/80">Completed this month · {{ sectionData.requestsTo.completed_mtd }}</span>
                            <Link :href="route('tickets.index')" class="ml-auto text-[11px] font-bold text-blue-700 hover:underline dark:text-blue-300">Track all →</Link>
                        </div>
                    </div>

                    <!-- Service Exchange: the viewed department's service catalogue.
                         For customers this is the primary content (what TAS provides). -->
                    <template v-if="catalog.length">
                        <div class="flex items-center justify-between">
                            <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: 'var(--dept-accent)' }">
                                {{ isProvider ? 'Services delivered by ' + sectionData.department.name : sectionData.department.name + ' provides' }}
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <button
                                v-for="svc in catalog"
                                :key="svc.id"
                                type="button"
                                @click="startService(svc)"
                                class="group flex flex-col rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-blue-500/60"
                            >
                                <span class="text-[9px] font-black uppercase tracking-[0.16em] text-blue-600 dark:text-blue-400">{{ sectionData.department.name }} provides</span>
                                <span class="mt-1 font-bold text-gray-900 dark:text-white">{{ svc.name }}</span>
                                <span class="mt-0.5 flex-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ svc.description }}</span>
                                <span class="mt-3 flex items-center justify-between">
                                    <span v-if="svc.eta" class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">◷ Target response · {{ svc.eta }}</span>
                                    <span class="ml-auto text-[11px] font-bold text-blue-600 dark:text-blue-400">{{ isProvider ? 'Manage →' : 'Start →' }}</span>
                                </span>
                            </button>
                        </div>
                    </template>

                    <!-- Brand Health (TAS desk) — SAME shared component as the
                         dashboard's Live Brand Health tab (one centralised view). -->
                    <BrandHealthReport
                        v-if="sectionData.brandHealth"
                        :data="sectionData.brandHealth"
                        @changed="reloadSection"
                    />

                    <!-- Recent Requests -->
                    <div v-if="sectionData.recentRequests && sectionData.recentRequests.length" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                            <div class="text-[10px] font-black uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">Recent Requests</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white">Latest activity for {{ sectionData.department.name }}</div>
                        </div>
                        <ul class="divide-y divide-gray-50 dark:divide-gray-700/50">
                            <li v-for="r in sectionData.recentRequests" :key="r.id" class="flex items-center gap-3 px-4 py-2.5">
                                <Link :href="route('tickets.edit', r.id)" class="font-mono text-xs font-bold text-blue-600 hover:underline dark:text-blue-400 shrink-0">{{ r.key }}</Link>
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ r.title }}</span>
                                <span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider" :class="priorityClass(r.priority)">{{ r.priority }}</span>
                                <span class="shrink-0 text-[11px] font-semibold capitalize text-gray-500 dark:text-gray-400 hidden sm:inline">{{ statusLabel(r.status) }}</span>
                            </li>
                        </ul>
                    </div>

                </template>

                <!-- Monitoring hub: Live Store Health (shared component, same as dashboard) -->
                <template v-if="hasStoreHealth">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: 'var(--dept-accent)' }">Proactive Operations · Live Store Health</div>
                        <Link :href="route('reports.store-health')" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">Full report →</Link>
                    </div>

                    <!-- Store Sectors vs Corporate Office sub-tabs -->
                    <div v-if="storeHealth.office" class="inline-flex items-center gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 dark:border-gray-700 dark:bg-gray-900/60">
                        <button
                            type="button"
                            @click="healthSubTab = 'sectors'"
                            :class="['rounded-lg px-4 py-1.5 text-xs font-black uppercase tracking-wide transition-all',
                                healthSubTab === 'sectors' ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-700 dark:text-blue-300' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400']"
                        >Store Sectors</button>
                        <button
                            type="button"
                            @click="healthSubTab = 'office'"
                            :class="['rounded-lg px-4 py-1.5 text-xs font-black uppercase tracking-wide transition-all',
                                healthSubTab === 'office' ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-700 dark:text-blue-300' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400']"
                        >Corporate Office</button>
                    </div>

                    <StoreHealthReport
                        v-show="healthSubTab === 'sectors'"
                        :report-data="storeHealth.reportData"
                        :summary="storeHealth.summary"
                        :thresholds="storeHealth.thresholds"
                        :threshold-bands="storeHealth.thresholdBands"
                        :entity-health="storeHealth.entityHealth"
                        :entity-ids="sectionData.entityIds"
                        :show-filters="false"
                        :filters="{}"
                    />
                    <StoreHealthReport
                        v-if="storeHealth.office"
                        v-show="healthSubTab === 'office'"
                        :report-data="storeHealth.office.reportData"
                        :summary="storeHealth.office.summary"
                        :thresholds="storeHealth.thresholds"
                        :threshold-bands="storeHealth.thresholdBands"
                        :entity-health="storeHealth.office.entityHealth"
                        :entity-ids="sectionData.entityIds"
                        :show-filters="false"
                        :filters="{}"
                    />

                </template>

                <!-- Generic hub KPI summary (Reports, Administrative, References) -->
                <template v-if="showGenericKpis">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-[10px] font-black uppercase tracking-[0.18em]" :style="{ color: 'var(--dept-accent)' }">{{ sectionData.eyebrow }}</div>
                        <Link v-if="sectionData.link" :href="route(sectionData.link.route)" class="text-xs font-bold text-blue-600 hover:underline dark:text-blue-400">{{ sectionData.link.label }} →</Link>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div v-for="kpi in sectionData.kpis" :key="kpi.label" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ kpi.label }}</span>
                                <span class="h-2 w-2 rounded-full" :class="kpiTone[kpi.tone] || kpiTone.blue"></span>
                            </div>
                            <div class="mt-1 text-2xl font-black text-gray-900 dark:text-white">{{ kpi.value }}</div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ kpi.note }}</div>
                        </div>
                    </div>
                </template>

                <!-- Modules live in the floating launcher (right edge). Empty state
                     only when there is no content AND no modules to launch. -->
                <div
                    v-if="showCatalog && !tiles.length && !sectionData"
                    class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 text-center dark:border-gray-700 dark:bg-gray-900/40"
                >
                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                        You don't have access to any modules in this section.
                    </p>
                </div>

            </div>

            <!-- Unknown section id -->
            <div v-else class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 text-center dark:border-gray-700 dark:bg-gray-900/40">
                <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Unknown section.</p>
                <Link :href="route('dashboard')" class="mt-2 inline-block text-sm font-bold text-blue-600 hover:underline dark:text-blue-400">
                    Back to Dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
