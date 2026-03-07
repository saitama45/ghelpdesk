<script setup>
import { ref, computed, onMounted } from 'vue';
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
    TagIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    Cog6ToothIcon,
    QueueListIcon,
    ClockIcon,
} from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission.js';

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

// For Laravel ziggy route helper if not global
const route = window.route;

const openMenus = ref({
    operations: false,
    references: false,
    userManagement: false,
    settings: false,
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
    if (route().current('tickets.*') || route().current('schedules.*') || route().current('attendance.*')) {
        openMenus.value.operations = true;
    }
    if (route().current('companies.*') || route().current('stores.*') || route().current('categories.*') || route().current('sub-categories.*') || route().current('items.*')) {
        openMenus.value.references = true;
    }
    if (route().current('users.*') || route().current('roles.*')) {
        openMenus.value.userManagement = true;
    }
    if (route().current('profile.*')) {
        openMenus.value.settings = true;
    }
});
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
                    <div class="w-10 h-10 bg-white rounded-lg p-1 flex items-center justify-center flex-shrink-0">
                        <img src="/images/company_logo.png" alt="Company Logo" class="w-full h-full object-contain">
                    </div>
                    <span class="text-xl font-semibold">TAS</span>
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

                <!-- Operations Section -->
                <div class="space-y-1 pt-2">
                    <button
                        @click="toggleMenu('operations')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('tickets.*') || route().current('schedules.*')) && !openMenus.operations
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
                            :href="route('attendance.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('attendance.index') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <ClockIcon class="w-4 h-4 mr-2" />
                            <span>DTR</span>
                        </Link>
                        <Link
                            :href="route('attendance.logs')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('attendance.logs') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <ClipboardDocumentListIcon class="w-4 h-4 mr-2" />
                            <span>Attendance Logs</span>
                        </Link>
                        <Link
                            :href="route('tickets.index')"
                            :class="[
                                'flex items-center p-2 rounded-lg text-sm transition-all duration-200',
                                route().current('tickets.*') ? 'text-white font-bold' : 'text-gray-400 hover:text-white'
                            ]"
                        >
                            <span>Tickets</span>
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
                    </div>
                </div>

                <!-- References Section -->
                <div class="space-y-1 pt-1">
                    <button
                        @click="toggleMenu('references')"
                        :class="[
                            'w-full flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                            (route().current('companies.*') || route().current('stores.*') || route().current('categories.*')) && !openMenus.references
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
                    </div>
                </div>

                <!-- User Management Section -->
                <div class="space-y-1 pt-1">
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
                <div class="space-y-1 pt-1">
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
            <div class="p-4 border-t border-gray-800">
                <div class="flex items-center">
                    <div v-if="user.profile_photo" class="w-8 h-8 rounded-full overflow-hidden border border-gray-600">
                        <img :src="'/storage/' + user.profile_photo" class="h-full w-full object-cover" :alt="user.name">
                    </div>
                    <div v-else class="w-8 h-8 bg-gray-600 rounded-full flex items-center justify-center">
                        <span class="text-sm font-medium">
                            {{ user.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </span>
                    </div>
                    <div v-if="!isCollapsed" class="ml-3 flex-1 min-w-0">
                        <p class="text-sm font-medium truncate">{{ user.name || 'User' }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ user.email || 'user@example.com' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
