<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    HomeIcon,
    Bars3Icon,
    XMarkIcon,
    UserGroupIcon,
    BuildingOffice2Icon,
    HomeModernIcon,
    DocumentTextIcon,
    CurrencyDollarIcon,
    CalendarIcon,
    ClipboardDocumentListIcon,
    WrenchScrewdriverIcon,
    BellIcon,
    ChartBarIcon,
    UsersIcon,
    BuildingOfficeIcon,
} from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission';

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

const toggleSidebar = () => {
    emit('toggle');
};
</script>

<template>
    <div class="flex">
        <!-- Sidebar -->
        <div
            :class="[
                'bg-gray-900 text-white transition-all duration-300 ease-in-out flex flex-col',
                isCollapsed ? 'w-20' : 'w-64'
            ]"
        >
            <!-- Sidebar Header -->
            <div class="flex items-center justify-between p-4 border-b border-gray-800">
                <div v-if="!isCollapsed" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-lg">A</span>
                    </div>
                    <span class="text-xl font-semibold">Amalgated</span>
                </div>

                <button
                    @click="toggleSidebar"
                    class="p-2 rounded-lg hover:bg-gray-800 transition-colors duration-200"
                    :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                >
                    <Bars3Icon v-if="isCollapsed" class="w-5 h-5" />
                    <XMarkIcon v-else class="w-5 h-5" />
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 p-4 space-y-2">
                <!-- Overview Section -->
                <div v-if="!isCollapsed" class="px-2 py-1">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Overview</p>
                </div>

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
                    <span v-if="!isCollapsed" class="truncate">Dashboard</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Dashboard
                    </div>
                </Link>

                <!-- Tenant Monitoring Dashboard Link -->
                <Link
                    :href="route('tenant-monitoring.dashboard.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.dashboard.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <ChartBarIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Tenant Dashboard</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Tenant Dashboard
                    </div>
                </Link>

                <!-- People Management Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">People Management</p>
                </div>

                <!-- Companies Link -->
                <Link
                    v-if="hasPermission('companies.view')"
                    :href="route('companies.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('companies.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <BuildingOfficeIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Companies</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Companies
                    </div>
                </Link>

                <!-- Tenants Link -->
                <Link
                    :href="route('tenant-monitoring.tenants.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.tenants.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <UserGroupIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Tenants</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Tenants
                    </div>
                </Link>

                <!-- Prospects Link -->
                <Link
                    :href="route('tenant-monitoring.prospects.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.prospects.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <UsersIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Prospects</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Prospects
                    </div>
                </Link>

                <!-- Property Management Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Property Management</p>
                </div>

                <!-- Properties Link -->
                <Link
                    :href="route('tenant-monitoring.properties.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.properties.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <BuildingOffice2Icon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Properties</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Properties
                    </div>
                </Link>

                <!-- Units Link -->
                <Link
                    :href="route('tenant-monitoring.units.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.units.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <HomeModernIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Units</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Units
                    </div>
                </Link>

                <!-- Operations Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Operations</p>
                </div>

                <!-- Contracts Link -->
                <Link
                    :href="route('tenant-monitoring.contracts.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.contracts.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <DocumentTextIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Contracts</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Contracts
                    </div>
                </Link>

                <!-- Bills & Payments Link -->
                <Link
                    :href="route('tenant-monitoring.bills.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.bills.*') || route().current('tenant-monitoring.payments.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <CurrencyDollarIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Bills & Payments</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Bills & Payments
                    </div>
                </Link>

                <!-- Maintenance Link -->
                <Link
                    :href="route('tenant-monitoring.maintenance.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.maintenance.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <WrenchScrewdriverIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Maintenance</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Maintenance
                    </div>
                </Link>

                <!-- Communication Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Communication</p>
                </div>

                <!-- Notifications Link -->
                <Link
                    :href="route('tenant-monitoring.notifications.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.notifications.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <div class="relative">
                        <BellIcon
                            :class="[
                                'w-5 h-5 flex-shrink-0',
                                isCollapsed ? 'mx-auto' : 'mr-3'
                            ]"
                        />
                        <!-- Notification badge -->
                        <span class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                    </div>
                    <span v-if="!isCollapsed" class="truncate">Notifications</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Notifications
                    </div>
                </Link>

                <!-- Calendar Link -->
                <Link
                    :href="route('tenant-monitoring.calendar.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.calendar.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <CalendarIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Calendar</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Calendar
                    </div>
                </Link>

                <!-- Analytics Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Analytics</p>
                </div>

                <!-- Reports Link -->
                <Link
                    :href="route('tenant-monitoring.reports.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tenant-monitoring.reports.*')
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
                    <span v-if="!isCollapsed" class="truncate">Reports</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Reports
                    </div>
                </Link>
            </nav>

            <!-- User Section -->
            <div class="p-4 border-t border-gray-800">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-gray-600 rounded-full flex items-center justify-center">
                        <span class="text-sm font-medium">
                            {{ user.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </span>
                    </div>
                    <div v-if="!isCollapsed" class="ml-3 flex-1">
                        <p class="text-sm font-medium truncate">{{ user.name || 'User' }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ user.email || 'user@example.com' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>