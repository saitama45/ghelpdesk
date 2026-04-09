<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Sidebar from '@/Components/Sidebar.vue';
import GlobalSearch from '@/Components/GlobalSearch.vue';
import Toast from '@/Components/Toast.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import { usePermission } from '@/Composables/usePermission.js';
import UserStatus from '@/Components/UserStatus.vue';
import { usePresence } from '@/Composables/usePresence.js';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const sidebarOpen = ref(false);
const isSidebarCollapsed = ref(false);
const { init: initPresence, destroy: destroyPresence, currentStatus } = usePresence();

const toggleSidebar = () => {
    if (window.innerWidth < 1024) {
        sidebarOpen.value = false;
    } else {
        isSidebarCollapsed.value = !isSidebarCollapsed.value;
    }
};

const logout = () => {
    router.post(route('logout'));
};

const userMenuOpen = ref(false);
const userMenuRef = ref(null);
const { success, error, warning, info } = useToast();
const { 
    showConfirmModal, 
    confirmState,
    handleConfirm, 
    handleCancel 
} = useConfirm();
const { hasPermission } = usePermission();

const handleClickOutside = (event) => {
    if (userMenuRef.value && !userMenuRef.value.contains(event.target)) {
        userMenuOpen.value = false;
    }
};

const checkFlashMessages = () => {
    const flash = page.props.flash || {};
    if (flash.success) success(flash.success);
    if (flash.error) error(flash.error);
    if (flash.warning) warning(flash.warning);
    if (flash.info) info(flash.info);
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    checkFlashMessages();
    initPresence();
});

watch(() => page.props.flash, () => {
    checkFlashMessages();
}, { deep: true });

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    destroyPresence();
});

watch(() => page.url, () => {
    sidebarOpen.value = false;
});

const isCurrentRoute = (routeName) => {
    return route().current(routeName);
};
</script>

<template>
    <div class="h-screen bg-gray-50 flex overflow-hidden relative">
        <!-- Sidebar -->
        <Sidebar 
            :is-collapsed="isSidebarCollapsed" 
            @toggle="toggleSidebar"
            class="fixed inset-y-0 left-0 z-50 lg:relative lg:translate-x-0 transition-transform duration-300 ease-in-out"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        />

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden w-full">
            <!-- Top Navigation -->
            <div class="sticky top-0 z-40 bg-white shadow-sm border-b border-gray-200 flex-shrink-0">
                <div class="flex items-center justify-between h-16 px-4 sm:px-6 lg:px-8">
                    <!-- Left side: Mobile menu button and Logo -->
                    <div class="flex items-center flex-1 lg:flex-none">
                        <button
                            @click.stop="sidebarOpen = !sidebarOpen"
                            class="lg:hidden p-2 -ml-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"
                        >
                            <span class="sr-only">Open sidebar</span>
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        
                        <div class="lg:hidden flex items-center ml-2">
                            <ApplicationLogo class="h-8 w-8 text-blue-600" />
                            <span class="ml-2 text-lg font-bold text-gray-900 truncate">TAS</span>
                        </div>

                        <!-- Desktop Page Title -->
                        <div class="hidden lg:block">
                            <h1 class="text-lg font-semibold text-gray-900 truncate">
                                <slot name="header"></slot>
                            </h1>
                        </div>
                    </div>

                    <!-- Page Title (Mobile only, centered if possible) -->
                    <div class="lg:hidden flex-1 flex justify-center px-2">
                        <h1 class="text-sm font-semibold text-gray-900 truncate max-w-[150px]">
                            <slot name="header"></slot>
                        </h1>
                    </div>

                    <!-- Right side -->
                    <div class="flex items-center space-x-2 flex-1 justify-end">
                        <GlobalSearch class="hidden sm:block" />
                        
                        <!-- User Menu -->
                        <div class="relative ml-2" ref="userMenuRef">
                            <button
                                @click="userMenuOpen = !userMenuOpen"
                                class="flex items-center space-x-2 p-1 sm:p-2 text-sm rounded-full hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <div class="relative">
                                    <div v-if="user.profile_photo" class="h-8 w-8 rounded-full overflow-hidden border border-gray-200">
                                        <img :src="'/storage/' + user.profile_photo" class="h-full w-full object-cover" :alt="user.name">
                                    </div>
                                    <div v-else class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center">
                                        <span class="text-sm font-medium text-white">{{ user.name?.charAt(0) || 'U' }}</span>
                                    </div>
                                    <UserStatus :status="currentStatus" size="lg" class="absolute -bottom-0.5 -right-0.5 border-2 border-white" />
                                </div>
                                <span class="hidden md:block text-gray-700 font-medium">{{ user.name }}</span>
                                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <!-- Dropdown -->
                            <div v-show="userMenuOpen" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 ring-1 ring-black ring-opacity-5 z-50">
                                <div class="px-4 py-2 text-xs text-gray-500 md:hidden border-b border-gray-100">
                                    Logged in as <span class="font-bold text-gray-900 block truncate">{{ user.name }}</span>
                                </div>
                                <Link :href="route('profile.edit')" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <svg class="w-4 h-4 inline mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    Profile
                                </Link>
                                <hr class="my-1 border-gray-100">
                                <button @click="logout" class="flex items-center w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 font-medium">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                    Sign out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page Content -->
            <main scroll-region class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50">
                <div class="py-4 sm:py-6">
                    <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8">
                        <slot />
                    </div>
                </div>
            </main>
        </div>

        <!-- Mobile sidebar overlay -->
        <div 
            v-show="sidebarOpen" 
            @click="sidebarOpen = false" 
            class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden transition-opacity duration-300"
        ></div>
        
        <!-- Toast Notifications -->
        <Toast />
        
        <!-- Confirm Modal -->
        <ConfirmModal 
            :show="showConfirmModal" 
            :title="confirmState.title" 
            :message="confirmState.message" 
            :confirm-label="confirmState.confirmLabel"
            :cancel-label="confirmState.cancelLabel"
            :variant="confirmState.variant"
            @confirm="handleConfirm" 
            @cancel="handleCancel" 
        />
    </div>
</template>
