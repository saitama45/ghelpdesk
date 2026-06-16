<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    HomeIcon,
    Bars3Icon,
    XMarkIcon,
    UserGroupIcon,
    ClipboardDocumentListIcon,
    BuildingOfficeIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    Cog6ToothIcon,
    QueueListIcon,
    PresentationChartLineIcon,
    BriefcaseIcon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';
import { usePresence } from '@/Composables/usePresence.js';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import UserStatus from '@/Components/UserStatus.vue';

const props = defineProps({
    isCollapsed: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['toggle']);

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const dynamicForms = computed(() => page.props.dynamicForms || []);
const { hasPermission } = usePermission();

const visibleDynamicForms = computed(() => {
    return dynamicForms.value.filter(form => hasPermission(form.slug + '.view'));
});
const { currentStatus, init: initPresence, destroy: destroyPresence } = usePresence();
const { init: initSidebar, getSectionOrder, getChildOrder, getSectionLabel, getChildLabel, ensureDynamicFormChildren } = useSidebarOrder();

const route = window.route;

const openMenus = ref({
    adminTask: false,
    services: false,
    inventory: false,
    monitoring: false,
    references: false,
    userManagement: false,
    settings: false,
    reports: false,
});

const toggleMenu = (menu) => {
    if (props.isCollapsed) {
        return;
    }

    const isCurrentlyOpen = openMenus.value[menu];
    Object.keys(openMenus.value).forEach(key => {
        openMenus.value[key] = key === menu ? !isCurrentlyOpen : false;
    });
};

const toggleSidebar = () => {
    emit('toggle');
};

const collapsedFlyoutLinkClass = (isActive) => [
    'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
    isActive ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white'
];

const so = (s) => ({ order: getSectionOrder(s) });
const co = (s, c) => ({ order: getChildOrder(s, c) });
const dynamicFormChildId = (form) => 'form-' + form.slug;
const dynamicFormLabel = (form) => {
    const childId = dynamicFormChildId(form);
    const label = getChildLabel('services', childId);

    return label === childId ? form.name : label;
};

onMounted(() => {
    initPresence();
    initSidebar(page.props.sidebarLayout);
    ensureDynamicFormChildren(dynamicForms.value);
    if (route().current('attendance.*') || route().current('schedules.*') || route().current('presence.*') || route().current('kb-articles.*') || route().current('service-vehicle-trips.*')) {
        openMenus.value.adminTask = true;
    }
    if (route().current('npc-statuses.*') || route().current('payments.*') || route().current('cctv-monitoring.*')) {
        openMenus.value.monitoring = true;
    }
    if (route().current('tickets.*') || route().current('task-boards.*') || route().current('pos-requests.*') || route().current('sap-requests.*') || route().current('stamps.*') || route().current('dynamic-form.*')) {
        openMenus.value.services = true;
    }
    if (route().current('stock-ins.*') || route().current('reports.inventory') || route().current('assets.*') || route().current('stock-transfers.*') || route().current('stock-receivings.*')) {
        openMenus.value.inventory = true;
    }
    if (route().current('companies.*') || route().current('departments.*') || route().current('clusters.*') || route().current('stores.*') || route().current('vendors.*') || route().current('categories.*') || route().current('sub-categories.*') || route().current('items.*') || route().current('activity-templates.*') || route().current('request-types.*') || route().current('form-builder.*')) {
        openMenus.value.references = true;
    }
    if (route().current('users.*') || route().current('roles.*')) {
        openMenus.value.userManagement = true;
    }
    if (route().current('profile.*') || route().current('settings.*') || route().current('ticket-archive.*') || route().current('canned-messages.*') || route().current('leadership-points.*')) {
        openMenus.value.settings = true;
    }
    if (route().current('reports.*') && !route().current('reports.inventory')) {
        openMenus.value.reports = true;
    }
});

onUnmounted(() => {
    destroyPresence();
});

watch(dynamicForms, (forms) => {
    ensureDynamicFormChildren(forms);
});

const canSeeAdminTask = computed(() => {
    return hasPermission('attendance.view') ||
           hasPermission('attendance.logs') ||
           hasPermission('schedules.view') ||
           hasPermission('presence.view') ||
           hasPermission('kb_articles.view') ||
           hasPermission('service_vehicle_trips.view');
});

const canSeeMonitoring = computed(() => {
    return hasPermission('npc_status.view') || hasPermission('payments.view') || hasPermission('cctv_monitoring.view');
});

const canSeeServices = computed(() => {
    return hasPermission('tickets.view') ||
           hasPermission('task_boards.view') ||
           hasPermission('pos_requests.view') ||
           hasPermission('sap_requests.view') ||
           hasPermission('stamps.view') ||
           visibleDynamicForms.value.length > 0;
});

const canSeeInventory = computed(() => {
    return hasPermission('stock_ins.view') || hasPermission('reports.inventory') || hasPermission('assets.view') || hasPermission('stock_transfers.view') || hasPermission('stock_receivings.view');
});

const canSeeReferences = computed(() => {
    return hasPermission('companies.view') ||
           hasPermission('departments.view') ||
           hasPermission('clusters.view') ||
           hasPermission('stores.view') ||
           hasPermission('vendors.view') ||
           hasPermission('activity_templates.view') ||
           hasPermission('categories.view') ||
           hasPermission('subcategories.view') ||
           hasPermission('items.view') ||
           hasPermission('assets.view') ||
           hasPermission('request_types.view') ||
           hasPermission('form_builder.view');
});

const canSeeUserManagement = computed(() => {
    return hasPermission('users.view') || hasPermission('roles.view');
});

const canSeeReports = computed(() => {
    return hasPermission('reports.view') && (
        hasPermission('reports.store_health') ||
        hasPermission('reports.sla_performance') ||
        hasPermission('reports.assignee_performance')
    );
});

const canSeeSettings = computed(() => {
    return hasPermission('settings.view') || hasPermission('canned_messages.view') || hasPermission('leadership_points.view');
});
</script>

<template>
    <aside
        :class="[
            'bg-gray-900 text-white transition-all duration-300 ease-in-out flex flex-col h-full shrink-0',
            isCollapsed ? 'w-20 z-[80]' : 'w-72'
        ]"
    >
        <!-- Sidebar Header -->
        <div class="flex items-center justify-between px-4 border-b border-gray-800 shrink-0 h-16">
            <div v-if="!isCollapsed" class="flex items-center space-x-3 min-w-0">
                <div class="w-9 h-9 bg-white rounded-lg p-1 flex items-center justify-center flex-shrink-0">
                    <img src="/images/company_logo.png" alt="Company Logo" class="w-full h-full object-contain">
                </div>
                <span class="text-lg font-bold leading-none whitespace-nowrap text-white">TAS Service Center</span>
            </div>

            <button
                @click="toggleSidebar"
                class="p-2 rounded-lg hover:bg-gray-800 transition-colors duration-200 flex-shrink-0"
                :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            >
                <Bars3Icon v-if="isCollapsed" class="w-5 h-5 text-gray-400 group-hover:text-white" />
                <XMarkIcon v-else class="w-5 h-5 text-gray-400" />
            </button>
        </div>

        <!-- Navigation -->
        <nav
            :class="[
                'flex-1 p-4 flex flex-col gap-1',
                isCollapsed ? 'overflow-visible' : 'overflow-y-auto custom-scrollbar'
            ]"
        >
                <!-- Dashboard Link -->
                <Link
                    :href="route('dashboard')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('dashboard')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                    :style="so('dashboard')"
                >
                    <HomeIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate font-medium">{{ getSectionLabel('dashboard') }}</span>
                    <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                        {{ getSectionLabel('dashboard') }}
                    </div>
                </Link>

                <!-- Project Tracker Link -->
                <Link
                    v-if="hasPermission('projects.view')"
                    :href="route('projects.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('projects.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                    :style="so('projectTracker')"
                >
                    <ClipboardDocumentListIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate font-medium">{{ getSectionLabel('projectTracker') }}</span>
                    <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                        {{ getSectionLabel('projectTracker') }}
                    </div>
                </Link>

                <!-- Services Section -->
                <div v-if="canSeeServices" :style="so('services')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('services')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('tickets.*') || route().current('task-boards.*') || route().current('pos-requests.*') || route().current('sap-requests.*') || route().current('stamps.*') || route().current('dynamic-form.*')) && (isCollapsed || !openMenus.services)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <QueueListIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('services') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.services" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.services" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('services') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('tickets.view')" :style="co('services', 'tickets')">
                                <Link :href="route('tickets.index')" :class="collapsedFlyoutLinkClass(route().current('tickets.*'))">{{ getChildLabel('services', 'tickets') }}</Link>
                            </div>
                            <div v-if="hasPermission('task_boards.view')" :style="co('services', 'task-boards')">
                                <Link :href="route('task-boards.index')" :class="collapsedFlyoutLinkClass(route().current('task-boards.*'))">{{ getChildLabel('services', 'task-boards') }}</Link>
                            </div>
                            <div v-if="hasPermission('pos_requests.view')" :style="co('services', 'pos-requests')">
                                <Link :href="route('pos-requests.index')" :class="collapsedFlyoutLinkClass(route().current('pos-requests.*'))">{{ getChildLabel('services', 'pos-requests') }}</Link>
                            </div>
                            <div v-if="hasPermission('sap_requests.view')" :style="co('services', 'sap-requests')">
                                <Link :href="route('sap-requests.index')" :class="collapsedFlyoutLinkClass(route().current('sap-requests.*'))">{{ getChildLabel('services', 'sap-requests') }}</Link>
                            </div>
                            <div v-if="hasPermission('stamps.view')" :style="co('services', 'stamps')">
                                <Link :href="route('stamps.index')" :class="collapsedFlyoutLinkClass(route().current('stamps.*'))">{{ getChildLabel('services', 'stamps') }}</Link>
                            </div>
                            <div v-for="form in visibleDynamicForms" :key="'collapsed-form-' + form.slug" :style="co('services', dynamicFormChildId(form))">
                                <Link :href="route('dynamic-form.index', form.slug)" :class="collapsedFlyoutLinkClass(route().current('dynamic-form.*') && page.url.includes('/forms/' + form.slug))">{{ dynamicFormLabel(form) }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.services" class="pl-10 flex flex-col gap-0.5 mt-1 transition-all duration-300">
                        <div v-if="hasPermission('tickets.view')" :style="co('services', 'tickets')">
                            <Link :href="route('tickets.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('tickets.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('services', 'tickets') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('task_boards.view')" :style="co('services', 'task-boards')">
                            <Link :href="route('task-boards.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('task-boards.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('services', 'task-boards') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('pos_requests.view')" :style="co('services', 'pos-requests')">
                            <Link :href="route('pos-requests.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('pos-requests.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('services', 'pos-requests') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('sap_requests.view')" :style="co('services', 'sap-requests')">
                            <Link :href="route('sap-requests.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('sap-requests.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('services', 'sap-requests') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('stamps.view')" :style="co('services', 'stamps')">
                            <Link :href="route('stamps.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('stamps.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('services', 'stamps') }}</span></Link>
                        </div>
                        <div v-for="form in visibleDynamicForms" :key="form.slug" :style="co('services', dynamicFormChildId(form))">
                            <Link :href="route('dynamic-form.index', form.slug)" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('dynamic-form.*') && page.url.includes('/forms/' + form.slug) ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ dynamicFormLabel(form) }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- Inventory Section -->
                <div v-if="canSeeInventory" :style="so('inventory')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('inventory')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('stock-ins.*') || route().current('reports.inventory') || route().current('assets.*') || route().current('stock-transfers.*') || route().current('stock-receivings.*')) && (isCollapsed || !openMenus.inventory)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <svg :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('inventory') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.inventory" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.inventory" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('inventory') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('assets.view')" :style="co('inventory', 'assets')">
                                <Link :href="route('assets.index')" :class="collapsedFlyoutLinkClass(route().current('assets.*'))">{{ getChildLabel('inventory', 'assets') }}</Link>
                            </div>
                            <div v-if="hasPermission('stock_ins.view')" :style="co('inventory', 'stock-ins')">
                                <Link :href="route('stock-ins.index')" :class="collapsedFlyoutLinkClass(route().current('stock-ins.*'))">{{ getChildLabel('inventory', 'stock-ins') }}</Link>
                            </div>
                            <div v-if="hasPermission('stock_transfers.view')" :style="co('inventory', 'stock-transfers')">
                                <Link :href="route('stock-transfers.index')" :class="collapsedFlyoutLinkClass(route().current('stock-transfers.*'))">{{ getChildLabel('inventory', 'stock-transfers') }}</Link>
                            </div>
                            <div v-if="hasPermission('stock_receivings.view')" :style="co('inventory', 'stock-receivings')">
                                <Link :href="route('stock-receivings.index')" :class="collapsedFlyoutLinkClass(route().current('stock-receivings.*'))">{{ getChildLabel('inventory', 'stock-receivings') }}</Link>
                            </div>
                            <div v-if="hasPermission('reports.inventory')" :style="co('inventory', 'inventory-report')">
                                <Link :href="route('reports.inventory')" :class="collapsedFlyoutLinkClass(route().current('reports.inventory'))">{{ getChildLabel('inventory', 'inventory-report') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.inventory" class="pl-10 flex flex-col gap-0.5 mt-1 transition-all duration-300">
                        <div v-if="hasPermission('assets.view')" :style="co('inventory', 'assets')">
                            <Link :href="route('assets.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('assets.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('inventory', 'assets') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('stock_ins.view')" :style="co('inventory', 'stock-ins')">
                            <Link :href="route('stock-ins.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('stock-ins.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('inventory', 'stock-ins') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('stock_transfers.view')" :style="co('inventory', 'stock-transfers')">
                            <Link :href="route('stock-transfers.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('stock-transfers.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('inventory', 'stock-transfers') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('stock_receivings.view')" :style="co('inventory', 'stock-receivings')">
                            <Link :href="route('stock-receivings.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('stock-receivings.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('inventory', 'stock-receivings') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('reports.inventory')" :style="co('inventory', 'inventory-report')">
                            <Link :href="route('reports.inventory')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('reports.inventory') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('inventory', 'inventory-report') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- Monitoring Section -->
                <div v-if="canSeeMonitoring" :style="so('monitoring')" class="space-y-1 collapsed-menu-group">
                        <button
                        @click="toggleMenu('monitoring')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('npc-statuses.*') || route().current('payments.*') || route().current('cctv-monitoring.*')) && (isCollapsed || !openMenus.monitoring)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <PresentationChartLineIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('monitoring') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.monitoring" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.monitoring" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('monitoring') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('npc_status.view')" :style="co('monitoring', 'npc-status')">
                                <Link :href="route('npc-statuses.index')" :class="collapsedFlyoutLinkClass(route().current('npc-statuses.*'))">{{ getChildLabel('monitoring', 'npc-status') }}</Link>
                            </div>
                            <div v-if="hasPermission('cctv_monitoring.view')" :style="co('monitoring', 'cctv-monitoring')">
                                <Link :href="route('cctv-monitoring.index')" :class="collapsedFlyoutLinkClass(route().current('cctv-monitoring.*'))">{{ getChildLabel('monitoring', 'cctv-monitoring') }}</Link>
                            </div>
                            <div v-if="hasPermission('payments.view')" :style="co('monitoring', 'payments')">
                                <Link :href="route('payments.index')" :class="collapsedFlyoutLinkClass(route().current('payments.*'))">{{ getChildLabel('monitoring', 'payments') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.monitoring" class="pl-10 flex flex-col gap-0.5 mt-1 transition-all duration-300">
                        <div v-if="hasPermission('npc_status.view')" :style="co('monitoring', 'npc-status')">
                            <Link :href="route('npc-statuses.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('npc-statuses.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('monitoring', 'npc-status') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('cctv_monitoring.view')" :style="co('monitoring', 'cctv-monitoring')">
                            <Link :href="route('cctv-monitoring.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('cctv-monitoring.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('monitoring', 'cctv-monitoring') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('payments.view')" :style="co('monitoring', 'payments')">
                            <Link :href="route('payments.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('payments.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('monitoring', 'payments') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- Administrative Section -->
                <div v-if="canSeeAdminTask" :style="so('adminTask')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('adminTask')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('attendance.*') || route().current('schedules.*') || route().current('presence.*') || route().current('kb-articles.*') || route().current('service-vehicle-trips.*')) && (isCollapsed || !openMenus.adminTask)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <BriefcaseIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('adminTask') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.adminTask" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.adminTask" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('adminTask') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('attendance.view')" :style="co('adminTask', 'dtr')">
                                <Link :href="route('attendance.index')" :class="collapsedFlyoutLinkClass(route().current('attendance.index'))">{{ getChildLabel('adminTask', 'dtr') }}</Link>
                            </div>
                            <div v-if="hasPermission('attendance.logs')" :style="co('adminTask', 'attendance-logs')">
                                <Link :href="route('attendance.logs')" :class="collapsedFlyoutLinkClass(route().current('attendance.logs'))">{{ getChildLabel('adminTask', 'attendance-logs') }}</Link>
                            </div>
                            <div v-if="hasPermission('schedules.view')" :style="co('adminTask', 'scheduling')">
                                <Link :href="route('schedules.index')" :class="collapsedFlyoutLinkClass(route().current('schedules.*'))">{{ getChildLabel('adminTask', 'scheduling') }}</Link>
                            </div>
                            <div v-if="hasPermission('presence.view')" :style="co('adminTask', 'presence')">
                                <Link :href="route('presence.index')" :class="collapsedFlyoutLinkClass(route().current('presence.*'))">{{ getChildLabel('adminTask', 'presence') }}</Link>
                            </div>
                            <div v-if="hasPermission('kb_articles.view')" :style="co('adminTask', 'kb-articles')">
                                <Link :href="route('kb-articles.index')" :class="collapsedFlyoutLinkClass(route().current('kb-articles.*'))">{{ getChildLabel('adminTask', 'kb-articles') }}</Link>
                            </div>
                            <div v-if="hasPermission('service_vehicle_trips.view')" :style="co('adminTask', 'service-vehicle-trips')">
                                <Link :href="route('service-vehicle-trips.index')" :class="collapsedFlyoutLinkClass(route().current('service-vehicle-trips.*'))">{{ getChildLabel('adminTask', 'service-vehicle-trips') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.adminTask" class="pl-10 flex flex-col gap-0.5 mt-1 transition-all duration-300">
                        <div v-if="hasPermission('attendance.view')" :style="co('adminTask', 'dtr')">
                            <Link :href="route('attendance.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('attendance.index') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'dtr') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('attendance.logs')" :style="co('adminTask', 'attendance-logs')">
                            <Link :href="route('attendance.logs')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('attendance.logs') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'attendance-logs') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('schedules.view')" :style="co('adminTask', 'scheduling')">
                            <Link :href="route('schedules.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('schedules.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'scheduling') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('presence.view')" :style="co('adminTask', 'presence')">
                            <Link :href="route('presence.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('presence.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'presence') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('kb_articles.view')" :style="co('adminTask', 'kb-articles')">
                            <Link :href="route('kb-articles.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('kb-articles.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'kb-articles') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('service_vehicle_trips.view')" :style="co('adminTask', 'service-vehicle-trips')">
                            <Link :href="route('service-vehicle-trips.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('service-vehicle-trips.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('adminTask', 'service-vehicle-trips') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- References Section -->
                <div v-if="canSeeReferences" :style="so('references')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('references')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('companies.*') || route().current('departments.*') || route().current('clusters.*') || route().current('stores.*') || route().current('vendors.*') || route().current('categories.*') || route().current('sub-categories.*') || route().current('items.*') || route().current('assets.*') || route().current('activity-templates.*') || route().current('request-types.*') || route().current('form-builder.*')) && (isCollapsed || !openMenus.references)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <BuildingOfficeIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('references') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.references" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.references" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('references') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5 max-h-[70vh] overflow-y-auto custom-scrollbar">
                            <div v-if="hasPermission('companies.view')" :style="co('references', 'companies')">
                                <Link :href="route('companies.index')" :class="collapsedFlyoutLinkClass(route().current('companies.*'))">{{ getChildLabel('references', 'companies') }}</Link>
                            </div>
                            <div v-if="hasPermission('departments.view')" :style="co('references', 'departments')">
                                <Link :href="route('departments.index')" :class="collapsedFlyoutLinkClass(route().current('departments.*'))">Departments</Link>
                            </div>
                            <div v-if="hasPermission('clusters.view')" :style="co('references', 'clusters')">
                                <Link :href="route('clusters.index')" :class="collapsedFlyoutLinkClass(route().current('clusters.*'))">{{ getChildLabel('references', 'clusters') }}</Link>
                            </div>
                            <div v-if="hasPermission('stores.view')" :style="co('references', 'stores')">
                                <Link :href="route('stores.index')" :class="collapsedFlyoutLinkClass(route().current('stores.*'))">{{ getChildLabel('references', 'stores') }}</Link>
                            </div>
                            <div v-if="hasPermission('vendors.view')" :style="co('references', 'vendors')">
                                <Link :href="route('vendors.index')" :class="collapsedFlyoutLinkClass(route().current('vendors.*'))">{{ getChildLabel('references', 'vendors') }}</Link>
                            </div>
                            <div v-if="hasPermission('activity_templates.view')" :style="co('references', 'activity-templates')">
                                <Link :href="route('activity-templates.index')" :class="collapsedFlyoutLinkClass(route().current('activity-templates.*'))">{{ getChildLabel('references', 'activity-templates') }}</Link>
                            </div>
                            <div v-if="hasPermission('categories.view')" :style="co('references', 'categories')">
                                <Link :href="route('categories.index')" :class="collapsedFlyoutLinkClass(route().current('categories.*'))">{{ getChildLabel('references', 'categories') }}</Link>
                            </div>
                            <div v-if="hasPermission('subcategories.view')" :style="co('references', 'sub-categories')">
                                <Link :href="route('sub-categories.index')" :class="collapsedFlyoutLinkClass(route().current('sub-categories.*'))">{{ getChildLabel('references', 'sub-categories') }}</Link>
                            </div>
                            <div v-if="hasPermission('items.view')" :style="co('references', 'items')">
                                <Link :href="route('items.index')" :class="collapsedFlyoutLinkClass(route().current('items.*'))">{{ getChildLabel('references', 'items') }}</Link>
                            </div>
                            <div v-if="hasPermission('request_types.view')" :style="co('references', 'request-types')">
                                <Link :href="route('request-types.index')" :class="collapsedFlyoutLinkClass(route().current('request-types.*'))">{{ getChildLabel('references', 'request-types') }}</Link>
                            </div>
                            <div v-if="hasPermission('form_builder.view')" :style="co('references', 'form-builder')">
                                <Link :href="route('form-builder.index')" :class="collapsedFlyoutLinkClass(route().current('form-builder.*'))">{{ getChildLabel('references', 'form-builder') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.references" class="pl-10 flex flex-col gap-0.5 mt-1">
                        <div v-if="hasPermission('companies.view')" :style="co('references', 'companies')">
                            <Link :href="route('companies.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('companies.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'companies') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('departments.view')" :style="co('references', 'departments')">
                            <Link :href="route('departments.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('departments.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>Departments</span></Link>
                        </div>
                        <div v-if="hasPermission('clusters.view')" :style="co('references', 'clusters')">
                            <Link :href="route('clusters.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('clusters.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'clusters') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('stores.view')" :style="co('references', 'stores')">
                            <Link :href="route('stores.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('stores.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'stores') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('vendors.view')" :style="co('references', 'vendors')">
                            <Link :href="route('vendors.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('vendors.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'vendors') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('activity_templates.view')" :style="co('references', 'activity-templates')">
                            <Link :href="route('activity-templates.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('activity-templates.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'activity-templates') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('categories.view')" :style="co('references', 'categories')">
                            <Link :href="route('categories.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('categories.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'categories') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('subcategories.view')" :style="co('references', 'sub-categories')">
                            <Link :href="route('sub-categories.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('sub-categories.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'sub-categories') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('items.view')" :style="co('references', 'items')">
                            <Link :href="route('items.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('items.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'items') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('request_types.view')" :style="co('references', 'request-types')">
                            <Link :href="route('request-types.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('request-types.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'request-types') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('form_builder.view')" :style="co('references', 'form-builder')">
                            <Link :href="route('form-builder.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('form-builder.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('references', 'form-builder') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- Reports Section -->
                <div v-if="canSeeReports" :style="so('reports')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('reports')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            route().current('reports.*') && !route().current('reports.inventory') && (isCollapsed || !openMenus.reports)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <PresentationChartLineIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('reports') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.reports" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.reports" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('reports') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('reports.store_health')" :style="co('reports', 'store-health')">
                                <Link :href="route('reports.store-health')" :class="collapsedFlyoutLinkClass(route().current('reports.store-health'))">{{ getChildLabel('reports', 'store-health') }}</Link>
                            </div>
                            <div v-if="hasPermission('reports.sla_performance')" :style="co('reports', 'sla-performance')">
                                <Link :href="route('reports.sla-performance')" :class="collapsedFlyoutLinkClass(route().current('reports.sla-performance'))">{{ getChildLabel('reports', 'sla-performance') }}</Link>
                            </div>
                            <div v-if="hasPermission('reports.assignee_performance')" :style="co('reports', 'assignee-performance')">
                                <Link :href="route('reports.assignee-performance')" :class="collapsedFlyoutLinkClass(route().current('reports.assignee-performance'))">{{ getChildLabel('reports', 'assignee-performance') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.reports" class="pl-10 flex flex-col gap-0.5 mt-1">
                        <div v-if="hasPermission('reports.store_health')" :style="co('reports', 'store-health')">
                            <Link :href="route('reports.store-health')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('reports.store-health') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('reports', 'store-health') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('reports.sla_performance')" :style="co('reports', 'sla-performance')">
                            <Link :href="route('reports.sla-performance')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('reports.sla-performance') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('reports', 'sla-performance') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('reports.assignee_performance')" :style="co('reports', 'assignee-performance')">
                            <Link :href="route('reports.assignee-performance')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('reports.assignee-performance') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('reports', 'assignee-performance') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- User Management Section -->
                <div v-if="canSeeUserManagement" :style="so('userManagement')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('userManagement')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('users.*') || route().current('roles.*')) && (isCollapsed || !openMenus.userManagement)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <UserGroupIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('userManagement') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.userManagement" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.userManagement" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('userManagement') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('users.view')" :style="co('userManagement', 'users')">
                                <Link :href="route('users.index')" :class="collapsedFlyoutLinkClass(route().current('users.*'))">{{ getChildLabel('userManagement', 'users') }}</Link>
                            </div>
                            <div v-if="hasPermission('roles.view')" :style="co('userManagement', 'roles')">
                                <Link :href="route('roles.index')" :class="collapsedFlyoutLinkClass(route().current('roles.*'))">{{ getChildLabel('userManagement', 'roles') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.userManagement" class="pl-10 flex flex-col gap-0.5 mt-1">
                        <div v-if="hasPermission('users.view')" :style="co('userManagement', 'users')">
                            <Link :href="route('users.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('users.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('userManagement', 'users') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('roles.view')" :style="co('userManagement', 'roles')">
                            <Link :href="route('roles.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('roles.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('userManagement', 'roles') }}</span></Link>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div v-if="canSeeSettings" :style="so('settings')" class="space-y-1 collapsed-menu-group">
                    <button
                        @click="toggleMenu('settings')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('profile.edit') || route().current('settings.*') || route().current('ticket-archive.*') || route().current('canned-messages.*') || route().current('leadership-points.*')) && (isCollapsed || !openMenus.settings)
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <Cog6ToothIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">{{ getSectionLabel('settings') }}</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.settings" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.settings" class="w-4 h-4 ml-2" />
                    </button>

                    <div v-if="isCollapsed" class="collapsed-flyout">
                        <div class="px-3 py-2 border-b border-gray-700">
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">{{ getSectionLabel('settings') }}</p>
                        </div>
                        <div class="p-2 flex flex-col gap-0.5">
                            <div v-if="hasPermission('settings.view')" :style="co('settings', 'system-settings')">
                                <Link :href="route('settings.index')" :class="collapsedFlyoutLinkClass(route().current('settings.index'))">{{ getChildLabel('settings', 'system-settings') }}</Link>
                            </div>
                            <div v-if="hasPermission('settings.view')" :style="co('settings', 'ticket-archive')">
                                <Link :href="route('ticket-archive.index')" :class="collapsedFlyoutLinkClass(route().current('ticket-archive.*'))">{{ getChildLabel('settings', 'ticket-archive') }}</Link>
                            </div>
                            <div v-if="hasPermission('canned_messages.view')" :style="co('settings', 'canned-messages')">
                                <Link :href="route('canned-messages.index')" :class="collapsedFlyoutLinkClass(route().current('canned-messages.*'))">{{ getChildLabel('settings', 'canned-messages') }}</Link>
                            </div>
                            <div v-if="hasPermission('leadership_points.view')" :style="co('settings', 'leadership-points')">
                                <Link :href="route('leadership-points.index')" :class="collapsedFlyoutLinkClass(route().current('leadership-points.*'))">{{ getChildLabel('settings', 'leadership-points') }}</Link>
                            </div>
                            <div :style="co('settings', 'profile')">
                                <Link :href="route('profile.edit')" :class="collapsedFlyoutLinkClass(route().current('profile.edit'))">{{ getChildLabel('settings', 'profile') }}</Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="!isCollapsed && openMenus.settings" class="pl-10 flex flex-col gap-0.5 mt-1">
                        <div v-if="hasPermission('settings.view')" :style="co('settings', 'system-settings')">
                            <Link :href="route('settings.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('settings.index') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('settings', 'system-settings') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('settings.view')" :style="co('settings', 'ticket-archive')">
                            <Link :href="route('ticket-archive.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('ticket-archive.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('settings', 'ticket-archive') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('canned_messages.view')" :style="co('settings', 'canned-messages')">
                            <Link :href="route('canned-messages.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('canned-messages.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('settings', 'canned-messages') }}</span></Link>
                        </div>
                        <div v-if="hasPermission('leadership_points.view')" :style="co('settings', 'leadership-points')">
                            <Link :href="route('leadership-points.index')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('leadership-points.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('settings', 'leadership-points') }}</span></Link>
                        </div>
                        <div :style="co('settings', 'profile')">
                            <Link :href="route('profile.edit')" :class="['flex items-center p-2 rounded-lg text-sm transition-all duration-200', route().current('profile.edit') ? 'text-white font-bold' : 'text-gray-400 hover:text-white']"><span>{{ getChildLabel('settings', 'profile') }}</span></Link>
                        </div>
                    </div>
                </div>

        </nav>

        <!-- User Section -->
        <div class="p-4 border-t border-gray-800 shrink-0">
            <div class="flex items-center">
                <div class="relative">
                    <div v-if="user.profile_photo" class="w-8 h-8 rounded-full overflow-hidden border border-gray-600">
                        <img :src="'/serve-storage/' + user.profile_photo" class="h-full w-full object-cover" :alt="user.name">
                    </div>
                    <div v-else class="w-8 h-8 bg-gray-600 rounded-full flex items-center justify-center">
                        <span class="text-sm font-medium">
                            {{ user.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </span>
                    </div>
                    <UserStatus :status="currentStatus" size="lg" class="absolute -bottom-0.5 -right-0.5 border-2 border-gray-900" />
                </div>
                <div v-if="!isCollapsed" class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-medium truncate">{{ user.name || 'User' }}</p>
                    <p class="text-xs text-gray-400 truncate">{{ user.email || 'user@example.com' }}</p>
                </div>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #4b5563;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #6b7280;
}

.collapsed-menu-group {
    position: relative;
}

.collapsed-flyout {
    position: absolute;
    left: calc(100% + 0.5rem);
    top: 0;
    z-index: 70;
    min-width: 14rem;
    max-width: 18rem;
    border: 1px solid #374151;
    border-radius: 0.5rem;
    background: #111827;
    box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.28), 0 8px 10px -6px rgb(0 0 0 / 0.28);
    opacity: 0;
    pointer-events: none;
    transform: translateX(-0.25rem);
    visibility: hidden;
    transition: opacity 150ms ease, transform 150ms ease, visibility 150ms ease;
}

.collapsed-flyout::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: -0.75rem;
    top: 0;
    width: 0.75rem;
}

.collapsed-menu-group:hover > .collapsed-flyout,
.collapsed-menu-group:focus-within > .collapsed-flyout {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(0);
    visibility: visible;
}
</style>
