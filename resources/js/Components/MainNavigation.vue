<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    currentPage: {
        type: String,
        default: ''
    }
});

// Component state
const isScrolled = ref(false);
const isMobileMenuOpen = ref(false);
const activeDropdown = ref(null); // Track which dropdown is open

// Get current page URL
const page = usePage();
const currentUrl = computed(() => page.url);

// Handle scroll for header effect
const handleScroll = () => {
    isScrolled.value = window.scrollY > 50;
};

// Mobile menu toggle
const toggleMobileMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
};

// Close mobile menu
const closeMobileMenu = () => {
    isMobileMenuOpen.value = false;
};

// Dropdown functionality
let dropdownTimeout = null;

const toggleDropdown = (menuName) => {
    clearTimeout(dropdownTimeout);
    activeDropdown.value = activeDropdown.value === menuName ? null : menuName;
};

const closeDropdown = () => {
    dropdownTimeout = setTimeout(() => {
        activeDropdown.value = null;
    }, 300); // Increased delay to allow moving mouse to dropdown
};

const keepDropdownOpen = () => {
    clearTimeout(dropdownTimeout);
};

const handleMainNavClick = (item) => {
    // Navigate to main page and close dropdown
    closeDropdown();
    if (item.type === 'route') {
        // For navigation items, use Inertia Link instead
        return;
    }
};

// Check if link is active (including nested routes)
const isActive = (path) => {
    if (path.startsWith('#')) {
        // For anchor links, check if we're on home page
        return currentUrl.value === '/' || currentUrl.value === '/home';
    }
    return currentUrl.value.startsWith(path);
};

// Navigation items
const navigationItems = [
    { name: 'Home', href: '/', type: 'route' },
    { name: 'About', href: '/about', type: 'route' },
    {
        name: 'Products',
        href: '/products',
        type: 'route',
        hasDropdown: true,
        dropdownItems: [
            { name: 'Lawn Lot', href: '/products/garden-lot' },
            { name: 'Family Estate', href: '/products/family-estate' }
        ]
    },
    {
        name: 'Services',
        href: '/services',
        type: 'route',
        hasDropdown: true,
        dropdownItems: [
            { name: 'Interment', href: '/services/interment' },
            { name: 'Chapel', href: '/services/chapel' }
        ]
    },
    { name: 'Rates', href: '/rates', type: 'route' },
    { name: 'Contact Us', href: '/contact-us', type: 'route' },
];

// Handle navigation clicks
const handleNavClick = (item) => {
    if (item.type === 'anchor') {
        // If not on home page, navigate to home then scroll
        if (currentUrl.value !== '/' && currentUrl.value !== '/home') {
            window.location.href = '/' + item.href;
        } else {
            // Smooth scroll to section
            const element = document.querySelector(item.href);
            if (element) {
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    }
    closeMobileMenu();
};

// Lifecycle
onMounted(() => {
    window.addEventListener('scroll', handleScroll);
});

onUnmounted(() => {
    window.removeEventListener('scroll', handleScroll);
});
</script>

<template>
    <nav class="bg-white/90 backdrop-blur-sm shadow-sm sticky top-0 z-50 transition-all duration-300" :class="{ 'shadow-lg': isScrolled }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <Link href="/" class="block">
                        <img src="/images/company_logo.png" alt="Loyola Tanauan" class="h-12 hover:opacity-80 transition-opacity duration-200">
                    </Link>
                </div>

                <!-- Desktop Navigation -->
                <div class="hidden md:flex space-x-8">
                    <template v-for="item in navigationItems" :key="item.name">
                        <!-- Regular link -->
                        <Link
                            v-if="!item.hasDropdown"
                            :href="item.href"
                            class="transition-colors duration-200 font-medium"
                            :class="isActive(item.href)
                                ? 'text-blue-600 hover:text-blue-800'
                                : 'text-slate-600 hover:text-slate-900'"
                        >
                            {{ item.name }}
                        </Link>

                        <!-- Dropdown menu -->
                        <div
                            v-else
                            class="relative"
                            @mouseenter="toggleDropdown(item.name)"
                            @mouseleave="closeDropdown"
                        >
                            <Link
                                :href="item.href"
                                class="transition-colors duration-200 font-medium flex items-center gap-1"
                                :class="isActive(item.href)
                                    ? 'text-blue-600 hover:text-blue-800'
                                    : 'text-slate-600 hover:text-slate-900'"
                                @click="handleMainNavClick(item)"
                            >
                                {{ item.name }}
                                <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': activeDropdown === item.name }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </Link>

                            <!-- Dropdown content -->
                            <div
                                v-show="activeDropdown === item.name"
                                class="absolute top-full left-0 -mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden z-50"
                                @mouseenter="keepDropdownOpen"
                                @mouseleave="closeDropdown"
                            >
                                <template v-for="dropdownItem in item.dropdownItems" :key="dropdownItem.name">
                                    <Link
                                        :href="dropdownItem.href"
                                        class="block px-4 py-3 text-slate-700 hover:bg-gray-50 hover:text-slate-900 transition-colors duration-200"
                                        @click="closeDropdown"
                                    >
                                        {{ dropdownItem.name }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button
                        @click="toggleMobileMenu"
                        class="text-slate-600 hover:text-slate-900 p-2 rounded-md hover:bg-gray-100 transition-colors"
                    >
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path v-if="!isMobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile Navigation Menu -->
            <div v-if="isMobileMenuOpen" class="md:hidden border-t border-gray-200">
                <div class="px-2 pt-2 pb-3 space-y-1">
                    <template v-for="item in navigationItems" :key="item.name">
                        <!-- Regular link -->
                        <Link
                            v-if="!item.hasDropdown && item.type === 'route'"
                            :href="item.href"
                            class="block px-3 py-2 rounded-md text-base font-medium transition-colors"
                            :class="isActive(item.href)
                                ? 'bg-blue-50 text-blue-600 border-l-4 border-blue-600'
                                : 'text-slate-600 hover:text-slate-900 hover:bg-gray-50'"
                            @click="closeMobileMenu"
                        >
                            {{ item.name }}
                        </Link>

                        <!-- Dropdown menu -->
                        <div v-else-if="item.hasDropdown" class="space-y-1">
                            <button
                                @click="toggleDropdown(item.name)"
                                class="w-full text-left px-3 py-2 rounded-md text-base font-medium transition-colors flex items-center justify-between"
                                :class="isActive(item.href)
                                    ? 'bg-blue-50 text-blue-600 border-l-4 border-blue-600'
                                    : 'text-slate-600 hover:text-slate-900 hover:bg-gray-50'"
                            >
                                <span>{{ item.name }}</span>
                                <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': activeDropdown === item.name }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>

                            <!-- Mobile dropdown items -->
                            <div v-show="activeDropdown === item.name" class="pl-4 space-y-1">
                                <Link
                                    v-for="dropdownItem in item.dropdownItems"
                                    :key="dropdownItem.name"
                                    :href="dropdownItem.href"
                                    class="block px-3 py-2 rounded-md text-sm text-slate-600 hover:text-slate-900 hover:bg-gray-50 transition-colors"
                                    @click="closeMobileMenu"
                                >
                                    {{ dropdownItem.name }}
                                </Link>
                            </div>
                        </div>

                        <button
                            v-else
                            @click="handleNavClick(item)"
                            class="w-full text-left px-3 py-2 rounded-md text-base font-medium transition-colors"
                            :class="isActive(item.href)
                                ? 'bg-blue-50 text-blue-600 border-l-4 border-blue-600'
                                : 'text-slate-600 hover:text-slate-900 hover:bg-gray-50'"
                        >
                            {{ item.name }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </nav>
</template>