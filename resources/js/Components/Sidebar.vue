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
    TagIcon,
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

                <!-- People Management Section -->
                <div v-if="!isCollapsed" class="px-2 py-1 mt-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">People Management</p>
                </div>

                <!-- Categories Link -->
                <Link
                    v-if="hasPermission('categories.view')"
                    :href="route('categories.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('categories.*')
                            ? 'bg-blue-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    ]"
                >
                    <TagIcon
                        :class="[
                            'w-5 h-5 flex-shrink-0',
                            isCollapsed ? 'mx-auto' : 'mr-3'
                        ]"
                    />
                    <span v-if="!isCollapsed" class="truncate">Categories</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Categories
                    </div>
                </Link>

                <!-- Sub-Categories Link -->
                <Link
                    v-if="hasPermission('subcategories.view')"
                    :href="route('sub-categories.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('sub-categories.*')
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
                    <span v-if="!isCollapsed" class="truncate">Sub-Categories</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Sub-Categories
                    </div>
                </Link>

                <!-- Items Link -->
                <Link
                    v-if="hasPermission('items.view')"
                    :href="route('items.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('items.*')
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
                    <span v-if="!isCollapsed" class="truncate">Items</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Items
                    </div>
                </Link>

                <!-- Stores Link -->
                <Link
                    v-if="hasPermission('stores.view')"
                    :href="route('stores.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('stores.*')
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
                    <span v-if="!isCollapsed" class="truncate">Stores</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Stores
                    </div>
                </Link>

                <!-- Scheduling Link -->
                <Link
                    v-if="hasPermission('schedules.view')"
                    :href="route('schedules.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('schedules.*')
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
                    <span v-if="!isCollapsed" class="truncate">Scheduling</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Scheduling
                    </div>
                </Link>

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

                <!-- Tickets Link (Added for relevance) -->
                <Link
                    :href="route('tickets.index')"
                    :class="[
                        'flex items-center p-3 rounded-lg transition-all duration-200 group relative',
                        route().current('tickets.*')
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
                    <span v-if="!isCollapsed" class="truncate">Tickets</span>

                    <!-- Tooltip for collapsed state -->
                    <div
                        v-if="isCollapsed"
                        class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 whitespace-nowrap z-50"
                    >
                        Tickets
                    </div>
                </Link>

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
                    <div v-if="!isCollapsed" class="ml-3 flex-1">
                        <p class="text-sm font-medium truncate">{{ user.name || 'User' }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ user.email || 'user@example.com' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
