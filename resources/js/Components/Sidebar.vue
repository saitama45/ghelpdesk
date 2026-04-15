<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
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
} from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';
import { usePresence } from '@/Composables/usePresence.js';
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
const { hasPermission } = usePermission();
const { currentStatus, init: initPresence, destroy: destroyPresence } = usePresence();

// For Laravel ziggy route helper if not global
const route = window.route;

const openMenus = ref({
    adminTask: false,
    operations: false,
    references: false,
    userManagement: false,
    settings: false,
    reports: false,
});

const toggleMenu = (menu) => {
    if (props.isCollapsed) {
        emit('toggle');
        // Delay opening the menu until sidebar is expanded
        setTimeout(() => {
            Object.keys(openMenus.value).forEach(key => {
                openMenus.value[key] = key === menu;
            });
        }, 300);
    } else {
        const isCurrentlyOpen = openMenus.value[menu];
        // Close all menus and toggle the clicked one
        Object.keys(openMenus.value).forEach(key => {
            openMenus.value[key] = key === menu ? !isCurrentlyOpen : false;
        });
    }
};

const toggleSidebar = () => {
    emit('toggle');
};

// Auto-expand menus based on current route
onMounted(() => {
    initPresence();
    if (route().current('attendance.*') || route().current('schedules.*') || route().current('presence.*')) {
        openMenus.value.adminTask = true;
    }
    if (route().current('tickets.*') || route().current('pos-requests.*') || route().current('sap-requests.*')) {
        openMenus.value.operations = true;
    }
    if (route().current('companies.*') || route().current('clusters.*') || route().current('stores.*') || route().current('vendors.*') || route().current('categories.*') || route().current('sub-categories.*') || route().current('items.*') || route().current('activity-templates.*') || route().current('request-types.*')) {
        openMenus.value.references = true;
    }
    if (route().current('users.*') || route().current('roles.*')) {
        openMenus.value.userManagement = true;
    }
    if (route().current('profile.*') || route().current('settings.*') || route().current('canned-messages.*')) {
        openMenus.value.settings = true;
    }
    if (route().current('reports.*') || route().current('reports.assignee-performance')) {
        openMenus.value.reports = true;
    }
});

onUnmounted(() => {
    destroyPresence();
});

const canSeeAdminTask = computed(() => {
    return hasPermission('attendance.view') ||
           hasPermission('attendance.logs') ||
           hasPermission('schedules.view') ||
           hasPermission('presence.view');
});

const canSeeOperations = computed(() => {
    return hasPermission('tickets.view') ||
           hasPermission('pos_requests.view') ||
           hasPermission('sap_requests.view');
});
const canSeeReferences = computed(() => {
    return hasPermission('companies.view') ||
           hasPermission('clusters.view') ||
           hasPermission('stores.view') ||
           hasPermission('vendors.view') ||
           hasPermission('activity_templates.view') ||
           hasPermission('categories.view') ||
           hasPermission('subcategories.view') ||
           hasPermission('items.view') ||
           hasPermission('request_types.view');
});

const canSeeUserManagement = computed(() => {
    return hasPermission('users.view') || hasPermission('roles.view');
});

const canSeeReports = computed(() => {
    return hasPermission('reports.view') && (hasPermission('reports.store_health') || hasPermission('reports.sla_performance') || hasPermission('reports.assignee_performance'));
});

const canSeeSettings = computed(() => {
    return hasPermission('settings.view') || hasPermission('canned_messages.view');
});
</script>

<template>
    <aside
        :class="[
            'bg-gray-900 text-white transition-all duration-300 ease-in-out flex flex-col h-full shrink-0',
            isCollapsed ? 'w-20' : 'w-72'
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
        <nav class="flex-1 p-4 space-y-1 overflow-y-auto custom-scrollbar">
                <!-- Dashboard Link -->
                <Link
                    :href="route('dashboard')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('dashboard')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <HomeIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate font-medium">Dashboard</span>
                    <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                        Dashboard
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
                >
                    <ClipboardDocumentListIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate font-medium">Project Tracker</span>
                    <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                        Project Tracker
                    </div>
                </Link>

                <!-- Admin Task Section -->
                <div v-if="canSeeAdminTask" class="space-y-1 pt-2">
                    <button
                        @click="toggleMenu('adminTask')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('attendance.*') || route().current('schedules.*') || route().current('presence.*')) && !openMenus.adminTask
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <BriefcaseIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">Admin Task</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.adminTask" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.adminTask" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            Admin Task
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.adminTask" class="pl-10 space-y-1 mt-1 transition-all duration-300">
                        <Link
                            v-if="hasPermission('attendance.view')"
                            :href="route('attendance.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('attendance.index') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>DTR</span>
                        </Link>
                        <Link
                            v-if="hasPermission('attendance.logs')"
                            :href="route('attendance.logs')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('attendance.logs') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Attendance Logs</span>
                        </Link>
                        <Link
                            v-if="hasPermission('schedules.view')"
                            :href="route('schedules.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('schedules.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Scheduling</span>
                        </Link>
                        <Link
                            v-if="hasPermission('presence.view')"
                            :href="route('presence.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('presence.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Presence</span>
                        </Link>
                    </div>
                </div>

                <!-- Operations Section -->
                <div v-if="canSeeOperations" class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('operations')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('tickets.*') || route().current('pos-requests.*') || route().current('sap-requests.*')) && !openMenus.operations
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <QueueListIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">Operations</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.operations" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.operations" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            Operations
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.operations" class="pl-10 space-y-1 mt-1 transition-all duration-300">
                        <Link
                            v-if="hasPermission('tickets.view')"
                            :href="route('tickets.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('tickets.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Tickets</span>
                        </Link>
                        <Link
                            v-if="hasPermission('pos_requests.view')"
                            :href="route('pos-requests.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('pos-requests.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>POS Requests</span>
                        </Link>
                        <Link
                            v-if="hasPermission('sap_requests.view')"
                            :href="route('sap-requests.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('sap-requests.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>SAP Requests</span>
                        </Link>
                    </div>
                </div>

                <!-- References Section -->
                <div v-if="canSeeReferences" class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('references')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('companies.*') || route().current('clusters.*') || route().current('stores.*') || route().current('categories.*') || route().current('request-types.*')) && !openMenus.references
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <BuildingOfficeIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">References</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.references" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.references" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            References
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.references" class="pl-10 space-y-1 mt-1">
                        <Link
                            v-if="hasPermission('companies.view')"
                            :href="route('companies.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('companies.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Companies</span>
                        </Link>
                        <Link
                            v-if="hasPermission('clusters.view')"
                            :href="route('clusters.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('clusters.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Clusters</span>
                        </Link>
                        <Link
                            v-if="hasPermission('stores.view')"
                            :href="route('stores.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('stores.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Stores</span>
                        </Link>
                        <Link
                            v-if="hasPermission('vendors.view')"
                            :href="route('vendors.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('vendors.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Vendors</span>
                        </Link>
                        <Link
                            v-if="hasPermission('activity_templates.view')"
                            :href="route('activity-templates.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('activity-templates.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Activity Templates</span>
                        </Link>
                        <Link
                            v-if="hasPermission('categories.view')"
                            :href="route('categories.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('categories.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Categories</span>
                        </Link>
                        <Link
                            v-if="hasPermission('subcategories.view')"
                            :href="route('sub-categories.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('sub-categories.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Sub-Categories</span>
                        </Link>
                        <Link
                            v-if="hasPermission('items.view')"
                            :href="route('items.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('items.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Items</span>
                        </Link>
                        <Link
                            v-if="hasPermission('request_types.view')"
                            :href="route('request-types.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('request-types.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Request Types</span>
                        </Link>
                    </div>
                </div>

                <!-- Reports Section -->
                <div v-if="canSeeReports" class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('reports')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            route().current('reports.*') && !openMenus.reports
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <PresentationChartLineIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">Reports</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.reports" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.reports" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            Reports
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.reports" class="pl-10 space-y-1 mt-1">
                        <Link
                            v-if="hasPermission('reports.store_health')"
                            :href="route('reports.store-health')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('reports.store-health') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Store Health Report</span>
                        </Link>
                        <Link
                            v-if="hasPermission('reports.sla_performance')"
                            :href="route('reports.sla-performance')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('reports.sla-performance') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>SLA Performance Report</span>
                        </Link>
                        <Link
                            v-if="hasPermission('reports.assignee_performance')"
                            :href="route('reports.assignee-performance')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('reports.assignee-performance') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Assignee Performance</span>
                        </Link>
                    </div>
                </div>

                <!-- User Management Section -->
                <div v-if="canSeeUserManagement" class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('userManagement')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('users.*') || route().current('roles.*')) && !openMenus.userManagement
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <UserGroupIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">User Management</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.userManagement" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.userManagement" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            User Management
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.userManagement" class="pl-10 space-y-1 mt-1">
                        <Link
                            v-if="hasPermission('users.view')"
                            :href="route('users.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('users.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Users</span>
                        </Link>
                        <Link
                            v-if="hasPermission('roles.view')"
                            :href="route('roles.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('roles.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Roles & Permissions</span>
                        </Link>
                    </div>
                </div>

                <!-- Settings Section -->
                <div v-if="canSeeSettings" class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('settings')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            route().current('profile.edit') && !openMenus.settings
                                ? 'bg-gray-800 text-blue-400'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <Cog6ToothIcon :class="['w-5 h-5 flex-shrink-0', isCollapsed ? 'mx-auto' : 'mr-3']" />
                        <span v-if="!isCollapsed" class="flex-1 text-left truncate font-medium">Settings</span>
                        <ChevronDownIcon v-if="!isCollapsed && openMenus.settings" class="w-4 h-4 ml-2" />
                        <ChevronRightIcon v-if="!isCollapsed && !openMenus.settings" class="w-4 h-4 ml-2" />
                        <div v-if="isCollapsed" class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50">
                            Settings
                        </div>
                    </button>

                    <div v-if="!isCollapsed && openMenus.settings" class="pl-10 space-y-1 mt-1">
                        <Link
                            v-if="hasPermission('settings.view')"
                            :href="route('settings.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('settings.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>System Settings</span>
                        </Link>
                        <Link
                            v-if="hasPermission('canned_messages.view')"
                            :href="route('canned-messages.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('canned-messages.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Canned Messages</span>
                        </Link>
                        <Link
                            :href="route('profile.edit')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('profile.edit') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>My Profile</span>
                        </Link>
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
</style>
